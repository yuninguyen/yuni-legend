param([Parameter(Mandatory=$true)][string]$Name)
. "$PSScriptRoot/common.ps1"
$root = Get-RepoRoot
$map = @{ 'spec-template'='spec-template.md'; 'plan-template'='plan-template.md'; 'tasks-template'='tasks-template.md'; 'checklist-template'='checklist-template.md'; 'constitution-template'='constitution-template.md' }
if (-not $map.ContainsKey($Name)) { throw "Unknown template: $Name" }
$path = Join-Path $root ".specify/templates/$($map[$Name])"
if (-not (Test-Path $path)) { throw "Template not found: $path" }
Write-Output $path
