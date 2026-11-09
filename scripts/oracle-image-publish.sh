#!/usr/bin/env bash
# oracle-image-publish.sh: builds Squire's Oracle-ready images and publishes them.
#
# For GitLab CI only (a Linux runner without a Docker daemon): it drives buildah
# with --storage-driver=vfs --isolation=chroot, builds both platforms
# (linux/amd64 and linux/arm64) from the same Dockerfiles and build args that
# scripts/oracle-image-build.sh uses, assembles a manifest list, and pushes it to
# the group's images project as <registry image>-oracle-<flavour>:<php>-<tag>
# (immutable) and <registry image>-oracle-<flavour>:<php> (floating). Usage:
#
#   scripts/oracle-image-publish.sh --flavour sail|parity --php 8.4 \
#       --tag 0.4.1 --registry-image <path> [--dry-run]
#
# In CI, --registry-image is the images project path inside the destination
# registry; before pushing, the script logs in to that registry's host with
# $NOUGAT_REGISTRY_USER and $NOUGAT_REGISTRY_TOKEN (the token over stdin, never
# on the command line).
# --dry-run builds everything and prints what would be pushed, without logging
# in or pushing anything, so it needs no registry variables.

set -euo pipefail

flavour=""
php_line="8.4"
tag=""
registry_image=""
dry_run=0

usage() {
    printf 'usage: %s --flavour sail|parity --php 8.3|8.4|8.5 --tag <squire tag> --registry-image <path> [--dry-run]\n' "$0" >&2
}

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
    --tag)
        tag="${2:?--tag needs a value}"
        shift 2
        ;;
    --registry-image)
        registry_image="${2:?--registry-image needs a value}"
        shift 2
        ;;
    --dry-run)
        dry_run=1
        shift
        ;;
    -h | --help)
        usage
        exit 0
        ;;
    *)
        echo "oracle-image-publish: unknown argument: $1" >&2
        usage
        exit 2
        ;;
    esac
done

case "$flavour" in
sail | parity) ;;
*)
    echo "oracle-image-publish: --flavour must be sail or parity" >&2
    exit 2
    ;;
esac

case "$php_line" in
8.3 | 8.4 | 8.5) ;;
*)
    echo "oracle-image-publish: --php must be 8.3, 8.4 or 8.5" >&2
    exit 2
    ;;
esac

if [ -z "$registry_image" ]; then
    echo "oracle-image-publish: --registry-image is required" >&2
    exit 2
fi

if [ "$dry_run" -eq 0 ] && [ -z "$tag" ]; then
    echo "oracle-image-publish: --tag is required (unless --dry-run)" >&2
    exit 2
fi

command -v buildah >/dev/null 2>&1 || {
    echo "oracle-image-publish: buildah is not on PATH" >&2
    exit 1
}

repo_root="$(cd "$(dirname "$0")/.." && pwd)"

# The pinned base for this PHP line: one row per line in images/oracle/php-images.txt.
php_image="$(awk -v line="$php_line" '$1 == line { print $2 }' "$repo_root/images/oracle/php-images.txt")"
if [ -z "$php_image" ]; then
    echo "no pinned base for PHP ${php_line} in images/oracle/php-images.txt; add a row (see MAINTAINING.md)" >&2
    exit 2
fi
workdir="$(mktemp -d)"

cleanup() {
    rm -rf "$workdir"
}
trap cleanup EXIT

# The CI runner has no overlay support under its container runtime, so every
# buildah call uses the vfs storage driver and chroot isolation (no namespaces,
# no Docker daemon, no qemu for the native platform).
STORAGE=(--storage-driver=vfs)

bud() {
    buildah "${STORAGE[@]}" bud --isolation=chroot "$@"
}

# The runner's own architecture builds without emulation, so it goes first: a
# runner that cannot run the other one still proves its own half before the
# script stops.
case "$(uname -m)" in
x86_64 | amd64) native=amd64 ;;
aarch64 | arm64) native=arm64 ;;
*) native="" ;;
esac
if [ "$native" = arm64 ]; then
    build_order=(arm64 amd64)
else
    build_order=(amd64 arm64)
fi

# Run one build, keeping its log for diagnosis. A build for an architecture the
# runner cannot run dies at its first RUN step with "exec format error" (buildah
# prints it in lower case); say so plainly. The job cannot fix that itself: it
# is not privileged, so it cannot register qemu binfmt handlers, and only the
# runner host can.
run_bud() {
    local log="$1" arch="$2"
    shift 2
    if ! bud "$@" >"$log" 2>&1; then
        if grep -qi "exec format error" "$log"; then
            echo "oracle-image-publish: the linux/${arch} build failed: this ${native:-$(uname -m)} runner cannot run linux/${arch} binaries (no qemu binfmt emulation on its host). The runner's owner can register it on the host (qemu-user-static); see MAINTAINING.md, Publishing the Oracle images." >&2
        else
            echo "oracle-image-publish: the build failed; the last lines of its log follow." >&2
            tail -n 20 "$log" >&2
        fi
        exit 1
    fi
}

if [ "$flavour" = sail ]; then
    # Sail's runtime Dockerfiles ship inside the laravel/sail package, so fetch
    # the package into a throwaway working container the way
    # scripts/oracle-image-build.sh does with a throwaway Composer project.
    # The image is named in full without a transport prefix: buildah's
    # docker:// transport refuses a reference with both a tag and a digest
    # ("invalid reference format"), while a plain name keeps the tag readable
    # and pulls by the digest.
    echo "[squire] ==> fetching laravel/sail into a throwaway container"
    sail_ctr="$(buildah "${STORAGE[@]}" from docker.io/library/composer:2.11.1@sha256:8de2184c6de224f4689e8f80ed5f5edaf0030b6b8b7839a22a2bcb6722ac87d0)"
    buildah "${STORAGE[@]}" run --isolation=chroot --workingdir /app "$sail_ctr" \
        composer require laravel/sail --no-interaction --quiet
    sail_mp="$(buildah "${STORAGE[@]}" mount "$sail_ctr")"
    mkdir -p "$workdir/sail-runtime"
    cp -r "$sail_mp/app/vendor/laravel/sail" "$workdir/sail-runtime/sail"
    buildah "${STORAGE[@]}" umount "$sail_ctr"
    buildah "${STORAGE[@]}" rm "$sail_ctr"
fi

for arch in "${build_order[@]}"; do
    platform="linux/${arch}"
    echo "[squire] ==> building ${flavour} ${php_line} for ${platform}"
    case "$flavour" in
    sail)
        runtime_dir="$workdir/sail-runtime/sail/runtimes/${php_line}"
        base_image="squire/sail-base:${php_line}-${arch}"
        run_bud "$workdir/sail-base-${arch}.log" "$arch" \
            --platform "$platform" \
            --build-arg WWWGROUP=1000 \
            -f "$runtime_dir/Dockerfile" \
            -t "$base_image" \
            "$runtime_dir"
        run_bud "$workdir/oracle-sail-${arch}.log" "$arch" \
            --platform "$platform" \
            --build-arg "SAIL_BASE=${base_image}" \
            -f "$repo_root/images/oracle/sail.Dockerfile" \
            -t "squire/oracle-sail:${php_line}-${arch}" \
            "$repo_root"
        ;;
    parity)
        run_bud "$workdir/oracle-parity-${arch}.log" "$arch" \
            --platform "$platform" \
            --build-arg "PHP_IMAGE=${php_image}" \
            -f "$repo_root/images/oracle/parity.Dockerfile" \
            -t "squire/oracle-parity:${php_line}-${arch}" \
            "$repo_root"
        ;;
    esac
done

echo "[squire] ==> assembling the manifest list"
list_name="squire-oracle-${flavour}-${php_line}"
buildah "${STORAGE[@]}" manifest create "$list_name"
for arch in amd64 arm64; do
    buildah "${STORAGE[@]}" manifest add "$list_name" "squire/oracle-${flavour}:${php_line}-${arch}"
done

# The digest of the list as pushed: push the list alone (no image blobs) to a
# local OCI directory and read it back from index.json. buildah manifest push
# does not print or write the digest for a manifest list.
mkdir -p "$workdir/list"
buildah "${STORAGE[@]}" manifest push --all=false "$list_name" "oci:${workdir}/list" >/dev/null
list_digest="$(sed -n 's/.*"digest":"\([^"]*\)".*/\1/p' "$workdir/list/index.json")"
if [ -z "$list_digest" ]; then
    echo "oracle-image-publish: could not read the list digest from index.json" >&2
    exit 1
fi

if [ "$dry_run" -eq 1 ]; then
    echo "[squire] dry run: built ${flavour} ${php_line} for linux/amd64 and linux/arm64 and assembled the list."
    echo "Would push ${registry_image}-oracle-${flavour}:${php_line}-<tag> (and :${php_line}) digest: ${list_digest}"
    exit 0
fi

if [ -z "${NOUGAT_REGISTRY_USER:-}" ] || [ -z "${NOUGAT_REGISTRY_TOKEN:-}" ]; then
    echo "oracle-image-publish: NOUGAT_REGISTRY_USER and NOUGAT_REGISTRY_TOKEN must be set to push" >&2
    exit 1
fi
registry_host="${registry_image%%/*}"
authfile="$workdir/auth.json"
echo "[squire] ==> logging in to ${registry_host}"
printf '%s\n' "$NOUGAT_REGISTRY_TOKEN" | buildah login --authfile "$authfile" -u "$NOUGAT_REGISTRY_USER" --password-stdin "$registry_host"
push_args=(--authfile "$authfile")

for reference_suffix in "${php_line}-${tag}" "${php_line}"; do
    reference="${registry_image}-oracle-${flavour}:${reference_suffix}"
    echo "[squire] ==> pushing ${reference}"
    buildah "${STORAGE[@]}" manifest push --all "${push_args[@]}" "$list_name" "docker://${reference}"
    echo "Pushed ${reference} digest: ${list_digest}"
done
