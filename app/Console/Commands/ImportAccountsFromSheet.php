<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\GoogleSyncService;

class ImportAccountsFromSheet extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'sync:accounts-from-sheet {spreadsheetId?}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Import accounts from Google Sheets (RKT, RMN, JH, AJ, TCB, Price sheets)';

    /**
     * Execute the console command.
     */
    public function handle(GoogleSyncService $syncService)
    {
        $spreadsheetId = $this->argument('spreadsheetId');
        
        $this->info('🚀 Starting Account Import from Google Sheets...');
        if ($spreadsheetId) {
            $this->comment("Using Spreadsheet ID: {$spreadsheetId}");
        }

        $result = $syncService->importAccounts($spreadsheetId);

        $this->info('✅ Import completed!');
        $this->table(
            ['Created', 'Updated', 'Failed', 'Skipped'],
            [
                [$result['created'], $result['updated'], $result['failed'], $result['skipped']]
            ]
        );
    }
}
