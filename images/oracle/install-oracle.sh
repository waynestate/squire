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

# Exact-match test for the soname Oracle's libraries need. Ubuntu 24.04 ships
# libaio.so.1t64, whose name contains "libaio.so.1" and would fool a plain
# substring grep.
libaio_resolved() {
    ldconfig -p | awk '{print $1}' | grep -qx 'libaio.so.1'
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
# The zips wrap their contents in a version-specific folder (for example
# instantclient_21_7); flatten it so the client always lives at $ic_home.
# The arm64 19.32 zips both carry the same zip-signing files (META-INF/), so
# the SDK is unpacked with -n (never overwrite): Basic Light's files always
# win, and unzip never stops to ask, which a build with no terminal reads as
# "None" and fails. Nothing reads META-INF, so it goes.
mkdir -p "$ic_home"
unzip -q "${workdir}/${basic_zip}" -d "$ic_home"
unzip -q -n "${workdir}/${sdk_zip}" -d "$ic_home"
rm -rf "${ic_home:?}/META-INF"

wrapper=""
wrapper_count=0
for candidate in "$ic_home"/instantclient_*; do
    [ -d "$candidate" ] || continue
    wrapper="$candidate"
    wrapper_count=$((wrapper_count + 1))
done
[ "$wrapper_count" -le 1 ] || die "expected at most one instantclient_* wrapper in ${ic_home}, found ${wrapper_count}"
if [ -n "$wrapper" ]; then
    tar -C "$wrapper" -cf - . > "${workdir}/flatten.tar"
    tar -C "$ic_home" -xf "${workdir}/flatten.tar"
    rm -f "${workdir}/flatten.tar"
    rm -rf "$wrapper"
fi

# The layout oci8 expects for a zip install: libraries at the top level,
# headers under sdk/include.
[ -f "${ic_home:?}/libclntsh.so" ] || die "libclntsh.so is missing from ${ic_home} after unpacking"
[ -f "${ic_home:?}/sdk/include/oci.h" ] || die "sdk/include/oci.h is missing from ${ic_home} after unpacking"

# --- library resolution, the way Citadel's PHP images do it ------------------------------
echo "$ic_home" > /etc/ld.so.conf.d/oracle-instantclient.conf
ldconfig

# Oracle's libraries carry a DT_NEEDED of the pre-t64 soname libaio.so.1.
# Debian ships libaio.so.1 itself. Ubuntu 24.04 renamed it to libaio.so.1t64,
# so when the old name is missing, add it back as a symlink next to the new
# one; the dynamic loader follows the symlink without a cache entry, and the
# php -m check at the end of this script proves the whole chain resolves.
if ! libaio_resolved; then
    libaio_source="$(ldconfig -p | awk '$1 ~ /^libaio\.so\.1/ {print $NF; exit}')"
    [ -n "$libaio_source" ] || die "libaio.so.1 does not resolve and no other libaio library was found"
    ln -sf "$libaio_source" "$(dirname "$libaio_source")/libaio.so.1"
    [ -e "$(dirname "$libaio_source")/libaio.so.1" ] || die "could not create the libaio.so.1 compatibility symlink"
fi

# --- compile oci8 ------------------------------------------------------------------------
printf '%s\n' "instantclient,${ic_home}" | pecl install oci8-3.4.1

# pecl's build finishes with "ldconfig -n <modules dir>", which only touches
# that directory. Rebuild the full cache so the image's final state does not
# depend on how that ldconfig variant merges with the existing cache.
ldconfig

# Enable the extension where PHP actually scans for ini files. Debian and
# Ubuntu use /etc/php/<line>/<sapi>/conf.d; the official PHP image uses
# /usr/local/etc/php/conf.d. Ask PHP rather than guessing.
conf_dir="$(php --ini | sed -n 's/^[Ss]can for additional \.ini files in:[[:space:]]*//p')"
[ -n "$conf_dir" ] || die "could not determine the PHP ini scan directory from php --ini"
echo "extension=oci8.so" > "${conf_dir}/zz-oci8.ini"

# The SDK is only needed to compile; drop it and the zips.
rm -rf "${ic_home:?}/sdk"
rm -rf "$workdir"

# --- prove it ----------------------------------------------------------------------------------
# Loading oci8 through PHP exercises the whole chain: ini scan, dlopen of
# oci8.so, its link to libclntsh, and libclntsh's need for libaio.so.1.
php -m | grep -qx oci8 || die "the oci8 extension is not loaded"
oci_version="$(php -r 'echo oci_client_version();')"
[ -n "$oci_version" ] || die "oci_client_version() printed nothing"
echo "install-oracle: oci8 loaded (${oci_version}), client ${ic_version} for ${TARGETARCH}"
