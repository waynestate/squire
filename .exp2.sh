#!/usr/bin/env bash
# Local experiment: list-only push to oci: transport, read the digest back.
set -eux
B="buildah --storage-driver=vfs"
$B from docker://alpine:3.20 > /tmp/ctr.txt
ctr="$(cat /tmp/ctr.txt)"
$B commit "$ctr" testimg:v1 > /dev/null
$B manifest create mylist
$B manifest add mylist testimg:v1
$B manifest push --all=false mylist oci:/tmp/list-only
{
    echo INDEXJSON
    cat /tmp/list-only/index.json
    echo
    echo BLOBS
    ls -la /tmp/list-only/blobs/sha256/
} > /w/exp-result2.txt
