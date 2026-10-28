<#
.SYNOPSIS
    Lint the PowerShell templates with PSScriptAnalyzer. Used by CI; also runnable locally.
#>
[CmdletBinding()]
param()

$ErrorActionPreference = 'Stop'
$root = Join-Path -Path $PSScriptRoot -ChildPath '..'

if ($null -eq (Get-Module -ListAvailable -Name PSScriptAnalyzer)) {
    Install-Module -Name PSScriptAnalyzer -Scope CurrentUser -Force -Repository PSGallery
}

$results = Invoke-ScriptAnalyzer -Path (Join-Path -Path $root -ChildPath 'templates') -Recurse -Severity Warning, Error
if ($results) {
    $results | Format-Table -AutoSize | Out-String | Write-Output
    exit 1
}
Write-Output 'PSScriptAnalyzer: no findings'
