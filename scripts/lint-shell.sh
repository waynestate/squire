#!/usr/bin/env bash
# Lint the shell templates with the pinned ShellCheck image. Runs anywhere Docker runs (Linux, macOS).
# Windows: scripts/lint-shell.ps1.
set -euo pipefail

cd "$(dirname "$0")/.."
MSYS_NO_PATHCONV=1 docker run --rm -v "$PWD:/mnt" -w /mnt koalaman/shellcheck-alpine:v0.11.0 \
    sh -c "find templates scripts -name '*.sh' -o -path 'templates/common/.squire/fallback/*' -type f | xargs shellcheck --external-sources --severity=style"
echo "shellcheck: no findings"
