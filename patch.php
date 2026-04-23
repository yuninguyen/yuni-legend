<?php
$filePath = 'c:/laragon/www/yuni-legend/app/Filament/Resources/PayoutLogResource.php';
$content = file_get_contents($filePath);
$target = 'CONCAT(account_id, \'_\', COALESCE(gc_brand, \'none\'), \'_\', COALESCE(parent_id, id)) as group_key';
$replacement = 'match (\Illuminate\Support\Facades\DB::getDriverName()) { \'sqlite\' => "account_id || \'_\' || COALESCE(gc_brand, \'none\') || \'_\' || COALESCE(parent_id, id) as group_key", default => "CONCAT(account_id, \'_\' , COALESCE(gc_brand, \'none\'), \'_\', COALESCE(parent_id, id)) as group_key" }';

// Try direct string replace first
if (strpos($content, $target) !== false) {
    echo "Found target. Replacing...\n";
    $newContent = str_replace($target, $replacement, $content);
    file_put_contents($filePath, $newContent);
    echo "Success!\n";
} else {
    echo "Target NOT found. Trying parts...\n";
    if (strpos($content, 'CONCAT(account_id') !== false) {
        echo "Found partial target 'CONCAT(account_id'.\n";
    } else {
        echo "Literal search failed completely.\n";
    }
}
