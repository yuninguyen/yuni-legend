<?php

namespace App\Filament\Traits;

use App\Services\GoogleSyncService;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Log;

trait HasSyncToSheetAction
{
    /**
     * Tạo Action chuẩn để đồng bộ dữ liệu lên Google Sheet
     * $serviceMethod: Tên hàm trong GoogleSyncService (vd: 'syncEmails', 'syncTrackers'...)
     * $modelLabel: Tên Model tiếng Việt/Anh để hiển thị thông báo (vd: 'Emails', 'Trackers'...)
     */
    /**
     * Tạo Action chuẩn để đồng bộ dữ liệu lên Google Sheet
     */
    protected function getSyncToSheetAction(string $serviceMethod, string $modelLabel): Action
    {
        return Action::make('sync_to_google_sheet')
            ->label(__('system.notifications.sync_to_google_sheet'))
            ->icon('heroicon-o-arrow-path')
            ->color('success')
            ->requiresConfirmation()
            ->modalHeading(__('system.notifications.sync_to_google_sheet'))
            ->modalDescription(__('system.notifications.sync_confirm_msg'))
            ->modalSubmitActionLabel(__('system.account_claim.submit')) // Dùng chung nút confirm
            // 🟢 CHỈ HIỆN CHO ADMIN
            ->visible(fn() => auth()->user()?->isAdmin())
            ->action(function () use ($serviceMethod, $modelLabel) {
                try {
                    // 1. Lấy dữ liệu thực tế đang hiển thị (quan trọng: tôn trọng Filter)
                    $query = $this->getFilteredTableQuery();
                    
                    // 2. Gọi Service xử lý
                    $syncService = app(GoogleSyncService::class);
                    // Giữ nguyên xi như cũ: Không truyền ID/SheetName vào đây để nó dùng mặc định của Service
                    $result = $syncService->$serviceMethod($query);

                    // 3. Thông báo thành công
                    Notification::make()
                        ->title(__('system.notifications.sync_success'))
                        ->body(__('system.notifications.sync_success_msg', ['count' => $result['updated'] + $result['appended']]))
                        ->success()
                        ->send();

                } catch (\Exception $e) {
                    Log::error("Manual Sync Error [{$modelLabel}]: " . $e->getMessage());

                    Notification::make()
                        ->title(__('system.notifications.sync_error'))
                        ->body($e->getMessage())
                        ->danger()
                        ->persistent()
                        ->send();
                }
            });
    }

    /**
     * Tạo Action để đồng bộ dữ liệu TỪ Google Sheet về Database
     */
    protected function getSyncFromSheetAction(string $serviceMethod, string $modelLabel): Action
    {
        return Action::make('sync_from_google_sheet')
            ->label(__('system.notifications.sync_from_google_sheet'))
            ->icon('heroicon-o-cloud-arrow-down')
            ->color('warning')
            ->requiresConfirmation()
            ->modalHeading(__('system.notifications.sync_from_google_sheet'))
            ->modalDescription(__('system.notifications.sync_from_confirm_msg'))
            ->modalSubmitActionLabel(__('system.account_claim.submit')) // Dùng chung nút confirm
            ->visible(fn() => auth()->user()?->isAdmin())
            ->action(function () use ($serviceMethod, $modelLabel) {
                try {
                    $spreadsheetId = config('services.google.import_spreadsheet_id') ?: config('services.google.spreadsheet_id');
                    $sheetName = $modelLabel === 'Emails' ? 'Email_Import' : 'Sheet1';

                    $syncService = app(GoogleSyncService::class);
                    $result = $syncService->$serviceMethod($sheetName, $spreadsheetId);

                    Notification::make()
                        ->title(__('system.notifications.sync_success'))
                        ->body(__('system.notifications.sync_from_success_msg', [
                            'updated' => $result['updated'],
                            'created' => $result['created'] ?? 0,
                            'failed' => $result['failed']
                        ]))
                        ->success()
                        ->send();

                } catch (\Exception $e) {
                    Log::error("Manual Sync From Error [{$modelLabel}]: " . $e->getMessage());

                    Notification::make()
                        ->title(__('system.notifications.sync_error'))
                        ->body($e->getMessage())
                        ->danger()
                        ->persistent()
                        ->send();
                }
            });
    }

    /**
     * Tạo Action để đồng bộ Tracker TỪ Google Sheet về Database (Tất cả platform)
     */
    protected function getImportTrackersFromSheetAction(): Action
    {
        return Action::make('sync_from_google_sheet_trackers')
            ->label(__('system.notifications.sync_from_google_sheet'))
            ->icon('heroicon-o-cloud-arrow-down')
            ->color('warning')
            ->requiresConfirmation()
            ->modalHeading(__('system.notifications.sync_from_google_sheet'))
            ->modalDescription(__('system.notifications.sync_from_confirm_msg'))
            ->modalSubmitActionLabel(__('system.account_claim.submit'))
            ->visible(fn() => auth()->user()?->isAdmin())
            ->action(function () {
                try {
                    $syncService = app(GoogleSyncService::class);
                    $result = $syncService->importTrackers();

                    Notification::make()
                        ->title(__('system.notifications.sync_success'))
                        ->body(__('system.notifications.sync_from_success_msg', [
                            'updated' => $result['updated'],
                            'created' => $result['created'],
                            'failed' => $result['failed']
                        ]))
                        ->success()
                        ->send();

                } catch (\Exception $e) {
                    Log::error("Manual Import Trackers Error: " . $e->getMessage());

                    Notification::make()
                        ->title(__('system.notifications.sync_error'))
                        ->body($e->getMessage())
                        ->danger()
                        ->persistent()
                        ->send();
                }
            });
    }
}
