<?php

namespace App\Providers;

use App\Models\Brand;
use App\Models\Email;
use App\Models\PayoutLog;
use App\Models\PayoutMethod;
use App\Models\RebateTracker;
use App\Models\UserPayment;
use App\Models\Account;
use App\Policies\AccountPolicy;
use App\Policies\BrandPolicy;
use App\Policies\EmailPolicy;
use App\Policies\PayoutLogPolicy;
use App\Policies\PayoutMethodPolicy;
use App\Policies\RebateTrackerPolicy;
use App\Policies\UserPaymentPolicy;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Spatie\Activitylog\Models\Activity;
use BezhanSalleh\FilamentLanguageSwitch\LanguageSwitch;
use Filament\Support\Facades\FilamentView;
use Filament\View\PanelsRenderHook;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\URL;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        if (config('app.env') === 'production') {
            URL::forceScheme('https');
        }

        LanguageSwitch::configureUsing(function (LanguageSwitch $switch) {
            $switch->locales(['en', 'vi']);
        });

        // 🟢 TIÊU CHUẨN HÓA TIỀN TỆ: Ép hệ thống dùng chuẩn tiếng Anh (USD -> $) bất kể ngôn ngữ giao diện là gì
        \Illuminate\Support\Number::useLocale('en_US');

        // Đăng ký Policy tại đây
        Gate::policy(Account::class, AccountPolicy::class);
        Gate::policy(Brand::class, BrandPolicy::class);
        Gate::policy(Email::class, EmailPolicy::class);
        Gate::policy(PayoutLog::class, PayoutLogPolicy::class);
        Gate::policy(PayoutMethod::class, PayoutMethodPolicy::class);
        Gate::policy(RebateTracker::class, RebateTrackerPolicy::class);
        Gate::policy(UserPayment::class, UserPaymentPolicy::class);
        // 🟢 KHÓA CHẶT: Chỉ cho phép Admin xem bất cứ thứ gì liên quan đến Activity Log
        Gate::policy(Activity::class, \App\Policies\ActivityPolicy::class);


        // Đăng ký Observer tại đây
        \App\Models\PayoutLog::observe(\App\Observers\PayoutLogObserver::class);
        \App\Models\RebateTracker::observe(\App\Observers\RebateTrackerObserver::class);
        \App\Models\PayoutMethod::observe(\App\Observers\PayoutMethodObserver::class);
        \App\Models\Email::observe(\App\Observers\EmailObserver::class);

        // 🟢 THÊM NÚT BACK TO TOP: Tự động xuất hiện khi cuộn trang xuống
        FilamentView::registerRenderHook(
            PanelsRenderHook::BODY_END,
            fn(): string => Blade::render('
                <div id="back-to-top" title="Back to Top">
                    <svg fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 10l7-7m0 0l7 7m-7-7v18"></path>
                    </svg>
                </div>
                <script>
                    const backToTop = document.getElementById("back-to-top");
                    window.addEventListener("scroll", () => {
                        if (window.pageYOffset > 300) {
                            backToTop.style.display = "flex";
                        } else {
                            backToTop.style.display = "none";
                        }
                    });
                    backToTop.addEventListener("click", () => {
                        window.scrollTo({ top: 0, behavior: "smooth" });
                    });
                </script>
            '),
        );
    }
}
