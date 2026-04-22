<?php

namespace App\Filament\Resources\RakutenResource\Pages;

use App\Filament\Resources\RakutenResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Enums\MaxWidth;

class ListRakutens extends ListRecords
{
    use \App\Filament\Traits\HasSyncToSheetAction;

    protected static string $resource = RakutenResource::class;

    // Thêm hàm này để bảng giãn rộng ra toàn màn hình
    public function getMaxWidth(): MaxWidth
    {
        return MaxWidth::Full;
    }

    protected function getHeaderActions(): array
    {
        return [

            // Nút Import
            \Filament\Actions\ImportAction::make()
                ->importer(\App\Filament\Imports\AccountImporter::class)
                ->label('Import All Data')
                ->color('success')
                ->icon('heroicon-o-arrow-up-tray')
                // 🟢 CHỈ HIỆN CHO ADMIN
                ->visible(fn() => auth()->user()?->isAdmin()),


            $this->getSyncToSheetAction('syncAccounts', 'Accounts'),

            \Filament\Actions\CreateAction::make(),
        ];
    }
}
