<?php

namespace App\Observers;

use App\Models\PayoutLog;
use App\Models\PayoutMethod;
use App\Jobs\SyncGoogleSheetJob;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PayoutLogObserver implements ShouldHandleEventsAfterCommit
{
    /**
     * Sự kiện SAVED: Chạy sau khi bản ghi được Lưu (cả Create lẫn Update).
     *
     * Đây là nơi DUY NHẤT xử lý:
     * 1. Auto-complete parent khi một Liquidation được tạo/cập nhật
     * 2. Đồng bộ bản ghi lên Google Sheets qua Job
     */
    public function saved(PayoutLog $payoutLog): void
    {
        if ($payoutLog->transaction_type === 'liquidation' && $payoutLog->parent_id) {
            DB::transaction(function () use ($payoutLog) {
                $parent = PayoutLog::lockForUpdate()->find($payoutLog->parent_id);

                if (!$parent) {
                    return;
                }

                if ($parent->status !== 'completed') {
                    $parent->updateQuietly(['status' => 'completed']);

                    // Only dispatch parent sync when NOT already syncing from sheet
                    if (!PayoutLog::$syncingFromSheet) {
                        \App\Jobs\SyncGoogleSheetJob::dispatch($parent->id, get_class($parent));
                    }
                }

                if ($payoutLog->wasChanged(['exchange_rate', 'total_vnd']) || $payoutLog->wasRecentlyCreated) {
                    $parent->updateQuietly([
                        'exchange_rate' => $payoutLog->exchange_rate,
                        'total_vnd' => $payoutLog->total_vnd,
                    ]);
                }
            });
        }

        // Stop here when syncing from Google Sheet to avoid infinite loop
        if (PayoutLog::$syncingFromSheet) {
            return;
        }

        SyncGoogleSheetJob::dispatch($payoutLog->id, get_class($payoutLog));
    }

    /**
     * Sự kiện CREATED: Tính lại số dư ví nếu đơn vừa tạo đã ở trạng thái Completed.
     */
    public function created(PayoutLog $payoutLog): void
    {
        if ($payoutLog->status === 'completed') {
            $this->syncMethodBalance($payoutLog->payoutMethod);

            if ($payoutLog->transaction_type === 'liquidation') {
                $this->updateMethodExchangeRate($payoutLog->payoutMethod);
            }
        }
    }

    /**
     * Sự kiện UPDATED: Tính lại số dư ví khi status thay đổi.
     */
    public function updated(PayoutLog $payoutLog): void
    {
        // 1. Kiểm tra nếu có sự thay đổi trạng thái (vào hoặc ra khỏi Completed)
        $wasCompleted = $payoutLog->getOriginal('status') === 'completed';
        $isCompleted = $payoutLog->status === 'completed';

        if ($wasCompleted || $isCompleted) {
            $this->syncMethodBalance($payoutLog->payoutMethod);

            // 2. Nếu là Liquidation và thay đổi dữ liệu liên quan đến tỷ giá
            if ($payoutLog->transaction_type === 'liquidation') {
                $this->updateMethodExchangeRate($payoutLog->payoutMethod);
            }
        }
    }

    /**
     * Sự kiện DELETED: Hoàn lại số dư ví và xóa dòng trên Google Sheet.
     */
    public function deleted(PayoutLog $payoutLog): void
    {
        if ($payoutLog->status === 'completed') {
            $this->syncMethodBalance($payoutLog->payoutMethod);

            if ($payoutLog->transaction_type === 'liquidation') {
                $this->updateMethodExchangeRate($payoutLog->payoutMethod);
            }
        }

        // Luôn đồng bộ lệnh xóa lên Google Sheet
        SyncGoogleSheetJob::dispatch($payoutLog->id, get_class($payoutLog), 'delete');
    }

    /**
     * Tính lại số dư ví bằng cách SUM từ database (không cộng/trừ thủ công
     * để tránh sai số tích lũy khi có nhiều giao dịch đồng thời).
     */
    private function syncMethodBalance(?PayoutMethod $method): void
    {
        if (!$method)
            return;

        DB::transaction(function () use ($method) {
            // lockForUpdate() phải gọi trên Builder (PayoutMethod::),
            // không phải gọi trên Model instance ($method->lockForUpdate() là SAI).
            // Lock ví để tránh race condition
            $method = PayoutMethod::lockForUpdate()->find($method->id);

            if (!$method)
                return;

            // TỔNG RÚT (WITHDRAWAL) - Dùng net_amount_usd (tiền thực nhận)
            $totalWithdraw = PayoutLog::where('payout_method_id', $method->id)
                ->where('transaction_type', 'withdrawal')
                ->where('status', 'completed')
                ->sum('net_amount_usd') ?? 0;

            // TỔNG ĐỔI (LIQUIDATION) - Dùng amount_usd (tiền gốc bán ra)
            $totalLiquidate = PayoutLog::where('payout_method_id', $method->id)
                ->where('transaction_type', 'liquidation')
                ->where('status', 'completed')
                ->sum('amount_usd') ?? 0;

            // Số dư = Thu - Chi
            $balance = $totalWithdraw - $totalLiquidate;

            $method->updateQuietly(['current_balance' => round($balance, 2)]);
        });
    }

    /**
     * Tính tỷ giá trung bình gia quyền (VWAP) cho ví dựa trên các Liquidation đã hoàn thành.
     * Công thức: Tỷ giá TB = SUM(amount_usd × exchange_rate) / SUM(amount_usd)
     */
    private function updateMethodExchangeRate(?PayoutMethod $method): void
    {
        if (!$method)
            return;

        $data = PayoutLog::where('payout_method_id', $method->id)
            ->where('transaction_type', 'liquidation')
            ->where('status', 'completed')
            ->selectRaw('SUM(amount_usd * exchange_rate) as total_weighted, SUM(amount_usd) as total_usd')
            ->first();

        $averageRate = 0;
        if ($data && $data->total_usd > 0) {
            $averageRate = $data->total_weighted / $data->total_usd;
        }

        // Cập nhật vào ví (dùng updateQuietly để tránh lặp)
        $method->updateQuietly(['exchange_rate' => round($averageRate, 2)]);
    }
}
