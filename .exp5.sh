#!/usr/bin/env bash
# Local experiment: composer require via chroot, no subshell, verify synchronously.
set -eux
B="buildah --storage-driver=vfs"
work="$(mktemp -d)"
ctr="$($B from docker://composer:2.10.3)"
mp="$($B mount "$ctr")"
mkdir -p "$mp/app"
cp /etc/resolv.conf "$mp/etc/resolv.conf"
rc=0
chroot "$mp" /bin/sh -c 'cd /app && exec /usr/bin/composer require laravel/sail --no-interaction --quiet' || rc=$?
echo "chroot rc=$rc"
ls "$mp/app"
test -f "$mp/app/vendor/laravel/sail/runtimes/8.4/Dockerfile"
cp -r "$mp/app/vendor" "$work/vendor"
buildah umount "$mp" >/dev/null
buildah rm "$ctr" >/dev/null
ls "$work/vendor/laravel/sail/runtimes/8.4" > /w/exp-result5.txt
echo DONE >> /w/exp-result5.txt
