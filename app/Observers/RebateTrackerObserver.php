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
        // Lấy dữ liệu (Bạn có thể điều chỉnh lại tên cột cho khớp với DB của bạn)
        $userName = $tracker->user->name ?? 'N/A';
        $platformName = $tracker->account->platform ?? 'N/A'; // Lấy platform từ account liên kết

        $data = [
            $tracker->id,
            $userName,
            $platformName,
            $tracker->rebate_amount,
            $tracker->status,
            $tracker->created_at->format('Y-m-d H:i:s'),
        ];

        // Đẩy lên tab có tên là 'All_Rebate_Tracker' (Tham số thứ 2)
        // Thay vì gọi thẳng, dùng Job để chạy ngầm
        \App\Jobs\SyncGoogleSheetJob::dispatch($tracker->id, RebateTracker::class);

    }
}
