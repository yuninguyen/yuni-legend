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
            $this->getSyncToSheetAction('syncAccounts', 'Accounts'),

            // Nút Import từ Google Sheet
            \Filament\Actions\Action::make('syncFromSheet')
                ->label(__('system.notifications.sync_from_google_sheet'))
                ->color('warning')
                ->icon('heroicon-o-cloud-arrow-down')
                ->requiresConfirmation()
                ->action(function (\App\Services\GoogleSyncService $syncService) {
                    try {
                        $result = $syncService->importAccounts();
                        
                        Notification::make()
                            ->title(__('system.notifications.sync_success'))
                            ->success()
                            ->body(__('system.notifications.sync_from_success_msg', [
                                'updated' => $result['updated'],
                                'created' => $result['created'],
                                'failed' => $result['failed']
                            ]))
                            ->send();
                    } catch (\Exception $e) {
                        Notification::make()
                            ->title('Synchronization Failed')
                            ->danger()
                            ->body($e->getMessage())
                            ->send();
                    }
                })
                ->visible(fn() => auth()->user()?->isAdmin()),

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
