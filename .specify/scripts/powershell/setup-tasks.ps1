. "$PSScriptRoot/common.ps1"
$root = Get-RepoRoot
$dir = Get-ActiveFeatureDirectory
$template = Join-Path $root '.specify/templates/tasks-template.md'
$target = Join-Path $dir 'tasks.md'
Copy-Item $template $target -Force
Write-Output $target
