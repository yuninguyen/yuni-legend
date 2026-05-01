<?php
// app/Filament/Pages/Dashboard.php

namespace App\Filament\Pages;

use Filament\Pages\Dashboard as BaseDashboard;

class Dashboard extends BaseDashboard
{
    public function getColumns(): int|string|array
    {
        return 2;
    }

    // 🟢 Branded heading with Logo
    public function getHeading(): \Illuminate\Contracts\Support\Htmlable
    {
        $hour = (int) now()->format('H');
        $greeting = match (true) {
            $hour < 12 => __('system.greetings.morning'),
            $hour < 18 => __('system.greetings.afternoon'),
            default => __('system.greetings.evening'),
        };

        $name = auth()->user()?->name ?? __('system.n/a');

        return new \Illuminate\Support\HtmlString('
            <div style="margin-bottom: 4px;">
                <h5 style="font-size: 26px; font-weight: 800; color: #0F172A; letter-spacing: -0.025em; line-height: 1.1;">
                    <span style="color: #64748B; font-weight: 600;">Rebate</span><span style="color: #F59E0B; font-weight: 800;">Ops</span> — ' . "{$greeting}, {$name} 👋" . '
                </h5>
                <p style="font-size: 15px; font-weight: 500; color: #64748B; margin-top: 6px;">' . __('system.greetings.operations_overview') . '</p>
            </div>
        ');
    }

    public function getSubheading(): ?string
    {
        return null;
    }

    public function getWidgets(): array
    {
        return [
            // Row 0: Summary Payout Stats
            \App\Filament\Widgets\PayoutStats::class,

            // Row 2: Revenue Report Matrix
            \App\Filament\Widgets\UserPlatformMatrixTable::class,

            // Row 3: Payroll Table (Down to Bottom)
            \App\Filament\Widgets\AdminUserEarningsTable::class,

            // Row 4: Operations Charts (New Top Priority)
            \App\Filament\Widgets\EmailStatusChart::class,
            \App\Filament\Widgets\AccountPlatformChart::class,

            // Row 5: Account Details
            \App\Filament\Widgets\AccountOverview::class,


        ];
    }
}
