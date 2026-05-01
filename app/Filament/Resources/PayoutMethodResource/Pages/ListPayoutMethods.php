<?php

namespace App\Filament\Resources\PayoutMethodResource\Pages;

use App\Filament\Resources\PayoutMethodResource;
use App\Services\GoogleSheetService;
use App\Services\GoogleSyncService;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;

class ListPayoutMethods extends ListRecords
{
    protected static string $resource = PayoutMethodResource::class;

    // Thêm dòng này để định danh rõ ràng, tránh trùng với PayoutLog
    protected static ?string $slug = 'payout-methods';
    use \App\Filament\Traits\HasSyncToSheetAction;

    protected function getHeaderActions(): array
    {
        return [
            $this->getSyncToSheetAction('syncPayoutMethods', 'Payout Methods'),
            $this->getImportPayoutMethodsFromSheetAction(),
            Actions\CreateAction::make(),
        ];
    }
}
