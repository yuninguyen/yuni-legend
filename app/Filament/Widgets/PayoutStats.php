<?php

namespace App\Filament\Widgets;

use App\Models\PayoutLog;
use App\Models\RebateTracker;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Livewire\Attributes\On;
use Illuminate\Database\Eloquent\Builder;

class PayoutStats extends BaseWidget
{
    public static function canView(): bool
    {
        return !auth()->user()?->isFinance();
    }

    public array $filters = [];

    // 🟢 Đổi từ '10s' thành null để tắt Polling. 
    // Data sẽ cập nhật khi User F5 hoặc có tương tác.
    protected static ?string $pollingInterval = null;

    // Thêm hiệu ứng làm mờ khi đang tải (Loading state)
    protected static bool $isLazy = false;

    // 2. Lắng nghe sự kiện từ Table truyền lên (Nhận toàn bộ filter)
    #[On('updateStatsFilters')]
    public function updateFilters(array $filters): void
    {
        $this->filters = $filters;
        // Tín hiệu này sẽ tự động kích hoạt hàm getStats() chạy lại
    }

    protected int|string|array $columns = 4;

    protected function getStats(): array
    {
        // 1. Khởi tạo Query cho RebateTracker (Dành cho Card 1 - Confirmed)
        $rebateQuery = RebateTracker::query();

        // 2. Khởi tạo Query cho PayoutLog (Dành cho Card 2 & 3 - Paid/Exchanged)
        $payoutQuery = PayoutLog::query();

        $filters = $this->filters;

        /**
         * Helper: Áp dụng bộ lọc đồng nhất cho cả 2 loại query
         */
        $applyFilters = function (Builder $query, bool $isRebate = true) use ($filters) {
            $table = $isRebate ? 'rebate_trackers' : 'payout_logs';
            
            // 1. Lọc theo User
            $query->when($filters['user_id'] ?? null, function ($q, $userId) use ($table) {
                $q->where("{$table}.user_id", $userId);
            });

            // 2. Lọc theo Platform (Cần join với bảng accounts)
            $query->when($filters['platform'] ?? null, function ($q, $platform) use ($table) {
                $q->join('accounts', "{$table}.account_id", '=', 'accounts.id')
                  ->where('accounts.platform', $platform);
            });

            // 3. Lọc theo Thời gian
            $dateColumn = "{$table}.created_at";
            
            $query->when($filters['date_preset'] ?? null, function ($q, $preset) use ($dateColumn) {
                if (str_starts_with($preset, 'year_')) {
                    return $q->whereYear($dateColumn, (int) str_replace('year_', '', $preset));
                }
                return match ($preset) {
                    'today' => $q->whereDate($dateColumn, now()->toDateString()),
                    'this_month' => $q->whereMonth($dateColumn, now()->month)->whereYear($dateColumn, now()->year),
                    'this_quarter' => $q->whereBetween($dateColumn, [now()->firstOfQuarter(), now()->lastOfQuarter()]),
                    'this_year' => $q->whereYear($dateColumn, now()->year),
                    default => $q,
                };
            });

            $query->when($filters['from_date'] ?? null, fn($q, $date) => $q->whereDate($dateColumn, '>=', $date));
            $query->when($filters['to_date'] ?? null, fn($q, $date) => $q->whereDate($dateColumn, '<=', $date));
        };

        // 3. Bảo mật: Nếu không phải Admin, luôn giới hạn dữ liệu của chính mình
        if (!auth()->user()?->isAdmin()) {
            $rebateQuery->where('rebate_trackers.user_id', auth()->id());
            $payoutQuery->where('payout_logs.user_id', auth()->id());
        }

        // 4. Áp dụng bộ lọc động từ UI
        $applyFilters($rebateQuery, true);
        $applyFilters($payoutQuery, false);

        // --- TÍNH TOÁN CARD 0: PENDING (Từ RebateTracker) ---
        $totalPendingUsd = (clone $rebateQuery)->where('rebate_trackers.status', 'pending')
            ->sum('rebate_amount');

        // --- TÍNH TOÁN CARD 1: CONFIRMED (Từ RebateTracker) ---
        $totalConfirmedUsd = (clone $rebateQuery)->where('rebate_trackers.status', 'confirmed')
            ->sum('rebate_amount');

        // --- TÍNH TOÁN CARD 2: PAID USD (Từ PayoutLog) ---
        $totalPaidUsd = (clone $payoutQuery)
            ->whereIn('transaction_type', ['withdrawal', 'hold'])
            ->where('payout_logs.status', 'completed')
            ->sum('net_amount_usd');

        // --- TÍNH TOÁN CARD 3: EXCHANGED VND (Từ PayoutLog) ---
        $totalVnd = (clone $payoutQuery)
            ->where('transaction_type', 'liquidation')
            ->where('payout_logs.status', 'completed')
            ->sum('total_vnd');

        return [
            Stat::make(__('system.payout_logs.fields.total_pending'), '$' . number_format($totalPendingUsd, 2))
                ->description(__('system.widgets.cashback_pending_desc'))
                ->descriptionIcon('heroicon-m-clock')
                ->color('warning'),

            Stat::make(__('system.payout_logs.fields.total_confirmed'), '$' . number_format($totalConfirmedUsd, 2))
                ->description(__('system.widgets.cashback_confirmed_desc'))
                ->descriptionIcon('heroicon-m-check-circle')
                ->color('success')
                ->chart([7, 2, 10, 3, 15, 4, 17]),

            Stat::make(__('system.payout_logs.fields.total_paid_usd'), '$' . number_format($totalPaidUsd, 2))
                ->description(__('system.widgets.completed_payments_desc'))
                ->descriptionIcon('heroicon-m-banknotes')
                ->color('warning'),

            Stat::make(__('system.payout_logs.fields.total_exchanged_vnd'), number_format($totalVnd, 0, ',', '.') . ' ₫')
                ->description(__('system.widgets.converted_to_vnd_desc'))
                ->descriptionIcon('heroicon-m-arrows-right-left')
                ->color('info'),
        ];
    }
}
