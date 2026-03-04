<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use App\Services\GoogleSheetService;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SyncGoogleSheetJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * @param int $recordId ID của bản ghi cần sync
     * @param string $modelClass Tên Class của Model (vd: PayoutLog::class)
     */
    public function __construct(
        protected $recordId,
        protected $modelClass,
        protected string $action = 'upsert'
    ) {}
    
    public function handle(GoogleSheetService $service): void
    {
        if ($this->action === 'delete') {
        $service->deleteRowsByIds([(string)$this->recordId], $targetTabs);
        return;
    }
        try {
            // 1. Tìm bản ghi cụ thể
            $record = method_exists($this->modelClass, 'withTrashed') 
                ? $this->modelClass::withTrashed()->find($this->recordId) 
                : $this->getRecordWithRelations();

            if (!$record && $this->action !== 'delete') return;

            $headers = [];
            $formattedRow = [];
            // ĐÃ SỬA WARNING #1: Thống nhất dùng mảng $targetTabs
            $targetTabs = []; 

            // 2. MAPPING: Xác định Resource và Tab dựa trên Model Class
            switch ($this->modelClass) {
                case \App\Models\Email::class:
                    // EmailResource sử dụng Trait HasEmailSchema
                    $resource = \App\Filament\Resources\EmailResource::class;
                    $targetTabs[] = 'Emails';
                    $headers = $resource::$emailHeaders;
                    $formattedRow = $resource::formatEmailForSheet($record);
                    break;

                case \App\Models\Account::class:
                    $platform = $record->platform ?: 'General';
                    $targetTabs[] = ucfirst($platform) . '_Accounts';
                    $headers = \App\Filament\Resources\AccountResource::$accountHeaders; // Hoặc từ Trait
                    $formattedRow = \App\Filament\Resources\AccountResource::formatAccountForSheet($record);
                    break;

                case \App\Models\RebateTracker::class:
                    $platform = $record->account?->platform ?: 'General';
                    $targetTabs = ['All_Rebate_Tracker', ucfirst($platform) . '_Tracker'];
                    $headers = \App\Filament\Resources\RebateTrackerResource::$trackerHeaders;
                    $formattedRow = \App\Filament\Resources\RebateTrackerResource::formatRecordForSheet($record);
                    break;

                case \App\Models\PayoutLog::class:
                    $resource = \App\Filament\Resources\PayoutLogResource::class;
                    $targetTabs[] = 'Payout_Logs';
                    $headers = $resource::$payoutLogHeaders;
                    $formattedRow = $resource::formatPayoutLogForSheet($record);
                    break;

                case \App\Models\PayoutMethod::class:
                    $resource = \App\Filament\Resources\PayoutMethodResource::class;
                    $targetTabs[] = 'Payout_Methods';
                    $headers = $resource::$payoutMethodHeaders;
                    $formattedRow = $resource::formatPayoutMethodForSheet($record);
                    break;
            }

            // Chuyển logic Delete xuống đây để mảng $targetTabs đã được gán giá trị
            if ($this->action === 'delete') {
                if (!empty($targetTabs)) {
                    foreach ($targetTabs as $tabName) {
                        $service->deleteRowsByIds([(string)$this->recordId], $tabName);
                    }
                }
                return;
            }

            // 3. Thực hiện UPSERT
            if (!empty($targetTabs) && !empty($formattedRow)) {
                foreach ($targetTabs as $tabName) {
                    $service->createSheetIfNotExist($tabName);
                    // Lệnh này đã gọi chuẩn 3 tham số theo bản vá CRITICAL #3 ở Service
                    $service->upsertRows([$formattedRow], $tabName, $headers);
                    $this->applySpecificFormatting($service, $tabName);
                }
            }
        } catch (\Exception $e) {
            Log::error("Google Sheet Job Error [{$this->modelClass} ID: {$this->recordId}]: " . $e->getMessage());
        }
    }

    /**
     * Tự động load các quan hệ cần thiết tùy theo loại Model
     */
    protected function getRecordWithRelations()
    {
        $query = $this->modelClass::query();
        if ($this->modelClass === \App\Models\Account::class) {
            $query->with(['email', 'user']);
        } elseif ($this->modelClass === \App\Models\RebateTracker::class) {
            $query->with(['account.email', 'user']);
        } elseif ($this->modelClass === \App\Models\PayoutLog::class) {
            $query->with(['account.email', 'payoutMethod']);
        }
        return $query->find($this->recordId);
    }

    /**
     * Tự động định dạng (màu sắc, thu gọn cột) sau khi Sync xong 1 dòng
     */
    protected function applySpecificFormatting(GoogleSheetService $service, string $tabName)
    {
        // Định dạng cho Payout Logs
        if ($tabName === 'Payout_Logs') {
            $service->formatColumnsAsClip($tabName, 16, 17); // Status, Note

            // Định dạng cho Payout Methods
        } elseif ($tabName === 'Payout_Methods') {
            $service->formatColumnsAsClip($tabName, 4, 8); // Credentials
            $service->formatColumnsAsClip($tabName, 25, 26); // Note
            $service->applyFormattingWithRules($tabName, 24, [
                'Limited' => ['red' => 1.0, 'green' => 0.8, 'blue' => 0.8],
            ]);

            // Định dạng cho Emails
        } elseif ($tabName === 'Emails') {
            $service->formatColumnsAsClip($tabName, 2, 3); // Email & Pass
            $service->applyFormattingWithRules($tabName, 1, [
                'Live' => ['red' => 0.85, 'green' => 0.95, 'blue' => 0.85],
                'Disabled' => ['red' => 1.0, 'green' => 0.8, 'blue' => 0.8],
            ]);

            // Định dạng cho các tab Accounts
        } elseif (str_ends_with($tabName, '_Accounts')) {
            $service->formatColumnsAsClip($tabName, 5, 6);   // Note Email (F)
            $service->formatColumnsAsClip($tabName, 14, 15); // Platform Note (O)
            $service->formatColumnsAsClip($tabName, 17, 18); // Personal Info (R)

            // Định dạng cho các tab Tracker
        } elseif (str_contains($tabName, '_Tracker')) {
            $service->formatColumnsAsClip($tabName, 5, 6);   // Note Email
            $service->formatColumnsAsClip($tabName, 15, 16); // Note Platform
            $service->formatColumnsAsClip($tabName, 18, 19); // Personal Info
        }
    }
}
