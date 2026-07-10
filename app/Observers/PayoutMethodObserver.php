<?php

namespace App\Observers;

use App\Models\PayoutMethod;
use App\Jobs\SyncGoogleSheetJob;

use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;

class PayoutMethodObserver implements ShouldHandleEventsAfterCommit
{
    // Chạy sau khi Save (cả Create và Update)
    public function saved(PayoutMethod $payoutMethod): void
    {
        // Không dispatch ngược lại Sheet khi đang import TỪ Sheet (tránh spam quota Google API)
        if (PayoutMethod::$syncingFromSheet) {
            return;
        }

        // Gửi ID và Class sang Job để chạy ngầm
        SyncGoogleSheetJob::dispatch($payoutMethod->id, PayoutMethod::class);
    }

    // Chạy sau khi Xóa
    public function deleted(PayoutMethod $payoutMethod): void
    {
        // Bạn có thể gọi hàm xóa dòng trên Sheet nếu muốn sạch sẽ 100%
        SyncGoogleSheetJob::dispatch($payoutMethod->id, PayoutMethod::class, 'delete');
    }
}