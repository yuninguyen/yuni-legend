<?php

namespace App\Filament\Widgets;

use App\Models\Account;
use App\Models\User;
use Filament\Widgets\ChartWidget;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Facades\DB;

class AccountPlatformChart extends ChartWidget
{
    public static function canView(): bool
    {
        return !auth()->user()?->isFinance();
    }
    public function getHeading(): ?string
    {
        if ($this->selectedUser && $this->selectedPlatform) {
            return "{$this->selectedUser} > {$this->selectedPlatform} (" . __('system.charts.platform_dist_title') . ")";
        }
        return __('system.charts.platform_dist_title');
    }
    protected int|string|array $columnSpan = 1;
    protected static ?int $sort = 2;
    protected static ?string $maxHeight = '300px';

    public ?string $selectedUser = null;
    public ?string $selectedPlatform = null;

    // Danh sách màu
    protected static array $myColors = ['#36A2EB', '#FF6384', '#FFCE56', '#4BC0C0', '#9966FF', '#FF9F40'];
    protected static array $statusColors = ['#10b981', '#f43f5e']; // Emerald-500 (Live), Rose-500 (Banned)

    public function onUserClicked(?string $userName): void
    {
        $this->selectedUser = ($this->selectedUser === $userName) ? null : $userName;
        $this->selectedPlatform = null; // Reset platform khi đổi user
    }

    public function onPlatformClicked(?string $platformName): void
    {
        $this->selectedPlatform = ($this->selectedPlatform === $platformName) ? null : $platformName;
    }

    protected function getData(): array
    {
        $query = Account::query();

        if (!auth()->user()?->isAdmin()) {
            $query->where('user_id', auth()->id());
        } elseif ($this->selectedUser) {
            $query->join('users', 'accounts.user_id', '=', 'users.id')
                ->where('users.name', $this->selectedUser);
        }

        // LEVEL 2: Phân bổ TRẠNG THÁI (Live vs Banned) cho 1 Platform cụ thể của 1 User
        if ($this->selectedUser && $this->selectedPlatform) {
            $query->where('platform', $this->selectedPlatform);

            $liveCount = (clone $query)->where(function ($q) {
                $q->whereJsonContains('status', 'active')->orWhereJsonContains('status', 'used');
            })->count();

            $bannedCount = (clone $query)->whereJsonContains('status', 'banned')->count();

            $total = $liveCount + $bannedCount;
            $livePercent = $total > 0 ? round(($liveCount / $total) * 100, 1) : 0;
            $bannedPercent = $total > 0 ? round(($bannedCount / $total) * 100, 1) : 0;

            $data = collect([
                __('system.status.live') => $liveCount,
                __('system.status.banned') => $bannedCount,
            ]);

            // Lấy tên Platform từ DB cho tiêu đề/label nếu cần
            $platformName = DB::table('platforms')->where('slug', $this->selectedPlatform)->value('name') ?: strtoupper($this->selectedPlatform);

            return [
                'datasets' => [
                    [
                        'data' => $data->values()->toArray(),
                        'backgroundColor' => self::$statusColors,
                        'hoverOffset' => 15,
                    ]
                ],
                'labels' => [
                    __('system.status.live') . " ({$liveCount} - {$livePercent}%)",
                    __('system.status.banned') . " ({$bannedCount} - {$bannedPercent}%)",
                ],
            ];
        }

        // LEVEL 1: Phân bổ NỀN TẢNG (Join để lấy Name có dấu cách)
        $data = $query->join('platforms', 'accounts.platform', '=', 'platforms.slug')
            ->selectRaw('platforms.name as label, count(*) as total')
            ->groupBy('platforms.name')
            ->orderBy('total', 'desc')
            ->pluck('total', 'label');

        $totalSum = $data->sum();

        $labels = $data->map(function ($value, $key) use ($totalSum) {
            $percent = $totalSum > 0 ? round(($value / $totalSum) * 100, 1) : 0;
            return "{$key} ({$percent}%)";
        })->values()->toArray();

        return [
            'datasets' => [
                [
                    'data' => $data->values()->toArray(),
                    'backgroundColor' => array_slice(self::$myColors, 0, $data->count()),
                    'hoverOffset' => 15,
                ]
            ],
            'labels' => $labels,
        ];
    }

    public function getDescription(): ?HtmlString
    {
        $isAdmin = auth()->user()?->isAdmin();

        // Nếu đã chọn User -> hiện chi tiết Platform (drill-down level 2)
        if ($this->selectedUser) {
            return $this->renderUserDetailView();
        }

        $query = Account::query();
        if (!$isAdmin) {
            $query->where('accounts.user_id', auth()->id());
        }
        $totalAccounts = (clone $query)->count();

        // Lấy số liệu tổng quan hệ thống để hiển thị ở header (giúp cân bằng chiều cao với widget bên cạnh)
        $totalGlobalLive = (clone $query)->where(function ($q) {
            $q->whereJsonContains('status', 'active')->orWhereJsonContains('status', 'used');
        })->count();
        $totalGlobalBanned = (clone $query)->whereJsonContains('status', 'banned')->count();

        // 1. Phần tổng quan Header
        $html = "
        <div class='es-widget-wrapper'>
            <div class='es-header-row'>
                <div style='font-size: 15px; color: #64748b; cursor: pointer; white-space: nowrap;' wire:click=\"onUserClicked(null)\">
                    " . __('system.charts.total_accounts') . ": <span style='color: #1e293b; font-weight: 800;'>{$totalAccounts}</span>
                </div>
                
                <div style='display: flex; align-items: center; gap: 10px; font-size: 13px; text-transform: uppercase;'>
                    <div style='display: flex; align-items: center; gap: 4px;'>
                        <div style='width: 7px; height: 7px; background: #4BC0C0; border-radius: 50%;'></div>
                        <span style='font-weight: 700; color: #4BC0C0;'>" . __('system.status.live') . ": <span style='color: #1e293b;'>{$totalGlobalLive}</span></span>
                    </div>
                    <span style='color: #cbd5e1;'>|</span>
                    <div style='display: flex; align-items: center; gap: 4px;'>
                        <div style='width: 7px; height: 7px; background: #FF6384; border-radius: 50%;'></div>
                        <span style='font-weight: 700; color: #FF6384;'>" . __('system.status.banned') . ": <span style='color: #1e293b;'>{$totalGlobalBanned}</span></span>
                    </div>
                </div>
            </div>
        ";

        // 2. Chi tiết User breakdown (Admin thấy tất cả, Staff chỉ thấy chính mình — clickable)
        $currentUser = auth()->user();
        $allUsers = $isAdmin
            ? User::whereNot('role', 'finance')->orderBy('name')->get()
            : collect([$currentUser]);

        if (true) {
            $rawLive = "SUM(CASE WHEN JSON_CONTAINS(status, '\"active\"') OR JSON_CONTAINS(status, '\"used\"') THEN 1 ELSE 0 END) as live_count";
            $rawBanned = "SUM(CASE WHEN JSON_CONTAINS(status, '\"banned\"') THEN 1 ELSE 0 END) as banned_count";

            if (DB::getDriverName() === 'sqlite') {
                $rawLive = "SUM(CASE WHEN status LIKE '%\"active\"%' OR status LIKE '%\"used\"%' THEN 1 ELSE 0 END) as live_count";
                $rawBanned = "SUM(CASE WHEN status LIKE '%\"banned\"%' THEN 1 ELSE 0 END) as banned_count";
            }

            $accountCounts = Account::query()
                ->select(
                    'user_id',
                    DB::raw('COUNT(*) as total_count'),
                    DB::raw($rawLive),
                    DB::raw($rawBanned),
                )
                ->whereNotNull('user_id')
                ->groupBy('user_id')
                ->get()
                ->keyBy('user_id');

            $rows = '';
            foreach ($allUsers as $user) {
                $name = e($user->name);
                $initial = strtoupper(mb_substr($name, 0, 1));
                $data = $accountCounts->get($user->id);
                $uTotal = $data->total_count ?? 0;
                $uLive = $data->live_count ?? 0;
                $uBanned = $data->banned_count ?? 0;

                $rows .= "
                    <div class='es-holder-row' wire:click=\"onUserClicked('{$name}')\">
                        <div class='es-initials-circle'>
                            <span class='es-initials-text'>{$initial}</span>
                        </div>
                        <span class='es-name' style='font-size: 12px; font-weight: 600; color: #334155; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;'>{$name}</span>
                        
                        <div class='es-stats-group'>
                            <span class='es-stat-badge' style='color: #475569; background: #f1f5f9;'>" . __('system.master_account_claim.total') . ": {$uTotal}</span>
                            <span class='es-stat-badge' style='color: #4BC0C0; background: rgba(75,192,192,0.1);'>" . __('system.status.live') . ": {$uLive}</span>
                            <span class='es-stat-badge' style='color: #FF6384; background: rgba(255,99,132,0.1);'>" . __('system.status.banned') . ": {$uBanned}</span>
                        </div>
                    </div>
                ";
            }

            // Tính toán Unassigned Accounts: Lấy tổng hệ thống trừ đi tổng của các users đã được liệt kê
            $assignedTotal = $accountCounts->sum('total_count');
            $assignedLive = $accountCounts->sum('live_count');
            $assignedBanned = $accountCounts->sum('banned_count');

            $unassignedTotal = max(0, $totalAccounts - $assignedTotal);

            if ($unassignedTotal > 0) {
                $uLive = max(0, $totalGlobalLive - $assignedLive);
                $uBanned = max(0, $totalGlobalBanned - $assignedBanned);

                $rows .= "
                    <div class='es-holder-row' style='border-top: 1px solid #f1f5f9; margin-top: 2px; cursor: default; background: transparent !important;'>
                        <div style='width: 24px; height: 24px; background: #94a3b8; border-radius: 50%; display: flex; align-items: center; justify-content: center; flex-shrink: 0;'>
                            <span style='font-size: 10px; font-weight: 700; color: white;'>?</span>
                        </div>
                        <span style='font-size: 12px; font-weight: 600; color: #64748b; font-style: italic;'>" . __('system.charts.unassigned') . "</span>
                        
                        <div class='es-stats-group'>
                            <span class='es-stat-badge' style='color: #475569; background: #f1f5f9;'>" . __('system.master_account_claim.total') . ": {$unassignedTotal}</span>
                            <span class='es-stat-badge' style='color: #4BC0C0; background: rgba(75,192,192,0.1);'>" . __('system.status.live') . ": {$uLive}</span>
                            <span class='es-stat-badge' style='color: #FF6384; background: rgba(255,99,132,0.1);'>" . __('system.status.banned') . ": {$uBanned}</span>
                        </div>
                    </div>
                ";
            }

            $html .= "
                <div class='es-holders-container'>
                    <div class='es-holder-label'>" . __('system.charts.holders') . " <span style='font-size: 8px; color: #cbd5e1;'>" . __('system.charts.click_to_filter') . "</span></div>
                    {$rows}
                </div>
            ";
        }

        $html .= "</div>";

        return new HtmlString($html);
    }

    /**
     * View chi tiết khi Admin click vào 1 user cụ thể
     */
    private function renderUserDetailView(): HtmlString
    {
        $platforms = Account::query()
            ->join('users', 'accounts.user_id', '=', 'users.id')
            ->join('platforms', 'accounts.platform', '=', 'platforms.slug')
            ->where('users.name', $this->selectedUser)
            ->selectRaw('platforms.slug, platforms.name, count(*) as total')
            ->groupBy('platforms.slug', 'platforms.name')
            ->get();

        $userTotal = $platforms->sum('total');
        $platformHtml = $platforms->map(function ($p, $index) {
            $color = self::$myColors[$index % count(self::$myColors)];
            $isActive = $this->selectedPlatform === $p->slug;
            $activeStyle = $isActive ? "background: #fff; border: 2px solid {$color}; box-shadow: 0 4px 6px -1px rgb(0 0 0 / 0.1);" : "background: #f8fafc; border: 1px solid #e2e8f0; opacity: 0.8;";

            return "
                <div wire:click=\"onPlatformClicked('{$p->slug}')\" 
                     style='display: flex; flex-direction: column; cursor: pointer; padding: 6px 12px; border-radius: 10px; transition: all 0.2s; min-width: 80px; {$activeStyle}'
                     onmouseover=\"this.style.opacity='1'; this.style.transform='translateY(-2px)'\" 
                     onmouseout=\"if(!{$isActive}) {this.style.opacity='0.8'; this.style.transform='none'}\">
                    <div style='display: flex; align-items: center; gap: 4px;'>
                        <div style='width: 8px; height: 8px; background: {$color}; border-radius: 50%;'></div>
                        <span style='font-size: 11px; font-weight: 700; color: " . ($isActive ? "#1e293b" : "#64748b") . " ;'>{$p->name}</span>
                    </div>
                    <div style='padding-left: 12px; font-size: 14px; font-weight: 800; color: " . ($isActive ? $color : "#111827") . ";'>{$p->total}</div>
                </div>";
        })->implode("");

        $selectedPlatformName = $this->selectedPlatform ? (DB::table('platforms')->where('slug', $this->selectedPlatform)->value('name') ?: $this->selectedPlatform) : null;

        return new HtmlString("
            <div class='mt-2 space-y-4'>
                <div style='display: flex; align-items: center; gap: 10px; margin-bottom: 8px;'>
                    <button wire:click=\"onUserClicked(null)\" style='padding: 2px 8px; background: #f1f5f9; border-radius: 6px; font-size: 11px; font-weight: bold; color: #475569; cursor: pointer; border: 1px solid #e2e8f0;'>← " . __('system.charts.back') . "</button>
                    <span style='font-size: 15px; font-weight: 700; color: #0f172a;'>{$this->selectedUser} <span style='font-weight: 400; color: #64748b; font-size: 13px;'>({$userTotal} " . __('system.charts.total_accounts') . ")</span></span>
                </div>
                <div style='display: flex; gap: 12px; flex-wrap: wrap; padding-bottom: 15px;'>{$platformHtml}</div>
                " . ($this->selectedPlatform ? "<div style='font-size: 11px; font-weight: bold; text-transform: uppercase; color: #64748b; border-top: 1px dashed #e2e8f0; padding-top: 10px;'>" . __('system.status.state') . ": " . strtoupper($selectedPlatformName) . "</div>" : "") . "
            </div>
        ");
    }

    protected function getType(): string
    {
        return 'doughnut';
    }

    protected function getOptions(): array
    {
        return [
            'plugins' => [
                'legend' => [
                    'display' => true,
                    'position' => 'bottom',
                ],
                'tooltip' => [
                    'enabled' => true,
                    'mode' => 'index',
                    'intersect' => true,
                ],
                'datalabels' => [
                    'display' => true,
                    'color' => '#fff',
                    'font' => ['weight' => 'bold', 'size' => 11],
                    'formatter' => "function(value, context) {
                        let sum = context.dataset.data.reduce((a, b) => a + b, 0);
                        return Math.round((value / sum) * 100) + '%';
                    }",
                ],
            ],
        ];
    }
}
