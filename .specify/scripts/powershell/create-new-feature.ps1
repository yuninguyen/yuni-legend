param([Parameter(Mandatory=$true)][string]$ShortName)
. "$PSScriptRoot/common.ps1"
$root = Get-RepoRoot
$specs = Join-Path $root 'specs'
New-Item -ItemType Directory -Force -Path $specs | Out-Null
$nums = Get-ChildItem $specs -Directory -ErrorAction SilentlyContinue | ForEach-Object { if ($_.Name -match '^(\d{3})-') { [int]$Matches[1] } }
$next = if ($nums) { (($nums | Measure-Object -Maximum).Maximum + 1) } else { 1 }
$name = ('{0:D3}-{1}' -f $next, ($ShortName -replace '[^a-zA-Z0-9-]','-').ToLower())
$rel = "specs/$name"
$dir = Join-Path $root $rel
New-Item -ItemType Directory -Force -Path $dir | Out-Null
Copy-Item (Join-Path $root '.specify/templates/spec-template.md') (Join-Path $dir 'spec.md') -Force
@{ feature_directory = $rel } | ConvertTo-Json | Set-Content (Join-Path $root '.specify/feature.json') -Encoding utf8
Write-Output $rel
