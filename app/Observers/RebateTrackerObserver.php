<?php

namespace App\Observers;

use App\Models\RebateTracker;
use App\Services\GoogleSheetService;

class RebateTrackerObserver
{
    protected GoogleSheetService $sheetService;

    // Tiêm (Inject) Service vào để dùng
    public function __construct(GoogleSheetService $sheetService)
    {
        $this->sheetService = $sheetService;
    }

    // Hàm này tự chạy khi một RebateTracker mới được tạo ra
public function created(RebateTracker $tracker): void
    {
        SyncGoogleSheetJob::dispatch($tracker->id, get_class($tracker));
    }

    public function updated(RebateTracker $tracker): void
    {
        SyncGoogleSheetJob::dispatch($tracker->id, get_class($tracker));
    }

    public function deleted(RebateTracker $tracker): void
    {
        SyncGoogleSheetJob::dispatch($tracker->id, get_class($tracker), 'delete');
    }
}
