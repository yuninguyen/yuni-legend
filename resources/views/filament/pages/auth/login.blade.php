<x-filament-panels::page.simple>
    {{-- Custom Login Styles --}}
    <style>
        /* ========== LOGIN PAGE OVERRIDES ========== */

        /* Full-screen dark gradient background */
        .fi-simple-layout {
            background: linear-gradient(135deg, #0F172A 0%, #1E293B 50%, #0F172A 100%) !important;
            min-height: 100vh !important;
            position: relative;
        }

        /* Dot grid pattern overlay */
        .fi-simple-layout::before {
            content: '';
            position: fixed;
            inset: 0;
            background-image: radial-gradient(circle at 1px 1px, rgba(255,255,255,0.03) 1px, transparent 0);
            background-size: 32px 32px;
            pointer-events: none;
            z-index: 0;
        }

        /* Animated gradient orb (top-right) */
        .fi-simple-layout::after {
            content: '';
            position: fixed;
            top: -20%;
            right: -10%;
            width: 500px;
            height: 500px;
            background: radial-gradient(circle, rgba(245, 158, 11, 0.08) 0%, transparent 70%);
            border-radius: 50%;
            pointer-events: none;
            z-index: 0;
            animation: float-orb 8s ease-in-out infinite;
        }

        @keyframes float-orb {
            0%, 100% { transform: translate(0, 0) scale(1); }
            50% { transform: translate(-30px, 20px) scale(1.05); }
        }

        /* Login card styling — Ensure intermediate containers are transparent */
        .fi-simple-main {
            position: relative;
            z-index: 1;
            background: transparent !important;
        }

        .fi-simple-main-ctn {
            max-width: 480px !important;
            background: transparent !important;
            box-shadow: none !important;
            border: none !important;
        }

        .fi-simple-page {
            background: rgba(255, 255, 255, 0.97) !important;
            backdrop-filter: blur(20px) !important;
            border-radius: 16px !important;
            border: 1px solid rgba(226, 232, 240, 0.5) !important;
            box-shadow:
                0 25px 50px -12px rgba(0, 0, 0, 0.3),
                0 0 0 1px rgba(255, 255, 255, 0.05),
                0 0 80px rgba(245, 158, 11, 0.04) !important;
            padding: 2rem !important;
            animation: card-appear 0.4s ease-out;
        }

        @keyframes card-appear {
            from { opacity: 0; transform: translateY(12px) scale(0.98); }
            to { opacity: 1; transform: translateY(0) scale(1); }
        }

        /* Hide DEFAULT Filament brand logo — we render our own */
        .fi-simple-layout .fi-logo {
            display: none !important;
        }

        /* Heading */
        .fi-simple-header-heading {
            font-size: 18px !important;
            font-weight: 600 !important;
            color: #0F172A !important;
        }

        .fi-simple-header-subheading {
            font-size: 14px !important;
            color: #64748B !important;
        }

        /* Form inputs */
        .fi-simple-page .fi-input {
            border-radius: 10px !important;
            padding: 0.625rem 0.875rem !important;
            border-color: #E2E8F0 !important;
            font-size: 14px !important;
            transition: all 200ms ease !important;
        }

        .fi-simple-page .fi-input:focus {
            border-color: #F59E0B !important;
            box-shadow: 0 0 0 3px rgba(245, 158, 11, 0.12) !important;
        }

        /* Login button */
        .fi-simple-page .fi-btn {
            border-radius: 10px !important;
            padding: 0.7rem 1.5rem !important;
            font-weight: 600 !important;
            font-size: 14px !important;
            transition: all 200ms ease !important;
        }

        .fi-simple-page .fi-btn:hover {
            transform: translateY(-1px) !important;
            box-shadow: 0 4px 12px rgba(245, 158, 11, 0.3) !important;
        }

        .fi-simple-page .fi-btn:active {
            transform: translateY(0) scale(0.99) !important;
        }

        /* Footer text */
        .fi-simple-footer {
            position: relative;
            z-index: 1;
        }

        .fi-simple-footer p {
            color: #475569 !important;
            font-size: 12px !important;
        }

        /* Labels */
        .fi-simple-page label {
            font-size: 13px !important;
            font-weight: 500 !important;
            color: #334155 !important;
        }
    </style>

    {{-- Custom branded heading --}}
    <x-slot name="heading">
        <div style="text-align: center; margin-bottom: 0.25rem;">
            <div style="display: flex; align-items: center; justify-content: center; gap: 8px; margin-bottom: 0.5rem;">
                <div style="width: 32px; height: 32px; background: linear-gradient(135deg, #F59E0B, #D97706); border-radius: 8px; display: flex; align-items: center; justify-content: center;">
                    <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2L2 7l10 5 10-5-10-5z"/><path d="M2 17l10 5 10-5"/><path d="M2 12l10 5 10-5"/></svg>
                </div>
                <span style="font-size: 22px; font-weight: 800; color: #0F172A; letter-spacing: -0.03em;">RebateOps</span>
            </div>
        </div>
        {{ __('system.auth.login_title') }}
    </x-slot>

    <x-slot name="subheading">
        {{ __('system.auth.login_subtitle') }}
    </x-slot>

    {{-- Filament form + action buttons rendered by Livewire --}}
    <x-filament-panels::form wire:submit="authenticate">
        {{ $this->form }}

        <x-filament-panels::form.actions
            :actions="$this->getCachedFormActions()"
            :full-width="$this->hasFullWidthFormActions()"
        />
    </x-filament-panels::form>

</x-filament-panels::page.simple>
