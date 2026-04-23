<?php

namespace App\Filament\Widgets;

use App\Models\PayoutLog;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Livewire\Attributes\On;

class PayoutStats extends BaseWidget
{
    public static function canView(): bool
    {
        return !auth()->user()?->isFinance();
    }
    // 1. Phải khai báo biến này ở đây để lưu trữ ID User khi nhận được từ Table
    public ?int $selectedUserId = null;

    // 🟢 Đổi từ '10s' thành null để tắt Polling. 
    // Data sẽ cập nhật khi User F5 hoặc có tương tác.
    protected static ?string $pollingInterval = null;

    // Thêm hiệu ứng làm mờ khi đang tải (Loading state)
    protected static bool $isLazy = false;

    // 2. Lắng nghe sự kiện từ Table truyền lên
    #[On('updateStatsUser')]
    public function updateUserId($userId): void
    {
        $this->selectedUserId = $userId;
        // Tín hiệu này sẽ tự động kích hoạt hàm getStats() chạy lại
    }

    protected function getStats(): array
    {
        // 1. Khởi tạo Query cho RebateTracker (Dành cho Card 1 - Confirmed)
        // Đây là nguồn dữ liệu chính xác nhất cho doanh thu đã xác nhận từ platform
        $rebateQuery = \App\Models\RebateTracker::query();

        // 2. Khởi tạo Query cho PayoutLog (Dành cho Card 2 & 3 - Paid/Exchanged)
        // Đây là nguồn dữ liệu phản ánh chính xác các giao dịch thực tế trong hệ thống
        $payoutQuery = \App\Models\PayoutLog::query();

        // 3. Áp dụng logic lọc theo User cho các Query
        if (!auth()->user()?->isAdmin()) {
            // Nếu là Staff, chỉ xem của chính mình
            $rebateQuery->where('user_id', auth()->id());
            $payoutQuery->where('user_id', auth()->id());
        } elseif ($this->selectedUserId) {
            // Nếu là Admin và đang chọn 1 User cụ thể
            $rebateQuery->where('user_id', $this->selectedUserId);
            $payoutQuery->where('user_id', $this->selectedUserId);
        }

        // --- TÍNH TOÁN CARD 1: CONFIRMED (Từ RebateTracker) ---
        $totalConfirmedUsd = (clone $rebateQuery)->where('status', 'confirmed')
            ->sum('rebate_amount');

        // --- TÍNH TOÁN CARD 2: PAID USD (Tổng tiền thực nhận từ Withdrawal và Hold) ---
        $totalPaidUsd = (clone $payoutQuery)
            ->whereIn('transaction_type', ['withdrawal', 'hold'])
            ->where('status', 'completed')
            ->sum('net_amount_usd');

        // --- TÍNH TOÁN CARD 3: EXCHANGED VND (Tổng tiền đã đổi từ Liquidation) ---
        $totalVnd = (clone $payoutQuery)
            ->where('transaction_type', 'liquidation')
            ->where('status', 'completed')
            ->sum('total_vnd');

        // Hiển thị nhãn để biết đang lọc hay xem tổng
        $labelSuffix = $this->selectedUserId ? __('system.payout_logs.fields.filtered') : '';

        return [
            Stat::make(__('system.payout_logs.fields.total_confirmed') . $labelSuffix, '$' . number_format($totalConfirmedUsd, 2))
                ->description(__('system.widgets.cashback_confirmed_desc'))
                ->descriptionIcon('heroicon-m-check-circle')
                ->color('success')
                ->chart([7, 2, 10, 3, 15, 4, 17]),

            Stat::make(__('system.payout_logs.fields.total_paid_usd') . $labelSuffix, '$' . number_format($totalPaidUsd, 2))
                ->description(__('system.widgets.completed_payments_desc'))
                ->descriptionIcon('heroicon-m-banknotes')
                ->color('warning'),

            Stat::make(__('system.payout_logs.fields.total_exchanged_vnd') . $labelSuffix, number_format($totalVnd, 0, ',', '.') . ' ₫')
                ->description(__('system.widgets.converted_to_vnd_desc'))
                ->descriptionIcon('heroicon-m-arrows-right-left')
                ->color('info'),
        ];
    }
}
