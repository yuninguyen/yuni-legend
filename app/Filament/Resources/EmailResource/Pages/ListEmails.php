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
            // 🚀 NEW: CUSTOM TXT IMPORT ACTION (Spec v1)
            EmailResource::getImportAction(),


            //Nút Sync to Google Sheet
            $this->getSyncToSheetAction('syncEmails', 'Emails'),

            //Nút Sync FROM Google Sheet
            $this->getSyncFromSheetAction('importEmails', 'Emails'),

            // Nút Create
            \Filament\Actions\CreateAction::make()
            ->visible(fn() => auth()->user()?->isAdmin()),
        ];
    }
}
