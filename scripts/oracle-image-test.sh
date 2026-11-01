#!/usr/bin/env bash
# oracle-image-test.sh: builds Squire's Oracle-ready images and asserts their
# contract.
#
# Usage:
#   scripts/oracle-image-test.sh --flavour sail|parity|all [--php 8.4] [--platform linux/amd64]
#
# Prints one PASS line per assertion per flavour; any FAIL makes the exit
# code non-zero. A non-native platform this host cannot build (usually
# linux/arm64 without emulation) is reported as SKIP instead of failing the
# run, because the host, not the recipe, is the limit.

set -euo pipefail

# Under Git Bash on Windows, stop the shell from rewriting path-looking
# arguments (it would turn -w /app into a Windows path).
export MSYS_NO_PATHCONV=1

flavour="all"
php_line="8.4"
platform="linux/amd64"

while [ $# -gt 0 ]; do
    case "$1" in
    --flavour)
        flavour="${2:?--flavour needs a value}"
        shift 2
        ;;
    --php)
        php_line="${2:?--php needs a value}"
        shift 2
        ;;
    --platform)
        platform="${2:?--platform needs a value}"
        shift 2
        ;;
    *)
        echo "oracle-image-test: unknown argument: $1" >&2
        exit 2
        ;;
    esac
done

case "$flavour" in
sail | parity | all) ;;
*)
    echo "oracle-image-test: --flavour must be sail, parity or all" >&2
    exit 2
    ;;
esac

case "$php_line" in
8.3 | 8.4 | 8.5) ;;
*)
    echo "oracle-image-test: --php must be 8.3, 8.4 or 8.5" >&2
    exit 2
    ;;
esac

case "$platform" in
linux/amd64 | linux/arm64) ;;
*)
    echo "oracle-image-test: --platform must be linux/amd64 or linux/arm64" >&2
    exit 2
    ;;
esac

command -v docker >/dev/null 2>&1 || {
    echo "oracle-image-test: docker is not on PATH" >&2
    exit 1
}

repo_root="$(cd "$(dirname "$0")/.." && pwd)"
logdir="$(mktemp -d)"
trap 'rm -rf "$logdir"' EXIT

failures=0

# assert <flavour> <image> <description> <shell command run inside the image>
assert() {
    local flavour_name="$1" image="$2" description="$3" cmd="$4"
    if docker run --rm --entrypoint=sh "$image" -c "$cmd" >/dev/null 2>&1; then
        echo "PASS ${flavour_name}: ${description}"
    else
        echo "FAIL ${flavour_name}: ${description}"
        failures=$((failures + 1))
    fi
}

test_flavour() {
    local flavour_name="$1"
    local image
    case "$flavour_name" in
    sail)
        image="squire/oracle-sail:${php_line}"
        ;;
    parity)
        image="squire/oracle-parity:${php_line}"
        ;;
    esac

    echo "[squire] ==> building ${image} for ${platform}"
    local build_log="${logdir}/build-${flavour_name}.log"
    if ! bash "${repo_root}/scripts/oracle-image-build.sh" --flavour "$flavour_name" \
        --php "$php_line" --platform "$platform" > "$build_log" 2>&1; then
        if [ "$platform" = "linux/amd64" ]; then
            echo "FAIL ${flavour_name}: the build failed; the last lines of the build log:" >&2
            tail -n 8 "$build_log" >&2
            failures=$((failures + 1))
            return 0
        fi
        echo "SKIP ${flavour_name}: this host cannot build ${platform}; the last lines of the build log:"
        tail -n 8 "$build_log"
        return 0
    fi

    # The label names the client per architecture; compare the version PHP
    # reports against the entry for the architecture that was built.
    local label want got
    label="$(docker inspect --format '{{index .Config.Labels "org.opencontainers.image.description"}}' "$image")"
    case "$platform" in
    linux/amd64)
        want="$(printf '%s\n' "$label" | sed -n 's/.*linux\/amd64: \([0-9][0-9.]*\).*/\1/p')"
        ;;
    linux/arm64)
        want="$(printf '%s\n' "$label" | sed -n 's/.*linux\/arm64: \([0-9][0-9.]*\).*/\1/p')"
        ;;
    esac
    got="$(docker run --rm --entrypoint=sh "$image" -c 'php -r "echo oci_client_version();"')"
    if [ -n "$want" ] && [ "$got" = "$want" ]; then
        echo "PASS ${flavour_name}: client version ${got} matches the image label"
    else
        echo "FAIL ${flavour_name}: client version '${got}' does not match the image label ('${want}')"
        failures=$((failures + 1))
    fi

    # These single-quoted commands must stay unexpanded here; they run inside
    # the image, where the variables belong.
    assert "$flavour_name" "$image" "oci8 is loaded" 'php -m | grep -qx oci8'
    assert "$flavour_name" "$image" "libaio.so.1 resolves (ldconfig)" 'ldconfig -p | grep -q "libaio.so.1"'
    # shellcheck disable=SC2016
    assert "$flavour_name" "$image" "no zip left under /opt" '[ "$(find /opt -name "*.zip" | wc -l)" -eq 0 ]'
    # shellcheck disable=SC2016
    assert "$flavour_name" "$image" "ORACLE_HOME is set" '[ -n "${ORACLE_HOME:-}" ]'
    # shellcheck disable=SC2016
    assert "$flavour_name" "$image" "TNS_ADMIN is unset" '[ -z "${TNS_ADMIN:-}" ]'
    # shellcheck disable=SC2016
    assert "$flavour_name" "$image" "NLS_LANG is unset" '[ -z "${NLS_LANG:-}" ]'
}

case "$flavour" in
all)
    test_flavour sail
    test_flavour parity
    ;;
*)
    test_flavour "$flavour"
    ;;
esac

if [ "$failures" -gt 0 ]; then
    echo "oracle-image-test: ${failures} assertion(s) failed" >&2
    exit 1
fi
echo "oracle-image-test: all assertions passed"
