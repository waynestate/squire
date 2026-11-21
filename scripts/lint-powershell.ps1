<#
.SYNOPSIS
    Lint the PowerShell scripts with PSScriptAnalyzer. Used by CI; also runnable locally.
#>
[CmdletBinding()]
param()

$ErrorActionPreference = 'Stop'
$root = Join-Path -Path $PSScriptRoot -ChildPath '..'

if ($null -eq (Get-Module -ListAvailable -Name PSScriptAnalyzer)) {
    Install-Module -Name PSScriptAnalyzer -Scope CurrentUser -Force -Repository PSGallery
}

# Every folder that holds .ps1 files. A folder with none is an error, so the job can never pass by
# analyzing nothing (it once pointed at templates/, which holds no .ps1 files).
$paths = @('scripts')
$results = @()
foreach ($path in $paths) {
    $full = Join-Path -Path $root -ChildPath $path
    $files = @(Get-ChildItem -Path $full -Filter '*.ps1' -Recurse -File -ErrorAction SilentlyContinue)
    if ($files.Count -eq 0) {
        Write-Error "PSScriptAnalyzer: no .ps1 files under '$path'; fix the path list in scripts/lint-powershell.ps1"
        exit 1
    }
    foreach ($file in $files) {
        $results += @(Invoke-ScriptAnalyzer -Path $file.FullName -Severity Warning, Error)
    }
}
if ($results) {
    $results | Format-Table -AutoSize | Out-String | Write-Output
    exit 1
}
Write-Output 'PSScriptAnalyzer: no findings'
