. "$PSScriptRoot/common.ps1"
$root = Get-RepoRoot
$required = @('AGENTS.md','BUILD.md','.specify',' .claude/skills')
foreach ($item in $required) {
    $clean = $item.Trim()
    if (-not (Test-Path (Join-Path $root $clean))) { throw "Missing prerequisite: $clean" }
}
Write-Output 'Spec-Kit prerequisites: OK'
