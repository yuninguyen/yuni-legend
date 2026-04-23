<?php

namespace App\Providers\Filament;

use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Pages;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\Widgets;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;
use Filament\Support\Facades\FilamentView;
use Illuminate\Support\Facades\Blade;
use Filament\Navigation\NavigationGroup;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->databaseNotifications()
            ->path('admin')
            ->login(\App\Filament\Pages\Auth\Login::class)

            // 🎨 BRANDING
            ->brandName('RebateOps')
            ->brandLogo(
                fn() => file_exists(public_path('images/logo.png'))
                ? asset('images/logo.png')
                : new \Illuminate\Support\HtmlString('
                    <div style="display: flex; align-items: center; justify-content: flex-start; gap: 10px;">
                        <div style="width: 32px; height: 32px; background: linear-gradient(135deg, #FBBF24 0%, #F59E0B 50%, #D97706 100%); border-radius: 8px; display: flex; align-items: center; justify-content: center; box-shadow: 0 2px 6px rgba(245,158,11,0.35), inset 0 1px 1px rgba(255,255,255,0.15); flex-shrink: 0;">
                            <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display: block; filter: drop-shadow(0 1px 1px rgba(0,0,0,0.15));"><path d="M12 2L2 7l10 5 10-5-10-5z"/><path d="M2 17l10 5 10-5"/><path d="M2 12l10 5 10-5"/></svg>
                        </div>
                        <div style="display: flex; align-items: baseline; gap: 0; line-height: 1;">
                            <span style="font-size: 17px; font-weight: 600; color: #CBD5E1; letter-spacing: -0.02em; font-family: Inter, sans-serif;">Rebate</span><span style="font-size: 17px; font-weight: 800; color: #FBBF24; letter-spacing: -0.02em; font-family: Inter, sans-serif;">Ops</span>
                        </div>
                    </div>
                ')
            )
            ->brandLogoHeight('32px')
            ->favicon(asset('favicon.ico'))

            ->sidebarCollapsibleOnDesktop()

            // 🎨 COLOR PALETTE — Refined tones
            ->font('Plus Jakarta Sans')
            ->colors([
                'primary' => Color::Amber,
                'success' => Color::Emerald,
                'danger' => Color::Red,
                'warning' => Color::Orange,
                'info' => Color::Sky,
                'gray' => Color::Slate,
            ])

            // 📦 RESOURCES & PAGES
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\\Filament\\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\\Filament\\Pages')
            ->pages([
                \App\Filament\Pages\Dashboard::class,
            ])
            ->resources([
                config('filament-logger.activity_resource')
            ])

            // 📊 WIDGETS
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\\Filament\\Widgets')
            ->widgets([
                Widgets\AccountWidget::class,
                \App\Filament\Widgets\EmailStatusChart::class,
                Widgets\FilamentInfoWidget::class,
                \App\Filament\Widgets\PayoutStats::class,
                \App\Filament\Widgets\AdminUserEarningsTable::class,
            ])

            // 🗂️ SIDEBAR GROUP ORDERING (Strict machine-name keys)
            ->navigationGroups([
                'resource_hub' => NavigationGroup::make()
                    ->label(__('system.nav.resource_hub')),
                'working_space' => NavigationGroup::make()
                    ->label(__('system.nav.working_space')),
                'wallet_payout' => NavigationGroup::make()
                    ->label(__('system.nav.wallet_payouts')),
                'settings' => NavigationGroup::make()
                    ->label(__('system.nav.settings')),
                'logs' => NavigationGroup::make()
                    ->label(__('system.nav.logs')),
            ])

            // 🔒 MIDDLEWARE
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                VerifyCsrfToken::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
            ])

            // 🎨 THEME CSS — Inject professional stylesheet
            ->renderHook(
                'panels::head.start',
                fn(): string => '<meta name="robots" content="noindex, nofollow">'
            )
            ->renderHook(
                'panels::styles.after',
                fn(): string => Blade::render('
                    <link rel="preconnect" href="https://fonts.googleapis.com">
                    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
                    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
                    <link rel="stylesheet" href="' . asset('css/filament-theme.css') . '?v=' . time() . '">
                '),
            );
    }

    public function boot(): void
    {
        // 🟢 CLIPBOARD POLYFILL (Giữ lại JS thiết yếu duy nhất)
        FilamentView::registerRenderHook(
            'panels::body.end',
            fn(): string => '
            <script>
            // Clipboard polyfill for HTTP
            if (!navigator.clipboard) {
                navigator.clipboard = {
                    writeText: function(text) {
                        return new Promise((resolve, reject) => {
                            try {
                                const el = document.createElement("textarea");
                                el.value = text;
                                el.style.position = "fixed";
                                el.style.opacity = "0";
                                document.body.appendChild(el);
                                el.select();
                                const success = document.execCommand("copy");
                                document.body.removeChild(el);
                                if (success) resolve(); else reject();
                            } catch (err) { reject(err); }
                        });
                    }
                };
            }
            window.addEventListener("copy-to-clipboard", event => {
                if (navigator.clipboard && navigator.clipboard.writeText) {
                    navigator.clipboard.writeText(event.detail.text);
                } else {
                    const el = document.createElement("textarea");
                    el.value = event.detail.text;
                    document.body.appendChild(el);
                    el.select();
                    document.execCommand("copy");
                    document.body.removeChild(el);
                }
            });
            </script>
            ',
        );
    }
}
