Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

function Get-RepoRoot {
    $root = git rev-parse --show-toplevel 2>$null
    if (-not $root) { throw 'Not inside a git repository.' }
    return $root.Trim()
}

function Get-ActiveFeatureDirectory {
    $root = Get-RepoRoot
    $state = Join-Path $root '.specify/feature.json'
    if (-not (Test-Path $state)) { throw 'No active Spec-Kit feature. Run speckit-specify first.' }
    $json = Get-Content $state -Raw | ConvertFrom-Json
    if (-not $json.feature_directory) { throw 'feature_directory missing from .specify/feature.json' }
    return (Join-Path $root $json.feature_directory)
}
