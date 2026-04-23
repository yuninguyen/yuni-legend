$path = "c:\laragon\www\yuni-legend\app\Filament\Resources\PayoutLogResource.php"
$lines = Get-Content $path
for ($i = 0; $i -lt $lines.Count; $i++) {
    if ($lines[$i] -match "CONCAT\(account_id") {
        $lines[$i] = $lines[$i] -replace "CONCAT\(account_id, '_', COALESCE\(gc_brand, 'none'\), '_', COALESCE\(parent_id, id\)\) as group_key", "match (\Illuminate\Support\Facades\DB::getDriverName()) { 'sqlite' => ""account_id || '_' || COALESCE(gc_brand, 'none') || '_' || COALESCE(parent_id, id) as group_key"", default => ""CONCAT(account_id, '_' , COALESCE(gc_brand, 'none'), '_', COALESCE(parent_id, id)) as group_key"" }"
        break
    }
}
$lines | Set-Content $path
