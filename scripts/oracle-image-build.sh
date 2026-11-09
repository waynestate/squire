#!/usr/bin/env bash
# oracle-image-build.sh: builds Squire's Oracle-ready images.
#
# Runs anywhere docker is on PATH (Linux CI, a macOS box, or Windows through
# Git Bash): it drives docker (with buildx for cross builds) and a throwaway
# Composer project for the Sail base. Usage:
#
#   scripts/oracle-image-build.sh --flavour sail|parity --php 8.4 [--platform linux/amd64]
#
# The image names here (squire/oracle-sail, squire/oracle-parity) are the local
# build names. CI publishes the same images to the project's container registry
# on a tag; see scripts/oracle-image-publish.sh and MAINTAINING.md.

set -euo pipefail

# Under Git Bash on Windows, stop the shell from rewriting path-looking
# arguments (it would turn -w /app into a Windows path). Host paths that
# docker must mount are converted explicitly with host_path below.
export MSYS_NO_PATHCONV=1

flavour=""
php_line="8.4"
platform=""

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
        echo "oracle-image-build: unknown argument: $1" >&2
        exit 2
        ;;
    esac
done

case "$flavour" in
sail | parity) ;;
*)
    echo "oracle-image-build: --flavour must be sail or parity" >&2
    exit 2
    ;;
esac

case "$php_line" in
8.3 | 8.4 | 8.5) ;;
*)
    echo "oracle-image-build: --php must be 8.3, 8.4 or 8.5" >&2
    exit 2
    ;;
esac

command -v docker >/dev/null 2>&1 || {
    echo "oracle-image-build: docker is not on PATH" >&2
    exit 1
}

# A host path in the form the local docker CLI can mount or use as a build
# context: a Windows path under Git Bash, the POSIX path everywhere else.
host_path() {
    if command -v cygpath >/dev/null 2>&1; then
        cygpath -w "$1"
    else
        printf '%s\n' "$1"
    fi
}

# Resolve the repository root from this script's location so the build works
# no matter where it is invoked from.
repo_root="$(cd "$(dirname "$0")/.." && pwd)"

# The pinned base for this PHP line (SQUIRE-30): one row per line in images/oracle/php-images.txt.
php_image="$(awk -v line="$php_line" '$1 == line { print $2 }' "$repo_root/images/oracle/php-images.txt")"
if [ -z "$php_image" ]; then
    echo "no pinned base for PHP ${php_line} in images/oracle/php-images.txt; add a row (see MAINTAINING.md)" >&2
    exit 2
fi
platform_args=()
if [ -n "$platform" ]; then
    platform_args=(--platform "$platform")
fi

case "$flavour" in
sail)
    # Build Sail's own runtime first, from a throwaway Composer project, then
    # layer the Oracle install on top of it.
    workdir="$(mktemp -d)"
    trap 'rm -rf "$workdir"' EXIT
    echo "[squire] ==> step 1: fetch laravel/sail into a throwaway project"
    docker run --rm -v "$(host_path "$workdir"):/app" -w /app \
        composer:2.11.1@sha256:8de2184c6de224f4689e8f80ed5f5edaf0030b6b8b7839a22a2bcb6722ac87d0 require laravel/sail --no-interaction --quiet
    echo "[squire] ==> step 2: build the Sail runtime (vendor/laravel/sail/runtimes/${php_line})"
    docker build "${platform_args[@]}" -t sail-base \
        "$(host_path "${workdir}/vendor/laravel/sail/runtimes/${php_line}")" \
        --build-arg WWWGROUP=1000
    image="squire/oracle-sail:${php_line}"
    echo "[squire] ==> step 3: layer the Oracle install into ${image}"
    docker build "${platform_args[@]}" -f images/oracle/sail.Dockerfile \
        --build-arg SAIL_BASE=sail-base -t "$image" "$(host_path "$repo_root")"
    ;;
parity)
    image="squire/oracle-parity:${php_line}"
    echo "[squire] ==> step 1: build ${image} from the official PHP image"
    docker build "${platform_args[@]}" -f images/oracle/parity.Dockerfile \
        --build-arg PHP_IMAGE="${php_image}" -t "$image" "$(host_path "$repo_root")"
    ;;
esac

size="$(docker image inspect --format '{{.Size}}' "$image")"
echo "[squire] built ${image} (${size} bytes)"
