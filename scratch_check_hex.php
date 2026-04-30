<?php

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Services\GoogleSheetService;

$spreadsheetId = '1R2DCjZJ3jJ7ixH66_ny2nrvq2uOxVz46ccalOpon-z0';

$client = new \Google\Client();
$client->setAuthConfig(config('services.google.service_account_path'));
$client->addScope(\Google\Service\Sheets::SPREADSHEETS);
$serviceSheets = new \Google\Service\Sheets($client);

$spreadsheet = $serviceSheets->spreadsheets->get($spreadsheetId);
$sheets = $spreadsheet->getSheets();

foreach ($sheets as $sheet) {
    $title = $sheet->getProperties()->getTitle();
    echo $title . ": " . bin2hex($title) . "\n";
}
