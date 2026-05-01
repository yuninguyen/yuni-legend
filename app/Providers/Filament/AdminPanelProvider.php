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
            ->brandLogo(fn () => view('filament.components.brand-logo'))
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
                'Settings' => NavigationGroup::make()
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
                    @include("filament.components.selection-bar-fix")
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
