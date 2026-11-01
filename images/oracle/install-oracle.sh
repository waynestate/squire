#!/bin/sh
# install-oracle.sh: Oracle Instant Client and oci8 for Squire's local images.
#
# Runs as root inside an image build (Linux only). It downloads the client
# from Oracle at build time, refuses it unless the SHA-256 matches the pinned
# values below, compiles oci8 3.4.1 against it, and proves the result loads.
# Nothing here talks to an Oracle server: the checks load the extension and
# print versions only. A squire checks the seal on the bottle before pouring.
#
# The Dockerfiles set ORACLE_HOME and LD_LIBRARY_PATH; this script writes no
# environment file of its own.

set -eu

die() {
    echo "install-oracle: $1" >&2
    exit 1
}

# --- the client, pinned per architecture --------------------------------------
# amd64 takes the 21 line (21.7 resolved on 2026-10-31); arm64 has no 21.x, so
# it takes 19.32. Basic Light, not Basic.
case "${TARGETARCH:-}" in
amd64)
    ic_version=21.7.0.0.0
    ic_url_dir=217000
    ic_arch=x64
    basic_sha256=8a745ad7f4290ff8f7bd1d9436f6afdf07644e390b5d6acc3dc50978687795cb
    sdk_sha256=af85a772d23078ae24ea9fb025fa56707daedce7b154ea02ae7ee665583aeee2
    ;;
arm64)
    ic_version=19.32.0.0.0
    ic_url_dir=1932000
    ic_arch=arm64
    basic_sha256=2c7c657b1e77b95a969e70f9af816b5e88f40047249c820936aa87a126d8e4f4
    sdk_sha256=be3b37817943102cb051fad80f5dc821821cf1616ffd0983c2ded81621c760d3
    ;;
*)
    die "TARGETARCH must be amd64 or arm64, got '${TARGETARCH:-}'"
    ;;
esac

ic_home=/opt/oracle/instantclient
workdir=/tmp/oracle-instantclient
basic_zip="instantclient-basiclite-linux.${ic_arch}-${ic_version}dbru.zip"
sdk_zip="instantclient-sdk-linux.${ic_arch}-${ic_version}dbru.zip"
ic_base_url="https://download.oracle.com/otn_software/linux/instantclient/${ic_url_dir}"

# --- build tools -----------------------------------------------------------------
export DEBIAN_FRONTEND=noninteractive
apt-get update
apt-get install -y --no-install-recommends build-essential ca-certificates curl unzip

# Ubuntu 24.04 calls the library libaio1t64; Debian calls it libaio1.
# shellcheck disable=SC1091
. /etc/os-release
case "$ID" in
ubuntu)
    libaio_package=libaio1t64
    ;;
debian)
    libaio_package=libaio1
    ;;
*)
    die "unsupported operating system '$ID'"
    ;;
esac
apt-get install -y --no-install-recommends "$libaio_package"

# phpize and pecl build the extension; the official PHP image already has
# them, the Sail runtime needs the -dev package and pecl.
php_line="$(php -r 'echo PHP_MAJOR_VERSION . "." . PHP_MINOR_VERSION;')"
if ! command -v phpize >/dev/null 2>&1; then
    apt-get install -y --no-install-recommends "php${php_line}-dev" || die "could not install php${php_line}-dev"
fi
if ! command -v pecl >/dev/null 2>&1; then
    apt-get install -y --no-install-recommends php-pear || die "could not install pecl"
fi

# --- download and checksum ----------------------------------------------------------
mkdir -p "$workdir"
curl --fail --silent --show-error -o "${workdir}/${basic_zip}" "${ic_base_url}/${basic_zip}" \
    || die "could not download ${basic_zip}"
curl --fail --silent --show-error -o "${workdir}/${sdk_zip}" "${ic_base_url}/${sdk_zip}" \
    || die "could not download ${sdk_zip}"

actual_basic="$(sha256sum "${workdir}/${basic_zip}" | cut -d' ' -f1)"
[ "$actual_basic" = "$basic_sha256" ] || die "SHA-256 mismatch for ${basic_zip}: got ${actual_basic}, want ${basic_sha256}"
actual_sdk="$(sha256sum "${workdir}/${sdk_zip}" | cut -d' ' -f1)"
[ "$actual_sdk" = "$sdk_sha256" ] || die "SHA-256 mismatch for ${sdk_zip}: got ${actual_sdk}, want ${sdk_sha256}"

# --- unpack ----------------------------------------------------------------------------
mkdir -p "$ic_home"
unzip -q "${workdir}/${basic_zip}" -d "$ic_home"
unzip -q "${workdir}/${sdk_zip}" -d "$ic_home"

# --- library resolution, the way Citadel's PHP images do it ------------------------------
echo "$ic_home" > /etc/ld.so.conf.d/oracle-instantclient.conf
ldconfig

# Prove libaio.so.1 resolves; add a symlink only if it does not.
if ! ldconfig -p | grep -q 'libaio\.so\.1'; then
    libaio_source="$(ldconfig -p | grep 'libaio\.so' | awk '{print $NF}' | head -n 1)"
    [ -n "$libaio_source" ] || die "libaio.so.1 does not resolve and no other libaio library was found"
    ln -sf "$libaio_source" "$(dirname "$libaio_source")/libaio.so.1"
    ldconfig
fi
ldconfig -p | grep -q 'libaio\.so\.1' || die "libaio.so.1 still does not resolve after installing ${libaio_package}"

# --- compile oci8 ------------------------------------------------------------------------
printf '%s\n' "instantclient,${ic_home}" | pecl install oci8-3.4.1
echo "extension=oci8.so" > "$(php -r 'echo ini_get("extension_dir");')/oci8.ini"

# The SDK is only needed to compile; drop it and the zips.
rm -rf "${ic_home:?}/bin"
rm -f "$ic_home"/*.h
rm -rf "$workdir"

# --- prove it ----------------------------------------------------------------------------------
php -m | grep -qx oci8 || die "the oci8 extension is not loaded"
oci_version="$(php -r 'echo oci_client_version();')"
[ -n "$oci_version" ] || die "oci_client_version() printed nothing"
ldconfig -p | grep -q 'libaio\.so\.1' || die "libaio.so.1 does not resolve"
echo "install-oracle: oci8 loaded (${oci_version}), client ${ic_version} for ${TARGETARCH}"
