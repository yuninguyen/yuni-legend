<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\GoogleSyncService;

class ImportTrackersFromSheet extends Command
{
    protected $signature = 'sync:trackers-from-sheet {spreadsheetId?}';
    protected $description = 'Import rebate trackers from Google Sheet (RKT_Tracker, RMN_Tracker, etc.)';

    public function handle(GoogleSyncService $syncService)
    {
        $this->info('Starting Tracker synchronization from Google Sheets...');
        
        $spreadsheetId = $this->argument('spreadsheetId');
        $result = $syncService->importTrackers($spreadsheetId);

        $this->table(
            ['Created', 'Updated', 'Failed', 'Skipped'],
            [[$result['created'], $result['updated'], $result['failed'], $result['skipped']]]
        );

        $this->info('Tracker synchronization completed.');
    }
}
