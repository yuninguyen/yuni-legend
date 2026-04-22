<?php

namespace App\Filament\Resources\AccountResource\Pages;

use App\Filament\Resources\AccountResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;
use Filament\Tables;
use App\Models\Account;
use Filament\Notifications\Notification;

class ListAccounts extends ListRecords
{
    protected static string $resource = AccountResource::class;

    /*
    public function getMaxContentWidth(): string
    {
        return 'full'; // Ép bảng tràn hết chiều ngang màn hình
    }
    */
    use \App\Filament\Traits\HasSyncToSheetAction;

    protected function getHeaderActions(): array
    {
        return [
            // Nút Import
            \Filament\Actions\ImportAction::make()
                ->importer(\App\Filament\Imports\AccountImporter::class)
                ->label('Import All Data')
                ->color('success')
                ->icon('heroicon-o-arrow-up-tray')
                // 🟢 THÊM DÒNG NÀY: Chỉ hiển thị nếu là Admin
                ->visible(fn() => auth()->user()?->isAdmin()),


            $this->getSyncToSheetAction('syncAccounts', 'Accounts'),

            // Nút Create
            \Filament\Actions\CreateAction::make(),
        ];
    }

    /*
    protected function getTableBulkActions(): array
    {
        return [
            Tables\Actions\BulkActionGroup::make([
                // Khi bạn chọn (tick) các dòng, nút này sẽ chỉ xuất những dòng đó
                Tables\Actions\ExportBulkAction::make()
                    ->exporter(\App\Filament\Exports\AccountExporter::class)
                    ->label('Export Selected Data'),

                Tables\Actions\DeleteBulkAction::make(),
            ]),
        ];
    } */
}
