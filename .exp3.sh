#!/usr/bin/env bash
# Local experiment: composer require laravel/sail through buildah from + mount + chroot.
set -eux
B="buildah --storage-driver=vfs"
work="$(mktemp -d)"
trap 'rm -rf "$work"' EXIT
ctr="$($B from docker://composer:2.10.3)"
mp="$($B mount "$ctr")"
mkdir -p "$mp/app"
cp /etc/resolv.conf "$mp/etc/resolv.conf"
(cd "$mp/app" && chroot "$mp" /usr/bin/composer require laravel/sail --no-interaction --quiet)
ls "$mp/app/vendor/laravel/sail/runtimes"
cp -r "$mp/app/vendor" "$work/vendor"
buildah umount "$mp" >/dev/null
buildah rm "$ctr" >/dev/null
ls "$work/vendor/laravel/sail/runtimes/8.4" > /w/exp-result3.txt
echo DONE >> /w/exp-result3.txt
