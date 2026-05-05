<?php

namespace App\Filament\Widgets;

use App\Models\Platform;
use App\Models\RebateTracker;
use App\Models\User;
use Carbon\Carbon;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Get;
// Import thêm các class phục vụ Filter
use Filament\Support\Enums\Alignment;
use Filament\Tables;
use Filament\Tables\Columns\Summarizers\Sum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class UserPlatformMatrixTable extends BaseWidget
{
    public static function canView(): bool
    {
        return ! auth()->user()?->isFinance();
    }

    protected int|string|array $columnSpan = 'full';

    public function getTableHeading(): ?string
    {
        return __('system.revenue_report.heading');
    }

    // Thêm vào trong class UserPlatformMatrixTable
    public function updatedTableFilters(): void
    {
        // Lấy toàn bộ giá trị từ bộ lọc 'table_filter'
        $filters = $this->tableFilters['table_filter'] ?? [];

        // Bắn tín hiệu kèm theo toàn bộ filter ra Dashboard
        $this->dispatch('updateStatsFilters', filters: $filters);
    }

    public function getTableRecordKey($record): string
    {
        return "{$record->user_name}-{$record->platform_name}";
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(
                fn () => RebateTracker::query()
                    ->join('accounts', 'rebate_trackers.account_id', '=', 'accounts.id')
                    ->join('users', 'rebate_trackers.user_id', '=', 'users.id')
                    ->leftJoin('platforms', 'accounts.platform', '=', 'platforms.slug')
                    ->when(! auth()->user()?->isAdmin(), function ($query) {
                        return $query->where('rebate_trackers.user_id', auth()->id());
                    })
                    ->where('users.role', '!=', 'finance')
                    ->select(
                        'users.name as user_name',
                        DB::raw('COALESCE(NULLIF(platforms.name, ""), accounts.platform) as platform_name'),
                        DB::raw('SUM(CASE WHEN rebate_trackers.status = "Clicked" THEN rebate_amount ELSE 0 END) as total_clicked'),
                        DB::raw('SUM(CASE WHEN rebate_trackers.status = "Missing" THEN rebate_amount ELSE 0 END) as total_missing'),
                        DB::raw('SUM(CASE WHEN rebate_trackers.status = "Pending" THEN rebate_amount ELSE 0 END) as total_pending'),
                        DB::raw('SUM(CASE WHEN rebate_trackers.status = "Confirmed" THEN rebate_amount ELSE 0 END) as total_confirmed'),
                        DB::raw('SUM(rebate_amount) as grand_total')
                    )
                    ->groupBy('users.name', DB::raw('COALESCE(NULLIF(platforms.name, ""), accounts.platform)'))
            )
            // CẤU HÌNH BỘ LỌC NẰM NGANG
            ->filters([
                Filter::make('table_filter')
                    ->form([
                        Select::make('user_id')
                            ->label(__('system.labels.user'))
                            ->options(User::whereNot('role', 'finance')->pluck('name', 'id'))
                            ->searchable()
                            ->visible(fn () => auth()->user()?->isAdmin())
                            ->live(),
                        Select::make('platform')
                            ->label(__('system.labels.platform'))
                            ->options(function (Get $get) {
                                $userId = $get('user_id') ?? (auth()->user()?->isAdmin() ? null : auth()->id());

                                $usedPlatforms = RebateTracker::query()
                                    ->join('accounts', 'rebate_trackers.account_id', '=', 'accounts.id')
                                    ->when($userId, fn ($q) => $q->where('rebate_trackers.user_id', $userId))
                                    ->distinct()
                                    ->pluck('accounts.platform');

                                return Platform::query()
                                    ->whereIn('slug', $usedPlatforms)
                                    ->orderBy('sort_order')
                                    ->pluck('name', 'slug');
                            })
                            ->searchable()
                            ->live(),
                        Select::make('date_preset')
                            ->label(__('system.revenue_report.quick_date'))
                            ->options(function () {
                                $options = [
                                    'today' => __('system.revenue_report.today'),
                                    'this_month' => __('system.revenue_report.this_month'),
                                    'this_quarter' => __('system.revenue_report.this_quarter'),
                                    'this_year' => __('system.revenue_report.this_year'),
                                ];
                                $currentYear = now()->year;

                                // Tìm năm của RebateTracker cũ nhất trong hệ thống
                                $oldestRecord = RebateTracker::min('created_at');
                                $oldestYear = $oldestRecord ? Carbon::parse($oldestRecord)->year : $currentYear;

                                for ($y = $currentYear - 1; $y >= $oldestYear; $y--) {
                                    $options['year_'.$y] = __('system.revenue_report.year_n', ['year' => $y]);
                                }

                                return $options;
                            })
                            ->live(),
                        DatePicker::make('from_date')
                            ->label(__('system.revenue_report.from_date')),
                        DatePicker::make('to_date')
                            ->label(__('system.revenue_report.to_date')),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when(
                                $data['user_id'] ?? null,
                                fn (Builder $query, $userId): Builder => $query->where('rebate_trackers.user_id', $userId)
                            )
                            ->when(
                                $data['platform'] ?? null,
                                fn (Builder $query, $platform): Builder => $query->where('accounts.platform', $platform)
                            )
                            ->when(
                                $data['date_preset'] ?? null,
                                function (Builder $query, $preset) {
                                    if (str_starts_with($preset, 'year_')) {
                                        return $query->whereYear('rebate_trackers.created_at', (int) str_replace('year_', '', $preset));
                                    }

                                    return match ($preset) {
                                        'today' => $query->whereDate('rebate_trackers.created_at', now()->toDateString()),
                                        'this_month' => $query->whereMonth('rebate_trackers.created_at', now()->month)
                                            ->whereYear('rebate_trackers.created_at', now()->year),
                                        'this_quarter' => $query->whereBetween('rebate_trackers.created_at', [now()->firstOfQuarter(), now()->lastOfQuarter()]),
                                        'this_year' => $query->whereYear('rebate_trackers.created_at', now()->year),
                                        default => $query,
                                    };
                                }
                            )
                            ->when(
                                $data['from_date'] ?? null,
                                fn (Builder $query, $date): Builder => $query->whereDate('rebate_trackers.created_at', '>=', $date)
                            )
                            ->when(
                                $data['to_date'] ?? null,
                                fn (Builder $query, $date): Builder => $query->whereDate('rebate_trackers.created_at', '<=', $date)
                            );
                    })
                    ->columns(2)
                    ->columnSpanFull(),
            ], layout: Tables\Enums\FiltersLayout::Dropdown)

            ->defaultSort('user_name', 'asc')
            ->paginated(false)
            ->columns([
                TextColumn::make('user_name')
                    ->label(__('system.labels.user'))
                    ->weight('bold')
                    ->alignment(Alignment::Center)
                    ->summarize(
                        Sum::make()
                            ->label('')
                            ->formatStateUsing(fn () => __('system.revenue_report.grand_total'))
                    ),

                TextColumn::make('platform_name')
                    ->label(__('system.labels.platform'))
                    ->alignment(Alignment::Center),

                TextColumn::make('total_clicked')
                    ->label(__('system.revenue_report.total_clicked'))
                    ->money('usd')
                    ->alignment(Alignment::Center),

                TextColumn::make('total_missing')
                    ->label(__('system.revenue_report.total_missing'))
                    ->money('usd')
                    ->alignment(Alignment::Center)
                    ->description(fn ($record) => $record->grand_total > 0
                        ? number_format(($record->total_missing / $record->grand_total) * 100, 1).'%'
                        : null)
                    ->color('danger')
                    ->summarize(
                        Sum::make()
                            ->label('')
                            ->money('usd')
                            ->extraAttributes(['class' => 'flex w-full justify-center'])
                    ),

                TextColumn::make('total_pending')
                    ->label(__('system.revenue_report.total_pending'))
                    ->money('usd')
                    ->alignment(Alignment::Center)
                    ->summarize(
                        Sum::make()
                            ->label('')
                            ->money('usd')
                            ->extraAttributes(['class' => 'flex w-full justify-center'])
                    ),

                TextColumn::make('total_confirmed')
                    ->label(__('system.revenue_report.total_confirmed'))
                    ->money('usd')
                    ->alignment(Alignment::Center)
                    ->description(fn ($record) => $record->grand_total > 0
                        ? number_format(($record->total_confirmed / $record->grand_total) * 100, 1).'%'
                        : null)
                    ->color('success')
                    ->summarize(
                        Sum::make()
                            ->label('')
                            ->money('usd')
                            ->extraAttributes(['class' => 'flex w-full justify-center'])
                    ),

                TextColumn::make('grand_total')
                    ->label(__('system.revenue_report.total_rebate'))
                    ->alignment(Alignment::Center)
                    ->money('usd')
                    ->weight('bold')
                    ->color('primary')
                    // Cột tổng quan trọng nhất
                    ->summarize(
                        Sum::make()
                            ->label('')
                            ->money('usd')
                            ->extraAttributes(['class' => 'flex w-full justify-center'])

                    ),
            ]);
    }
}
