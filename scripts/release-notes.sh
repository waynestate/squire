#!/bin/sh
# Cut one tag's section out of CHANGELOG.md and print it as release notes.
#
# Usage: sh scripts/release-notes.sh TAG [CHANGELOG]
#
# Both platforms cut the notes with this one script: the GitHub release workflow
# (.github/workflows/release.yml) and the GitLab gitlab-release job (.gitlab-ci.yml)
# call it with their tag. The squire keeps one sword for both fields; he does not
# sharpen two.
set -eu

if [ "$#" -lt 1 ] || [ -z "$1" ]; then
    echo "usage: release-notes.sh TAG [CHANGELOG]" >&2
    exit 2
fi

TAG="${1#v}"
CHANGELOG="${2:-CHANGELOG.md}"

if [ ! -f "$CHANGELOG" ]; then
    echo "release-notes: $CHANGELOG not found" >&2
    exit 1
fi

SECTION="$(mktemp)"
NOTES="$(mktemp)"
trap 'rm -f "$SECTION" "$NOTES"' EXIT

awk -v tag="$TAG" '
    index($0, "## " tag " (") == 1 { found = 1; in_section = 1; next }
    in_section && index($0, "## ") == 1 { exit }
    in_section { print }
    END { if (!found) exit 1 }
' "$CHANGELOG" > "$SECTION" || {
    echo "$CHANGELOG has no section for this tag: expected a heading like \"## $TAG (\"." >&2
    exit 1
}

# Drop the leading and trailing blank lines; the inner ones stay.
awk '
    { lines[++n] = $0 }
    END {
        first = 1
        last = n
        while (first <= last && lines[first] == "") first++
        while (last >= first && lines[last] == "") last--
        for (i = first; i <= last; i++) print lines[i]
    }
' "$SECTION" > "$NOTES"

if [ ! -s "$NOTES" ]; then
    echo "The $CHANGELOG section for $TAG is empty; add release notes before tagging." >&2
    exit 1
fi

cat "$NOTES"
