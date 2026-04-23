<?php
$filePath = 'c:/laragon/www/yuni-legend/app/Filament/Resources/PayoutLogResource.php';
$lines = file($filePath);
if (count($lines) >= 59) {
    echo "Processing file...\n";
    foreach ($lines as $i => $line) {
        if (strpos($line, 'CONCAT(account_id') !== false) {
            echo "Found target on line " . ($i + 1) . ". Replacing...\n";
            $lines[$i] = str_replace(
                "CONCAT(account_id, '_', COALESCE(gc_brand, 'none'), '_', COALESCE(parent_id, id)) as group_key",
                'match (\Illuminate\Support\Facades\DB::getDriverName()) { \'sqlite\' => "account_id || \'_\' || COALESCE(gc_brand, \'none\') || \'_\' || COALESCE(parent_id, id) as group_key", default => "CONCAT(account_id, \'_\' , COALESCE(gc_brand, \'none\'), \'_\', COALESCE(parent_id, id)) as group_key" }',
                $line
            );
            echo "New line: " . $lines[$i];
        }
    }
    file_put_contents($filePath, implode('', $lines));
    echo "Success!\n";
} else {
    echo "File too short: " . count($lines) . " lines.\n";
}
