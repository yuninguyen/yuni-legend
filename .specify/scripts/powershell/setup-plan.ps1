. "$PSScriptRoot/common.ps1"
$root = Get-RepoRoot
$dir = Get-ActiveFeatureDirectory
$template = Join-Path $root '.specify/templates/plan-template.md'
$target = Join-Path $dir 'plan.md'
Copy-Item $template $target -Force
Write-Output $target
