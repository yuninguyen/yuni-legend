<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class SyncAllToGoogleSheet extends Command
{

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    // Tên lệnh bạn sẽ gõ trên terminal
    protected $signature = 'sync:all {spreadsheetId?}';

    /**
     * The console command description.
     *
     * @var string
     */

    // Mô tả lệnh
    protected $description = 'Refresh all data from the database to Google Sheets';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        if ($spreadsheetId = $this->argument('spreadsheetId')) {
            config(['services.google.spreadsheet_id' => $spreadsheetId]);
            $this->comment("Target Spreadsheet ID: {$spreadsheetId}");
        }

        $syncService = app(\App\Services\GoogleSyncService::class);

        $this->info('🚀 Initiating the full-scale synchronization process...');

        $this->comment(' Syncing to Google Sheets: Emails...');
        $syncService->syncEmails();
        $this->info('   ✔ Emails done!');

        $this->comment(' Syncing to Google Sheets: Payout Methods...');
        $syncService->syncPayoutMethods();
        $this->info('   ✔ Payout Methods done!');

        $this->comment(' Syncing to Google Sheets: Payout Logs...');
        $syncService->syncPayoutLogs();
        $this->info('   ✔ Payout Logs done!');

        $this->info('⏳ Processing accounts (this may take time due to multiple tabs)...');
        $syncService->syncAccounts();
        $this->info("   ✔ Accounts done!");

        $this->info('⏳ Processing Rebate Trackers...');
        $syncService->syncTrackers();
        $this->info("   ✔ Trackers done!");

        $this->newLine();
        $this->info('✅ ALL DATA HAS BEEN UPDATED ON GOOGLE SHEETS!');
    }


}

