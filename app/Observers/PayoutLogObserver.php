<?php

namespace App\Observers;

use App\Models\PayoutLog;
use App\Services\GoogleSheetService;
use App\Jobs\SyncGoogleSheetJob;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;
use Illuminate\Support\Facades\Log;

class PayoutLogObserver implements ShouldHandleEventsAfterCommit
{
    /**
     * Sự kiện SAVED: Chạy sau khi bản ghi được Lưu (cả Create và Update)
     */
    public function saved(PayoutLog $payoutLog): void
    {
        // 🟢 1. LOGIC ĐỒNG BỘ NỘI BỘ (DATABASE)
        // Nếu là dòng thanh khoản (liquidation), cập nhật tỷ giá và VND cho dòng cha
        if ($payoutLog->transaction_type === 'liquidation' && $payoutLog->parent_id) {
            $parent = $payoutLog->parent;
            if ($parent) {
                // Chỉ update nếu có sự thay đổi về tiền tệ
                if ($payoutLog->wasChanged(['exchange_rate', 'total_vnd']) || $payoutLog->wasRecentlyCreated) {
                    $parent->updateQuietly([
                        'exchange_rate' => $payoutLog->exchange_rate,
                        'total_vnd' => $payoutLog->total_vnd,
                    ]);
                }
            }
        }

        // 🟢 NẾU ĐANG SYNC TỪ SHEET VỀ THÌ KHÔNG ĐẨY JOB NGƯỢC LÊN NỮA
        if (isset($payoutLog->is_syncing_from_sheet) && $payoutLog->is_syncing_from_sheet) {
            return;
        }

        // 🟢 2. ĐẨY JOB LÊN GOOGLE SHEETS
        // Thay vì gọi syncToSheet trực tiếp (làm chậm web), ta đẩy vào Job để chạy ngầm
        \App\Jobs\SyncGoogleSheetJob::dispatch($payoutLog->id, get_class($payoutLog));
    }

    /**
     * Sự kiện UPDATED: Xử lý thay đổi Status để cộng/trừ tiền ví
     */
    public function updated(PayoutLog $payoutLog): void
    {
        // 🟢 3. CẬP NHẬT BALANCE (Chỉ chạy khi status từ Pending -> Completed)
        if ($payoutLog->wasChanged('status') && $payoutLog->status === 'completed') {
            $method = $payoutLog->payoutMethod;

            if ($method) {
                // Nếu là Withdrawal (Dành cho PayPal): Cộng tiền vào ví
                if ($payoutLog->transaction_type === 'withdrawal') {
                    $method->increment('current_balance', $payoutLog->net_amount_usd);
                }

                // Hold (Keep Code - Gift Card đã nhận về tay): TRỪ balance vì đã dùng tiền mua GC
                // FIX #5: Sửa comment cho đúng với thực tế code (decrement, không phải increment)
                elseif ($payoutLog->transaction_type === 'hold') {
                    $method->decrement('current_balance', $payoutLog->amount_usd);
                }
                // Nếu là Liquidation: Trừ tiền khỏi ví (vì đã lấy tiền mặt VND)
                elseif ($payoutLog->transaction_type === 'liquidation') {
                    $method->decrement('current_balance', $payoutLog->amount_usd);
                }
            }
        }
    }

    /**
     * Sự kiện DELETED: Cập nhật lại Sheet khi xóa dòng
     */
    public function deleted(PayoutLog $payoutLog): void
    {
        \App\Jobs\SyncGoogleSheetJob::dispatch($payoutLog->id, get_class($payoutLog), 'delete');
    }
}
