<?php

namespace App\Filament\Widgets;

use App\Models\User;
use App\Models\UserPayment;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Support\Enums\Alignment;
use Filament\Tables;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Grouping\Group;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;
use Illuminate\Support\Carbon;

class AdminUserEarningsTable extends BaseWidget
{
    protected static ?int $sort = 3;

    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        // Cho phép Admin, Operator và Finance xem bảng này
        return in_array(auth()->user()?->role, ['admin', 'operator', 'finance']);
    }

    public function getTableRecordKey($record): string
    {
        return "{$record->user_id}-{$record->asset_group}";
    }

    public function getTableHeading(): string
    {
        return __('system.payroll.heading');
    }

    public function table(Table $table): Table
    {
        $raw = $this->tableFilters['table_filter'] ?? [];

        // Sanitize filter inputs before use in queries
        $userId = isset($raw['user_id']) && is_numeric($raw['user_id']) ? (int) $raw['user_id'] : null;
        $fromDate = null;
        $toDate = null;
        try {
            $fromDate = isset($raw['from_date']) && $raw['from_date'] !== ''
                ? Carbon::parse($raw['from_date'])->startOfDay()->toDateTimeString()
                : null;
            $toDate = isset($raw['to_date']) && $raw['to_date'] !== ''
                ? Carbon::parse($raw['to_date'])->endOfDay()->toDateTimeString()
                : null;
        } catch (\Exception) {
            // Invalid date input — ignore filter
        }

        // 1. Query cho Operator: Gộp theo Nhóm tài sản (Gift Card vs PayPal)
        $operatorQuery = UserPayment::query()
            ->join('users', 'user_payments.user_id', '=', 'users.id')
            ->whereIn('users.role', ['admin', 'staff', 'operator'])
            ->select('user_payments.user_id as user_id', 'users.role as user_role', 'users.name as user_name', 'user_payments.asset_group as asset_group')
            ->selectRaw('SUM(total_usd) as amount_usd')
            ->selectRaw("SUM(CASE WHEN status = 'paid' THEN total_vnd ELSE 0 END) as amount_paid")
            ->groupBy('user_payments.user_id', 'user_payments.asset_group', 'users.role', 'users.name')
            // Scope cho Operator: Chỉ thấy của chính mình
            ->when(! auth()->user()?->isAdmin() && ! auth()->user()?->isFinance(), fn ($query) => $query->where('user_payments.user_id', auth()->id()))
            ->when($userId, fn ($query, $id) => $query->where('user_payments.user_id', $id))
            ->when($fromDate, fn ($query, $date) => $query->where('user_payments.created_at', '>=', $date))
            ->when($toDate, fn ($query, $date) => $query->where('user_payments.created_at', '<=', $date));

        $usdSql = "SELECT SUM(total_usd) FROM user_payments WHERE status = 'paid' AND deleted_at IS NULL";
        $usdBindings = [];
        if ($fromDate) {
            $usdSql .= " AND created_at >= ?";
            $usdBindings[] = $fromDate;
        }
        if ($toDate) {
            $usdSql .= " AND created_at <= ?";
            $usdBindings[] = $toDate;
        }

        $paidSql = "SELECT SUM((exchange_rate - payout_rate) * total_usd * (payout_percentage / 100)) FROM user_payments WHERE status = 'paid' AND deleted_at IS NULL";
        $paidBindings = [];
        if ($fromDate) {
            $paidSql .= " AND created_at >= ?";
            $paidBindings[] = $fromDate;
        }
        if ($toDate) {
            $paidSql .= " AND created_at <= ?";
            $paidBindings[] = $toDate;
        }

        // 2. Query cho Finance: Chỉ hiện 1 dòng "Lợi nhuận hệ thống"
        $financeQuery = User::query()
            ->where('role', 'finance')
            ->select('users.id as user_id', 'users.role as user_role', 'users.name as user_name')
            ->selectRaw("'system_profit' as asset_group")
            ->selectRaw("({$usdSql}) as amount_usd", $usdBindings)
            ->selectRaw("({$paidSql}) as amount_paid", $paidBindings)
            ->when($userId, fn ($query, $id) => $query->where('id', $id));

        return $table
            ->query(function () use ($operatorQuery, $financeQuery) {
                // Nếu là Operator -> Không union financeQuery (không xem lợi nhuận hệ thống)
                if (! auth()->user()?->isAdmin() && ! auth()->user()?->isFinance()) {
                    return UserPayment::query()->withTrashed()->fromSub($operatorQuery, 'consolidated_payroll');
                }

                return UserPayment::query()->withTrashed()->fromSub($operatorQuery->union($financeQuery), 'consolidated_payroll');
            })
            ->columns([
                Tables\Columns\TextColumn::make('asset_group')
                    ->label(__('system.labels.asset_type'))
                    ->alignment(Alignment::Center)
                    ->formatStateUsing(function ($state) {
                        return match ($state) {
                            'gift_card' => '🎁 '.__('system.payroll.total_gift_card'),
                            'paypal' => '💰 '.__('system.payroll.total_paypal'),
                            'system_profit' => '📈 '.__('system.payroll.system_profit'),
                            default => $state,
                        };
                    })
                    ->color(fn ($state) => $state === 'system_profit' ? 'primary' : 'gray')
                    ->weight('bold'),

                Tables\Columns\TextColumn::make('user_role')
                    ->label(__('system.labels.role'))
                    ->alignment(Alignment::Center)
                    ->formatStateUsing(fn ($state) => $state ? __('system.roles.'.$state) : '-')
                    ->badge()
                    ->color(fn ($state): string => match ($state) {
                        'finance' => 'info',
                        'operator' => 'success',
                        default => 'gray',
                    })
                    ->visibleFrom('md'),

                Tables\Columns\TextColumn::make('amount_usd')
                    ->label(__('system.payout_logs.fields.net_amount_usd'))
                    ->money('USD')
                    ->color('info')
                    ->alignment(Alignment::Center)
                    ->summarize(
                        Tables\Columns\Summarizers\Sum::make()
                            ->label('')
                            ->money('USD')
                            ->extraAttributes(['class' => 'flex w-full justify-center'])
                    ),

                Tables\Columns\TextColumn::make('amount_paid')
                    ->label(__('system.status.completed').' (VND)')
                    ->money('VND', locale: 'vi_VN')
                    ->color('success')
                    ->weight('bold')
                    ->alignment(Alignment::Center)
                    ->summarize(
                        Tables\Columns\Summarizers\Sum::make()
                            ->label('')
                            ->money('VND', locale: 'vi_VN')
                            ->extraAttributes(['class' => 'flex w-full justify-center'])
                    ),
            ])
            ->groups([
                Group::make('user_id')
                    ->label(__('system.labels.user'))
                    ->getTitleFromRecordUsing(fn ($record) => $record->user_name ?? 'Unknown')
                    ->collapsible(),
            ])
            ->defaultGroup('user_id')
            ->defaultSort('user_id')
            ->recordTitle(fn ($record) => $record->user?->name)
            ->paginated(false)
            ->filters([
                Filter::make('table_filter')
                    ->form([
                        Select::make('user_id')
                            ->label(__('system.labels.user'))
                            ->options(User::whereIn('role', ['admin', 'staff', 'operator', 'finance'])->pluck('name', 'id'))
                            ->searchable()
                            ->visible(fn () => auth()->user()?->isAdmin() || auth()->user()?->isFinance())
                            ->live(),
                        DatePicker::make('from_date')
                            ->label(__('system.labels.from'))
                            ->live(),
                        DatePicker::make('to_date')
                            ->label(__('system.labels.until'))
                            ->live(),
                    ])
                    ->columns(in_array(auth()->user()?->role, ['admin', 'finance']) ? 3 : 2)
                    ->columnSpanFull(),
            ], layout: Tables\Enums\FiltersLayout::AboveContent);
    }
}
