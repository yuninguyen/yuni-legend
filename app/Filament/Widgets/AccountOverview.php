<?php

namespace App\Filament\Widgets;

use App\Models\Account;
use App\Models\User;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\DB;

class AccountOverview extends BaseWidget
{
    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        return !auth()->user()?->isFinance();
    }

    protected function getStats(): array
    {
        $stats = [];
        $isAdmin = auth()->user()?->isAdmin();

        $lblLive = __('system.status.live');
        $lblBanned = __('system.status.banned');
        $lblAvailable = __('system.unassigned');

        // Lấy bản đồ slug => name để hiển thị tên đẹp thay vì slug
        $platformNames = \App\Models\Platform::pluck('name', 'slug')->toArray();

        // Trình dựng Giao diện (HTML) cho phần tổng quan Live/Banned/Unassigned
        $buildSummaryHtml = function ($active, $banned, $available) use ($lblLive, $lblBanned, $lblAvailable) {

            return "
                <div style='display: flex; gap: 12px; align-items: center; margin-top: 8px;'>
                    <div style='display: flex; flex-direction: column; gap: 2px;'>
                        <div style='display: flex; align-items: center; gap: 6px;'>
                            <div style='width: 8px; height: 8px; background: #4BC0C0; border-radius: 50%;'></div>
                            <span style='font-size: 11px; font-weight: 600; color: #4BC0C0; text-transform: uppercase; letter-spacing: 0.05em;'>{$lblLive}</span>
                        </div>
                        <div style='padding-left: 14px; font-size: 15px; font-weight: 800; color: #1e293b;'>{$active}</div>
                    </div>

                    <div style='width: 1px; height: 24px; background: #e2e8f0;'></div>

                    <div style='display: flex; flex-direction: column; gap: 2px;'>
                        <div style='display: flex; align-items: center; gap: 6px;'>
                            <div style='width: 8px; height: 8px; background: #FF6384; border-radius: 50%;'></div>
                            <span style='font-size: 11px; font-weight: 600; color: #FF6384; text-transform: uppercase; letter-spacing: 0.05em;'>{$lblBanned}</span>
                        </div>
                        <div style='padding-left: 14px; font-size: 15px; font-weight: 800; color: #1e293b;'>{$banned}</div>
                    </div>

                    <div style='width: 1px; height: 24px; background: #e2e8f0;'></div>

                    <div style='display: flex; flex-direction: column; gap: 2px;'>
                        <div style='display: flex; align-items: center; gap: 6px;'>
                            <div style='width: 8px; height: 8px; background: #FF9F40; border-radius: 50%;'></div>
                            <span style='font-size: 11px; font-weight: 600; color: #FF9F40; text-transform: uppercase; letter-spacing: 0.05em;'>{$lblAvailable}</span>
                        </div>
                        <div style='padding-left: 14px; font-size: 15px; font-weight: 800; color: #1e293b;'>{$available}</div>
                    </div>
                </div>
            ";
        };

        // Trình dựng HTML chi tiết theo từng User — hiển thị TẤT CẢ user
        $buildUserDetailHtml = function ($allUsers, $dataMap, $unassignedData = null) use ($lblAvailable) {
            $rows = '';
            foreach ($allUsers as $user) {
                $name = e($user->name);
                $initial = strtoupper(mb_substr($name, 0, 1));
                $data = $dataMap->get($user->id);
                $total = $data->total_count ?? 0;
                $live = $data->live_count ?? 0;
                $banned = $data->banned_count ?? 0;

                $rows .= "
                    <div style='display: grid; grid-template-columns: 24px 90px 1fr 1fr 1fr; align-items: center; gap: 10px; padding: 5px 0;'>
                        <div style='width: 24px; height: 24px; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); border-radius: 50%; display: flex; align-items: center; justify-content: center; flex-shrink: 0;'>
                            <span style='font-size: 10px; font-weight: 700; color: white; line-height: 1;'>{$initial}</span>
                        </div>
                        <span style='font-size: 12px; font-weight: 600; color: #334155; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;'>{$name}</span>
                        <span style='font-size: 10px; font-weight: 700; color: #475569; background: #f1f5f9; padding: 3px 6px; border-radius: 6px; text-align: center; white-space: nowrap;'>" . __('system.master_account_claim.total') . ": {$total}</span>
                        <span style='font-size: 10px; font-weight: 600; color: #4BC0C0; background: rgba(75,192,192,0.1); padding: 3px 6px; border-radius: 6px; text-align: center; white-space: nowrap;'>" . __('system.status.live') . ": {$live}</span>
                        <span style='font-size: 10px; font-weight: 600; color: #FF6384; background: rgba(255,99,132,0.1); padding: 3px 6px; border-radius: 6px; text-align: center; white-space: nowrap;'>" . __('system.status.banned') . ": {$banned}</span>
                    </div>
                ";
            }

            // Thêm hàng Unassigned nếu có
            if ($unassignedData && $unassignedData['total'] > 0) {
                $rows .= "
                    <div style='display: grid; grid-template-columns: 24px 90px 1fr 1fr 1fr; align-items: center; gap: 10px; padding: 5px 0; border-top: 1px solid #f1f5f9; margin-top: 2px;'>
                        <div style='width: 24px; height: 24px; background: #94a3b8; border-radius: 50%; display: flex; align-items: center; justify-content: center; flex-shrink: 0;'>
                            <span style='font-size: 10px; font-weight: 700; color: white;'>?</span>
                        </div>
                        <span style='font-size: 12px; font-weight: 600; color: #64748b; font-style: italic;'>{$lblAvailable}</span>
                        <span style='font-size: 10px; font-weight: 700; color: #475569; background: #f1f5f9; padding: 3px 6px; border-radius: 6px; text-align: center; white-space: nowrap;'>" . __('system.master_account_claim.total') . ": {$unassignedData['total']}</span>
                        <span style='font-size: 10px; font-weight: 600; color: #4BC0C0; background: rgba(75,192,192,0.1); padding: 3px 6px; border-radius: 6px; text-align: center; white-space: nowrap;'>" . __('system.status.live') . ": {$unassignedData['live']}</span>
                        <span style='font-size: 10px; font-weight: 600; color: #FF6384; background: rgba(255,99,132,0.1); padding: 3px 6px; border-radius: 6px; text-align: center; white-space: nowrap;'>" . __('system.status.banned') . ": {$unassignedData['banned']}</span>
                    </div>
                ";
            }

            return "
                <div style='margin-top: 8px; padding-top: 8px; border-top: 1px dashed #e2e8f0;'>
                    <div style='font-size: 9px; font-weight: 700; color: #94a3b8; text-transform: uppercase; letter-spacing: 0.1em; margin-bottom: 4px;'>" . __('system.charts.holders') . "</div>
                    {$rows}
                </div>
            ";
        };

        // Helper: Tính tổng Live/Banned cho một query set (GLOBAL, không lọc theo user đăng nhập)
        // Live = status chứa 'active' HOẶC 'used' (In Use)
        $countGlobalStats = function ($baseQuery) {
            $totalLive = (clone $baseQuery)->where(function ($q) {
                $q->whereJsonContains('status', 'active')
                    ->orWhereJsonContains('status', 'used');
            })->count();
            $totalBanned = (clone $baseQuery)->whereJsonContains('status', 'banned')->count();
            $totalUnassigned = (clone $baseQuery)->whereNull('user_id')->count();
            return [$totalLive, $totalBanned, $totalUnassigned];
        };

        // Truy vấn dữ liệu chi tiết: Tính số account mỗi user nắm, trên từng platform
        $rawLive = "SUM(CASE WHEN JSON_CONTAINS(accounts.status, '\"active\"') OR JSON_CONTAINS(accounts.status, '\"used\"') THEN 1 ELSE 0 END) as live_count";
        $rawBanned = "SUM(CASE WHEN JSON_CONTAINS(accounts.status, '\"banned\"') THEN 1 ELSE 0 END) as banned_count";

        if (DB::getDriverName() === 'sqlite') {
            $rawLive = "SUM(CASE WHEN accounts.status LIKE '%\"active\"%' OR accounts.status LIKE '%\"used\"%' THEN 1 ELSE 0 END) as live_count";
            $rawBanned = "SUM(CASE WHEN accounts.status LIKE '%\"banned\"%' THEN 1 ELSE 0 END) as banned_count";
        }

        $userPlatformData = Account::query()
            ->join('users', 'accounts.user_id', '=', 'users.id')
            ->select(
                'accounts.platform',
                'accounts.user_id',
                'users.name as user_name',
                DB::raw('COUNT(*) as total_count'),
                DB::raw($rawLive),
                DB::raw($rawBanned),
            )
            ->whereNotNull('accounts.user_id')
            ->groupBy('accounts.platform', 'accounts.user_id', 'users.name')
            ->orderBy('users.name')
            ->get();

        // Lấy TẤT CẢ users (ngoại trừ Finance vì họ không vận hành)
        $allUsers = User::whereNot('role', 'finance')->orderBy('name')->get();

        // Lấy danh sách tất cả các nền tảng hiện có
        $platforms = Account::select('platform')
            ->distinct()
            ->whereNotNull('platform')
            ->pluck('platform');

        // === THẺ ĐẦU TIÊN: ALL PLATFORMS ===
        $totalSystem = Account::count();

        if ($isAdmin) {
            // Admin: hiển thị tổng GLOBAL
            [$globalLive, $globalBanned, $globalUnassigned] = $countGlobalStats(Account::query());

            $rawLive = "SUM(CASE WHEN JSON_CONTAINS(status, '\"active\"') OR JSON_CONTAINS(status, '\"used\"') THEN 1 ELSE 0 END) as live_count";
            $rawBanned = "SUM(CASE WHEN JSON_CONTAINS(status, '\"banned\"') THEN 1 ELSE 0 END) as banned_count";

            if (DB::getDriverName() === 'sqlite') {
                $rawLive = "SUM(CASE WHEN status LIKE '%\"active\"%' OR status LIKE '%\"used\"%' THEN 1 ELSE 0 END) as live_count";
                $rawBanned = "SUM(CASE WHEN status LIKE '%\"banned\"%' THEN 1 ELSE 0 END) as banned_count";
            }

            $allUsersGlobalData = Account::query()
                ->join('users', 'accounts.user_id', '=', 'users.id')
                ->select(
                    'accounts.user_id',
                    DB::raw('COUNT(*) as total_count'),
                    DB::raw($rawLive),
                    DB::raw($rawBanned),
                )
                ->whereNotNull('accounts.user_id')
                ->groupBy('accounts.user_id')
                ->get()
                ->keyBy('user_id');

            $globalHtml = $buildSummaryHtml($globalLive, $globalBanned, $globalUnassigned);
            $globalHtml .= $buildUserDetailHtml($allUsers, $allUsersGlobalData, [
                'total' => $globalUnassigned,
                'live' => max(0, $globalLive - $allUsersGlobalData->sum('live_count')),
                'banned' => max(0, $globalBanned - $allUsersGlobalData->sum('banned_count'))
            ]);
        } else {
            // Staff: hiển thị số liệu cá nhân
            $userId = auth()->id();
            $user = auth()->user();

            // Lấy số liệu cá nhân và số liệu unassigned toàn hệ thống
            [$myLive, $myBanned, $ignore] = $countGlobalStats(Account::where('user_id', $userId));
            [$sysLive, $sysBanned, $totalUnassigned] = $countGlobalStats(Account::query());

            $globalHtml = $buildSummaryHtml($myLive, $myBanned, $totalUnassigned);

            // Chỉ liệt kê chính user này bên dưới giống Admin
            $personalDataMap = collect([
                $userId => (object) [
                    'total_count' => Account::where('user_id', $userId)->count(),
                    'live_count' => $myLive,
                    'banned_count' => $myBanned,
                ]
            ]);

            // Hiển thị row chi tiết của mình + box unassigned (nếu có)
            $globalHtml .= $buildUserDetailHtml([$user], $personalDataMap, [
                'total' => $totalUnassigned,
                'live' => Account::whereNull('user_id')->where(fn($q) => $q->whereJsonContains('status', 'active')->orWhereJsonContains('status', 'used'))->count(),
                'banned' => Account::whereNull('user_id')->whereJsonContains('status', 'banned')->count(),
            ]);
        }

        $stats[] = Stat::make(__('system.total_accounts'), $totalSystem)
            ->description(new \Illuminate\Support\HtmlString($globalHtml))
            ->color('gray');

        // === CÁC THẺ TỪNG PLATFORM ===
        foreach ($platforms as $platform) {
            $query = Account::where('platform', $platform);
            $total = (clone $query)->count();

            if ($isAdmin) {
                // Admin: hiển thị tổng GLOBAL cho platform này
                [$platformLive, $platformBanned, $platformUnassigned] = $countGlobalStats(clone $query);
                $platformHtml = $buildSummaryHtml($platformLive, $platformBanned, $platformUnassigned);

                // Tạo data map cho platform này từ userPlatformData
                $platformDataMap = $userPlatformData
                    ->where('platform', $platform)
                    ->keyBy('user_id');
                $platformHtml .= $buildUserDetailHtml($allUsers, $platformDataMap, [
                    'total' => $platformUnassigned,
                    'live' => max(0, $platformLive - $platformDataMap->sum('live_count')),
                    'banned' => max(0, $platformBanned - $platformDataMap->sum('banned_count'))
                ]);
            } else {
                // Staff: hiển thị số liệu cá nhân cho platform này
                $userId = auth()->id();
                $user = auth()->user();

                [$myLive, $myBanned, $ignore] = $countGlobalStats(Account::where('platform', $platform)->where('user_id', $userId));
                [$pltLive, $pltBanned, $available] = $countGlobalStats(clone $query);

                $platformHtml = $buildSummaryHtml($myLive, $myBanned, $available);

                // Chi tiết cá nhân
                $personalPltDataMap = collect([
                    $userId => (object) [
                        'total_count' => Account::where('platform', $platform)->where('user_id', $userId)->count(),
                        'live_count' => $myLive,
                        'banned_count' => $myBanned,
                    ]
                ]);

                $platformHtml .= $buildUserDetailHtml([$user], $personalPltDataMap, [
                    'total' => $available,
                    'live' => Account::where('platform', $platform)->whereNull('user_id')->where(fn($q) => $q->whereJsonContains('status', 'active')->orWhereJsonContains('status', 'used'))->count(),
                    'banned' => Account::where('platform', $platform)->whereNull('user_id')->whereJsonContains('status', 'banned')->count(),
                ]);
            }

            $displayName = $platformNames[$platform] ?? $platform;

            $stats[] = Stat::make(strtoupper($displayName), $total)
                ->description(new \Illuminate\Support\HtmlString($platformHtml))
                ->color('primary');
        }

        return $stats;
    }
}

