#!/usr/bin/env bash
# Debug: why did composer require produce nothing?
set -ux
B="buildah --storage-driver=vfs"
ctr="$($B from docker://composer:2.10.3)"
mp="$($B mount "$ctr")"
mkdir -p "$mp/app"
cp /etc/resolv.conf "$mp/etc/resolv.conf"
(cd "$mp/app" && chroot "$mp" /usr/bin/composer require laravel/sail --no-interaction) > /w/exp4-composer.log 2>&1
rc=$?
echo "composer rc=$rc" >> /w/exp4-composer.log
{ echo APPDIR; ls -la "$mp/app"; echo RESOLV; cat "$mp/etc/resolv.conf"; } >> /w/exp4-composer.log
buildah umount "$mp" >/dev/null
buildah rm "$ctr" >/dev/null
