<#
.SYNOPSIS
    Lint the shell templates with the pinned ShellCheck image. Needs Docker; no bash needed.
#>
[CmdletBinding()]
param()

$ErrorActionPreference = 'Stop'
Set-Location -LiteralPath (Join-Path -Path $PSScriptRoot -ChildPath '..')

& docker run --rm -v "${PWD}:/mnt" -w /mnt koalaman/shellcheck-alpine:v0.11.0 `
    sh -c "find templates scripts -name '*.sh' -o -path 'templates/common/.squire/fallback/*' -type f | xargs shellcheck --external-sources --severity=style"
if ($LASTEXITCODE -ne 0) {
    exit 1
}
Write-Output 'shellcheck: no findings'
