<?php

namespace App\Observers;

use App\Models\RebateTracker;
use App\Jobs\SyncGoogleSheetJob;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;

class RebateTrackerObserver implements ShouldHandleEventsAfterCommit
{
    /**
     * Chạy sau khi bản ghi được Lưu (cả Create lẫn Update).
     * saved() = created() + updated() - dùng 1 method gọn hơn.
     */
    public function saved(RebateTracker $tracker): void
    {
        // Không dispatch ngược lại Sheet khi đang import TỪ Sheet (tránh spam quota Google API)
        if (RebateTracker::$syncingFromSheet) {
            return;
        }

        SyncGoogleSheetJob::dispatch($tracker->id, get_class($tracker));
    }

    /**
     * Chạy sau khi bản ghi bị xóa.
     * Truyền kèm platform để Job biết xóa đúng tab (vd: Rakuten_Tracker).
     */
    public function deleted(RebateTracker $tracker): void
    {
        $platform = $tracker->account?->platform ?: null;
        SyncGoogleSheetJob::dispatch($tracker->id, get_class($tracker), 'delete', $platform);
    }
}
