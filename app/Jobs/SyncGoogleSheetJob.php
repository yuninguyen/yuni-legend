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

    // 🟢 THÊM 3 DÒNG NÀY ĐỂ JOB TỰ ĐỘNG THỬ LẠI KHI GOOGLE LỖI
    public int $tries = 3;      // Thử lại tối đa 3 lần
    public int $backoff = 60;   // Đợi 60 giây giữa các lần thử
    public int $timeout = 120;   // Ngắt Job nếu chạy quá 120 giây để tránh treo máy chủ

    /**
     * @param int         $recordId   ID của bản ghi cần sync
     * @param string      $modelClass Tên Class của Model
     * @param string      $action     'upsert' hoặc 'delete'
     * @param string|null $platform   Platform lưu kèm để dùng khi delete
     *                                (record đã xóa khỏi DB nên không query được)
     */
    public function __construct(
        protected $recordId,
        protected $modelClass,
        protected string $action = 'upsert',
        protected ?string $platform = null   // FIX #6: lưu platform vào job
    ) {
    }

    public function handle(\App\Services\GoogleSyncService $syncService): void
    {
        try {
            // ── 1. Trường hợp DELETE ──
            // Khi xóa, Model có thể đã mất khỏi DB nên chúng ta tạo object giả để lấy ID và Class
            if ($this->action === 'delete') {
                $record = new $this->modelClass;
                $record->id = $this->recordId;

                $syncService->syncRecord($record, 'delete', $this->platform);
                return;
            }

            // ── 2. Trường hợp UPSERT: Tìm bản ghi kèm relations ──
            $record = $this->getRecordWithRelations();

            if (!$record) {
                Log::warning("SyncGoogleSheetJob: Record not found [{$this->modelClass} #{$this->recordId}]");
                return;
            }

            // ── 3. Đồng bộ dùng Service tập trung ──
            $syncService->syncRecord($record, 'upsert');

        } catch (\Exception $e) {
            Log::error("SyncGoogleSheetJob Error [{$this->modelClass} #{$this->recordId}]: " . $e->getMessage());
            throw $e;
        }
    }

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
}
