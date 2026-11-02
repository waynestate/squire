#!/usr/bin/env bash
# Local experiment: how to obtain the manifest list digest buildah pushes.
set -eux
B="buildah --storage-driver=vfs"
$B from docker://alpine:3.20 > /tmp/ctr.txt
ctr="$(cat /tmp/ctr.txt)"
$B commit "$ctr" testimg:v1 > /dev/null
$B manifest create mylist
$B manifest add mylist testimg:v1
$B manifest inspect mylist > /tmp/inspect.json
$B manifest push --all mylist oci:/tmp/oci-dest > /dev/null 2>&1
{
    echo INDEXJSON
    cat /tmp/oci-dest/index.json
    echo
    echo EXACTMATCHES
    for b in /tmp/oci-dest/blobs/sha256/*; do
        if cmp -s "$b" /tmp/inspect.json; then
            echo "  exact: $(basename "$b")"
        fi
    done
    echo SHAOFINSPECT
    sha256sum /tmp/inspect.json
    echo SIZES
    wc -c /tmp/inspect.json
    for b in /tmp/oci-dest/blobs/sha256/*; do
        wc -c "$b"
    done
} > /w/exp-result.txt
