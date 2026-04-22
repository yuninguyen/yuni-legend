<?php

namespace App\Filament\Resources\EmailResource\Pages;

use App\Filament\Resources\EmailResource;
use App\Filament\Exports\EmailExporter;
use App\Filament\Imports\EmailImporter;
use Illuminate\Support\HtmlString;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;
use Filament\Tables;
use Filament\Notifications\Notification;
use App\Services\GoogleSheetService;

class ListEmails extends ListRecords
{
    use \App\Filament\Traits\HasSyncToSheetAction;

    protected static string $resource = EmailResource::class;

    public function getMaxContentWidth(): string
    {
        return 'full'; // Ép bảng tràn hết chiều ngang màn hình
    }

    protected function getHeaderActions(): array
    {
        return [
            // Nút Import
            \Filament\Actions\ImportAction::make()
                ->importer(\App\Filament\Imports\EmailImporter::class)
                ->label('Import All Data')
                ->color('success')
                ->icon('heroicon-o-arrow-up-tray')
                ->modalHeading('Upload Email Database')
                ->modalDescription(fn() => new HtmlString('
                    <div class="text-sm space-y-2">
                        <p class="font-medium text-gray-700">Supported formats: .csv, .xlsx, and .txt</p>
                        <div>
                            <a href="/templates/email_template.csv" class="text-primary-600 underline hover:text-primary-500">
                                Download CSV Template
                            </a>
                        </div>
                        <div>
                            <a href="/templates/email_template.txt" class="text-primary-600 underline hover:text-primary-500">
                                Download TXT Template
                            </a>
                        </div>
                    </div>
                '))
                // 🟢 CHỈ HIỆN CHO ADMIN
                ->visible(fn() => auth()->user()?->isAdmin()),


            //Nút Sync to Google Sheet
            $this->getSyncToSheetAction('syncEmails', 'Emails'),

            // Nút Create
            \Filament\Actions\CreateAction::make()
            ->visible(fn() => auth()->user()?->isAdmin()),
        ];
    }
}
