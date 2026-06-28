<?php

namespace App\Filament\Resources\Traits;

use App\Filament\Resources\RebateTrackerResource;
use App\Models\Account;
use App\Models\Platform;
use App\Models\RebateTracker;
use App\Models\User;
use App\Services\GoogleSyncService;
use Carbon\Carbon;
use Filament\Forms;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Form;
use Filament\Infolists\Components\Section;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Components\Tabs;
use Filament\Infolists\Components\Tabs\Tab;
use Filament\Infolists\Components\Grid;
use Filament\Infolists\Infolist;
use Filament\Notifications\Notification;
use Filament\Support\Enums\Alignment;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Enums\IconPosition;
use Filament\Tables;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Grouping\Group;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\HtmlString;

trait HasTrackerSchema
{
    use HasPlatform;
    use HasPlatformCache;

    // FIX #8: $usStates được kế thừa từ HasUsStates — KHÔNG khai báo lại ở đây.
    use HasUsStates;

    /**
     * Scope lọc các bản ghi đang ở trạng thái có thể rút tiền
     */
    public function scopeWhereReadyForPayout(Builder $query)
    {
        return $query->whereIn('status', ['pending', 'confirmed']);
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                // SECTION 1: Account & Platform
                Forms\Components\Section::make(__('system.account_claim.section_title'))
                    ->schema([
                        Forms\Components\Grid::make(12)
                            ->schema([
                                // 1. USER (Chỉ Admin thấy)
                                Forms\Components\Select::make('user_id')
                                    ->label(__('system.labels.user'))
                                    ->relationship('user', 'name', fn (Builder $query) => $query->where(function (Builder $q) {
                                        $q->whereHas('accounts')->orWhere('id', auth()->id());
                                    }))
                                    ->default(fn () => auth()->id())
                                    ->hidden(fn () => ! auth()->user()?->isAdmin())
                                    ->dehydrated(true)
                                    ->searchable()
                                    ->preload()
                                    ->live()
                                    ->afterStateUpdated(function ($set) {
                                        $set('platform', null);
                                        $set('account_email', null);
                                        $set('account_password_display', null);
                                    })
                                    // Admin chiếm 3 phần, Staff không chiếm phần nào (ẩn)
                                    ->columnSpan(auth()->user()?->isAdmin() ? 6 : 0),

                                // 2. SELECT PLATFORM (Làm nhỏ lại cho Staff)
                                Forms\Components\Select::make('platform')
                                    ->label(__('system.labels.platform'))
                                    ->options(function (Forms\Get $get, $record) {
                                        $userId = $get('user_id') ?? $record?->user_id;
                                        $allPlatforms = self::getPlatforms();
                                        if (! $userId) {
                                            return $allPlatforms;
                                        }

                                        $activePlatformKeys = Account::where('user_id', $userId)
                                            ->whereNotNull('platform')
                                            ->distinct()
                                            ->pluck('platform')
                                            ->toArray();

                                        $filteredPlatforms = [];
                                        foreach ($activePlatformKeys as $key) {
                                            $filteredPlatforms[$key] = $allPlatforms[$key] ?? ucfirst((string) $key);
                                        }

                                        return $filteredPlatforms;
                                    })
                                    ->live()
                                    ->required()
                                    ->afterStateHydrated(function (Forms\Set $set, $state, $record) {
                                        if (blank($state) && $record?->account?->platform) {
                                            $set('platform', $record->account->platform);
                                        }
                                    })
                                    ->afterStateUpdated(function (Forms\Set $set) {
                                        $set('account_email', null);
                                        $set('account_password_display', null);
                                    })
                                    // 🟢 Staff: Chiếm 2/12 | Admin: Chiếm 3/12
                                    ->columnSpan(auth()->user()?->isAdmin() ? 6 : 3),

                                // 3. SELECT EMAIL (Chiếm không gian lớn nhất cho Staff)
                                Forms\Components\Select::make('account_email')
                                    ->label(__('system.labels.select_account_email'))
                                    ->options(function ($get, $record) {
                                        $userId = $get('user_id') ?? $record?->user_id;
                                        $platform = $get('platform') ?? $record?->account?->platform;
                                        if (! $userId || ! $platform) {
                                            return [];
                                        }

                                        return Account::query()
                                            ->where('user_id', $userId)
                                            ->where('platform', $platform)
                                            ->select(['id', 'email_id', 'user_id', 'platform'])
                                            ->with('email:id,email')
                                            ->withSum(['rebateTrackers as pending_amount' => fn ($q) => $q->whereIn('status', ['pending', 'clicked'])], 'rebate_amount')
                                            ->withSum(['rebateTrackers as confirmed_amount' => fn ($q) => $q->whereIn('status', ['confirmed'])], 'rebate_amount')
                                            ->withSum(['payoutLogs as paid_amount' => fn ($q) => $q->whereIn('transaction_type', ['withdrawal', 'hold'])->where('status', 'completed')], 'amount_usd')
                                            ->get()
                                            ->mapWithKeys(function ($account) {
                                                $pending = number_format($account->pending_amount ?? 0, 2);
                                                $paid = number_format($account->paid_amount ?? 0, 2);
                                                $available = number_format(max(0, ($account->confirmed_amount ?? 0) - ($account->paid_amount ?? 0)), 2);
                                                $email = $account->email?->email ?? 'N/A';

                                                return [$email => "{$email} - \${$paid} ➔ [Pending: \${$pending}] - [Confirmed: \${$available}]"];
                                            });
                                    })
                                    ->searchable()
                                    ->preload()
                                    ->live()
                                    ->required()
                                    ->afterStateHydrated(function (Forms\Set $set, $state, $record) {
                                        if (blank($state) && $record?->account?->email?->email) {
                                            $set('account_email', $record->account->email->email);
                                        }
                                    })
                                    ->suffixAction(
                                        Forms\Components\Actions\Action::make('copyEmail')
                                            ->icon('heroicon-m-clipboard-document')
                                            ->color('warning')
                                            ->tooltip(__('system.actions.copy').' Email')
                                            ->action(function (Forms\Get $get, $livewire) {
                                                $email = $get('account_email');

                                                if ($email) {
                                                    // Sử dụng chung công nghệ dispatch copy của Sếp
                                                    $livewire->dispatch('copy-to-clipboard', text: $email);

                                                    Notification::make()
                                                        ->title(__('system.labels.email_copied'))
                                                        ->success()
                                                        ->send();
                                                }
                                            })
                                    )
                                    ->afterStateUpdated(function ($state, $get, $set) {
                                        if (! $state) {
                                            $set('account_id', null);
                                            $set('account_password_display', null);

                                            return;
                                        }
                                        $account = Account::whereHas('email', fn ($q) => $q->where('email', $state))
                                            ->where('user_id', $get('user_id'))
                                            ->where('platform', $get('platform'))
                                            ->first();
                                        if ($account) {
                                            $set('account_id', $account->id);
                                            $set('account_password_display', $account->password);
                                        }
                                    })
                                    // 🟢 Staff: Chiếm 7/12 (Rất rộng) | Admin: Chiếm 9/12
                                    ->columnSpan(auth()->user()?->isAdmin() ? 9 : 7),

                                // 4. SHOW PASSWORD (Làm nhỏ lại cho Staff)
                                Forms\Components\TextInput::make('account_password_display')
                                    ->label(__('system.labels.password'))
                                    ->readonly()
                                    ->password()
                                    ->revealable()
                                    ->dehydrated(false)
                                    ->formatStateUsing(fn ($record) => $record?->account?->password)
                                    ->suffixAction(
                                        Forms\Components\Actions\Action::make('copyPassword')
                                            ->icon('heroicon-m-clipboard-document')
                                            ->color('warning')
                                            ->action(function (Forms\Get $get, $livewire) {
                                                $accountId = $get('account_id');
                                                $password = Account::find($accountId)?->password;

                                                if ($password) {
                                                    $livewire->dispatch('copy-to-clipboard', text: $password);

                                                    Notification::make()
                                                        ->title(__('system.labels.copied'))
                                                        ->success()
                                                        ->send();
                                                }
                                            })
                                    )
                                    // 🟢 Staff: Chiếm 2/12 | Admin: Chiếm 3/12
                                    ->columnSpan(auth()->user()?->isAdmin() ? 3 : 2),
                            ]),

                        // PHẦN HIỂN THỊ TRẠNG THÁI (Giữ nguyên logic của Sếp)
                        Forms\Components\Placeholder::make('account_status_display')
                            ->label(__('system.labels.account_status_tracking'))
                            ->visible(fn ($get) => $get('account_email'))
                            ->content(function ($get) {
                                // ... (Giữ nguyên toàn bộ nội dung HtmlString bên trong của Sếp)
                                $emailState = $get('account_email');
                                // 🟢 FIX: 1 email có thể được dùng cho Account ở NHIỀU platform khác nhau,
                                // nếu chỉ lọc theo email sẽ lấy nhầm Account (và status) của platform khác.
                                $account = Account::whereHas('email', fn ($q) => $q->where('email', $emailState))
                                    ->where('platform', $get('platform'))
                                    ->first();
                                if (! $account) {
                                    return new HtmlString("<div class='text-danger'>⚠️ ".__('system.notifications.no_records_found').'</div>');
                                }
                                $statuses = (array) $account->status;
                                if (empty($statuses)) {
                                    return __('system.n/a');
                                }
                                $htmlResult = collect($statuses)->map(function ($status, $index) use ($statuses) {
                                    $s_lower = strtolower($status);
                                    $color = match ($s_lower) {
                                        'active', 'live' => '#6b7280',
                                        'used', 'in_use' => '#3b82f6',
                                        'no_paypal_needed', 'no_paypal_required' => '#1e3a8a',
                                        'not_linked', 'not_linked_paypal' => '#f59e0b',
                                        'unlinked', 'unlinked_paypal' => '#f59e0b',
                                        'linked', 'linked_paypal' => '#22c55e',
                                        'limited', 'paypal_limited' => '#ef4444',
                                        'banned' => '#ef4444',
                                        default => '#6b7280'
                                    };
                                    $label = match ($s_lower) {
                                        'used', 'in_use' => __('system.status.used'),
                                        'limited', 'paypal_limited' => __('system.status.paypal_limited'),
                                        'linked', 'linked_paypal' => __('system.status.linked_paypal'),
                                        'unlinked', 'unlinked_paypal' => __('system.status.unlinked_paypal'),
                                        'not_linked', 'not_linked_paypal' => __('system.status.not_linked_paypal'),
                                        'no_paypal_needed', 'no_paypal_required' => __('system.status.no_paypal_required'),
                                        'banned' => __('system.status.banned'),
                                        'active', 'live' => __('system.status.active'),
                                        default => __('system.status.'.$s_lower)
                                    };
                                    $arrow = ($index < count($statuses) - 1) ? " <span style='color: #d1d5db; margin: 0 4px;'>→</span> " : '';

                                    return "<span style='color: {$color}; font-weight: 800; font-size: 0.85rem;'>{$label}</span>{$arrow}";
                                })->implode('');

                                return new HtmlString("<div style='padding:12px; background:#f0f9ff; border-radius:8px;'>{$htmlResult}</div>");
                            })
                            ->columnSpanFull(),

                        Forms\Components\Hidden::make('account_id')->required(),
                    ])
                    // 🟢 KẾT QUẢ CUỐI CÙNG: Staff nhìn thấy 3 ô trên 1 hàng ngang (Tỉ lệ 2-8-2)
                    ->columns(auth()->user()?->isAdmin() ? 2 : 1),

                // SECTION 2: Order Details
                Forms\Components\Section::make(__('system.rebate_tracker.order_detail'))
                    ->schema([
                        Forms\Components\Grid::make(3)
                            ->schema([
                                Forms\Components\TextInput::make('transaction_date')
                                    ->label(__('system.labels.transaction_date'))
                                    ->placeholder('dd/mm/yyyy')
                                    ->mask('99/99/9999')
                                    ->rules(['nullable', 'date_format:d/m/Y'])
                                    ->afterStateHydrated(function ($component, $state) {
                                        if ($state) {
                                            $ymd = \Carbon\Carbon::parse($state)->setTimezone(config('app.timezone'))->format('Y-m-d');
                                            [$year, $month, $day] = explode('-', substr($ymd, 0, 10));
                                            $component->state("{$day}/{$month}/{$year}");
                                        }
                                    })
                                    ->dehydrateStateUsing(function ($state) {
                                        if (blank($state)) {
                                            return null;
                                        }

                                        if (! preg_match('#^(\d{2})/(\d{2})/(\d{4})$#', $state, $m)) {
                                            return null;
                                        }

                                        return "{$m[3]}-{$m[2]}-{$m[1]}";
                                    })
                                    ->reactive() // Quan trọng: Để Payout Date có thể nhận diện thay đổi của Transaction Date
                                    ->nullable() // Cho phép để trống
                                    ->columns(5), // Đảm bảo không tự động lấy ngày hiện tại

                                Forms\Components\TextInput::make('payout_date')
                                    ->label(__('system.labels.payout_date'))
                                    ->placeholder('dd/mm/yyyy')
                                    ->mask('99/99/9999')
                                    ->afterStateHydrated(function ($component, $state) {
                                        if ($state) {
                                            $ymd = \Carbon\Carbon::parse($state)->setTimezone(config('app.timezone'))->format('Y-m-d');
                                            [$year, $month, $day] = explode('-', substr($ymd, 0, 10));
                                            $component->state("{$day}/{$month}/{$year}");
                                        }
                                    })
                                    ->dehydrateStateUsing(function ($state) {
                                        if (blank($state)) {
                                            return null;
                                        }

                                        if (! preg_match('#^(\d{2})/(\d{2})/(\d{4})$#', $state, $m)) {
                                            return null;
                                        }

                                        return "{$m[3]}-{$m[2]}-{$m[1]}";
                                    })
                                    // Logic Validation: Phải sau hoặc bằng ngày giao dịch
                                    ->rules([
                                        'nullable',
                                        'date_format:d/m/Y',
                                        fn (Forms\Get $get): \Closure => function (string $attribute, $value, \Closure $fail) use ($get) {
                                            $transactionDate = $get('transaction_date');

                                            if (blank($value) || blank($transactionDate)) {
                                                return;
                                            }

                                            try {
                                                $payout = Carbon::createFromFormat('d/m/Y', $value);
                                                $transaction = Carbon::createFromFormat('d/m/Y', $transactionDate);
                                            } catch (\Exception $e) {
                                                return;
                                            }

                                            if ($payout->lt($transaction)) {
                                                $fail(__('system.notifications.date_updated_sync'));
                                            }
                                        },
                                    ])
                                    ->nullable() // Cho phép để trống
                                    ->columns(5), // Đảm bảo không tự động lấy ngày hiện tại

                                Forms\Components\Select::make('status')
                                    ->label(__('system.labels.status'))
                                    ->options([
                                        'pending' => __('system.status.pending'),
                                        'confirmed' => __('system.status.confirmed'),
                                        'ineligible' => __('system.status.ineligible'),
                                        'missing' => __('system.status.missing'),
                                        'clicked' => __('system.status.clicked'),
                                    ])
                                    ->default('clicked')
                                    ->required(),
                            ]),
                        Forms\Components\Grid::make(5)
                            ->schema([
                                Forms\Components\TextInput::make('store_name')
                                    ->label(__('system.labels.store_name'))
                                    ->required(),

                                Forms\Components\TextInput::make('order_id')
                                    ->label(__('system.labels.order_id')),

                                Forms\Components\TextInput::make('order_value')
                                    ->label(__('system.labels.order_value'))
                                    ->numeric()
                                    ->prefix('$')
                                    // 🟢 Dùng live(onBlur: true) thay cho reactive() để tránh re-render mỗi keystroke
                                    // làm nhảy/lùi số khi đang gõ — chỉ tính lại Rebate Amount khi rời khỏi ô.
                                    ->live(onBlur: true)
                                    ->required()
                                    ->afterStateUpdated(function (Forms\Set $set, Forms\Get $get) {
                                        $total = (float) $get('order_value') * ((float) $get('cashback_percent') / 100);
                                        $set('rebate_amount', round($total, 2));
                                    }),

                                Forms\Components\TextInput::make('cashback_percent')
                                    ->label(__('system.labels.cashback_percent'))
                                    ->numeric()
                                    ->suffix('%')
                                    ->live(onBlur: true)
                                    ->default(10)
                                    ->afterStateUpdated(function (Forms\Set $set, Forms\Get $get) {
                                        $total = (float) $get('order_value') * ((float) $get('cashback_percent') / 100);
                                        $set('rebate_amount', round($total, 2));
                                    }),

                                // 🟢 Auto tính theo Order Value × Cashback % nhưng vẫn cho sửa tay trực tiếp
                                Forms\Components\TextInput::make('rebate_amount')
                                    ->label(__('system.labels.rebate_amount'))
                                    ->numeric()
                                    ->prefix('$')
                                    ->required()
                                    ->extraInputAttributes(['class' => 'text-success font-bold text-xl']),
                            ]),
                    ])->columnSpanFull(),

                // SECTION 3: Logistics
                Forms\Components\Section::make(__('system.labels.note'))
                    ->schema([
                        Forms\Components\Grid::make(2)
                            ->schema([
                                Forms\Components\TextInput::make('device')
                                    ->label(__('system.labels.device'))
                                    ->placeholder('iOS, VMware, BitBrowser Antidetect...'),
                                Forms\Components\Select::make('state')
                                    ->label(__('system.labels.state_us'))
                                    ->searchable()
                                    ->options(self::$usStates),
                            ]),
                        Forms\Components\Textarea::make('note')
                            ->label(__('system.labels.note'))
                            ->columnSpanFull()
                            ->rows(5),
                        Forms\Components\Textarea::make('detail_transaction')
                            ->label(__('system.labels.transaction_details'))
                            ->columnSpanFull()
                            ->rows(5),
                    ])->columnSpanFull(),
            ]);
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist
            ->schema([
                Tabs::make('Tabs')
                    ->tabs([
                        Tab::make(__('system.account_claim.section_title'))
                            ->icon('heroicon-m-envelope')
                            ->schema([
                                Grid::make(2)->schema([
                                    TextEntry::make('account.email.email')
                                        ->label(__('system.labels.email_address'))
                                        ->placeholder('N/A')
                                        ->copyable(),
                                    TextEntry::make('account.password')
                                        ->label(__('system.labels.password'))
                                        ->placeholder('N/A')
                                        ->copyable(),
                                    TextEntry::make('account.platform')
                                        ->label(__('system.labels.platform'))
                                        ->placeholder('N/A')
                                        ->formatStateUsing(fn ($state) => $state ? static::getPlatformName($state) : 'N/A'),
                                    TextEntry::make('user.name')
                                        ->label(__('system.labels.user'))
                                        ->placeholder('N/A'),
                                    TextEntry::make('account.status')
                                        ->label(__('system.labels.account_status_tracking'))
                                        ->html()
                                        ->placeholder('No status history found.')
                                        ->formatStateUsing(function ($state, $record) {
                                            $account = $record->account;
                                            if (! $account || ! $account->status) {
                                                return null;
                                            }

                                            $statusHistory = is_array($account->status)
                                                ? $account->status
                                                : json_decode($account->status, true) ?? [$account->status];

                                            $htmlResult = collect($statusHistory)->map(function ($status, $index) use ($statusHistory) {
                                                $s_lower = strtolower($status);
                                                $color = match ($s_lower) {
                                                    'active', 'live' => '#6b7280',
                                                    'used', 'in_use' => '#3b82f6',
                                                    'no_paypal_needed', 'no_paypal_required' => '#1e3a8a',
                                                    'not_linked', 'not_linked_paypal' => '#f59e0b',
                                                    'unlinked', 'unlinked_paypal' => '#f59e0b',
                                                    'linked', 'linked_paypal' => '#22c55e',
                                                    'limited', 'paypal_limited' => '#ef4444',
                                                    'banned' => '#ef4444',
                                                    default => '#6b7280'
                                                };

                                                $label = match ($s_lower) {
                                                    'used', 'in_use' => __('system.status.used'),
                                                    'limited', 'paypal_limited' => __('system.status.paypal_limited'),
                                                    'linked', 'linked_paypal' => __('system.status.linked_paypal'),
                                                    'unlinked', 'unlinked_paypal' => __('system.status.unlinked_paypal'),
                                                    'not_linked', 'not_linked_paypal' => __('system.status.not_linked_paypal'),
                                                    'no_paypal_needed', 'no_paypal_required' => __('system.status.no_paypal_required'),
                                                    'banned' => __('system.status.banned'),
                                                    'active', 'live' => __('system.status.active'),
                                                    default => __('system.status.'.$s_lower)
                                                };

                                                $isLast = $index === count($statusHistory) - 1;
                                                $arrow = ! $isLast ? " <span style='color: #9ca3af; margin: 0 10px;'>→</span> " : '';

                                                return "<span style='color: {$color}; font-weight: 800; font-size: 0.9rem;'>{$label}</span>{$arrow}";
                                            })->implode('');

                                            return new HtmlString("
                                                <div style='padding: 10px; background: #f9fafb; border: 1px solid #e5e7eb; border-radius: 6px; display: inline-block;'>
                                                    {$htmlResult}
                                                </div>
                                            ");
                                        })
                                        ->columnSpanFull(),
                                ]),
                            ]),

                        Tab::make(__('system.rebate_tracker.order_detail'))
                            ->icon('heroicon-m-shopping-bag')
                            ->schema([
                                Grid::make(3)->schema([
                                    TextEntry::make('transaction_date')
                                        ->label(__('system.labels.transaction_date'))
                                        ->dateTime('d/m/Y')
                                        ->placeholder('N/A'),
                                    TextEntry::make('payout_date')
                                        ->label(__('system.labels.payout_date'))
                                        ->dateTime('d/m/Y')
                                        ->placeholder('N/A'),
                                    TextEntry::make('status')
                                        ->label(__('system.labels.status'))
                                        ->placeholder('N/A')
                                        ->badge()
                                        ->icon(fn (string $state): string => match ($state) {
                                            'clicked' => 'heroicon-m-cursor-arrow-rays',
                                            'pending' => 'heroicon-m-clock',
                                            'confirmed' => 'heroicon-m-check-badge',
                                            'missing' => 'heroicon-m-magnifying-glass',
                                            'ineligible' => 'heroicon-m-x-circle',
                                            default => 'heroicon-m-question-mark-circle',
                                        })
                                        ->formatStateUsing(fn (string $state): string => match ($state) {
                                            'clicked' => __('system.status.clicked'),
                                            'pending' => __('system.status.pending'),
                                            'confirmed' => __('system.status.confirmed'),
                                            'missing' => __('system.status.missing'),
                                            'ineligible' => __('system.status.ineligible'),
                                            default => ucfirst($state),
                                        })
                                        ->color(fn (string $state): string => match ($state) {
                                            'clicked' => 'gray',
                                            'pending' => 'info',
                                            'confirmed' => 'success',
                                            'missing' => 'warning',
                                            'ineligible' => 'danger',
                                            default => 'gray',
                                        }),
                                    TextEntry::make('store_name')
                                        ->label(__('system.labels.store_name'))
                                        ->placeholder(__('system.n/a')),
                                    TextEntry::make('order_id')
                                        ->label(__('system.labels.order_id'))
                                        ->placeholder(__('system.n/a'))
                                        ->copyable(),
                                    TextEntry::make('order_value')
                                        ->label(__('system.labels.order_value'))
                                        ->placeholder(__('system.n/a')),
                                    TextEntry::make('cashback_percent')
                                        ->label(__('system.labels.cashback_percent'))
                                        ->placeholder(__('system.n/a')),
                                    TextEntry::make('rebate_amount')
                                        ->label(__('system.labels.rebate_amount'))
                                        ->money('USD')
                                        ->weight(FontWeight::Bold)
                                        ->color('success'),
                                ]),
                            ]),

                        Tab::make(__('system.labels.note'))
                            ->icon('heroicon-m-document-text')
                            ->schema([
                                Grid::make(2)->schema([
                                    TextEntry::make('device')
                                        ->label(__('system.labels.device'))
                                        ->placeholder(__('system.n/a')),
                                    TextEntry::make('state')
                                        ->label(__('system.labels.state_us'))
                                        ->placeholder(__('system.n/a'))
                                        ->formatStateUsing(fn ($state) => $state ? "{$state} - ".(self::$usStates[$state] ?? '') : 'N/A'),
                                    TextEntry::make('note')
                                        ->label(__('system.labels.note'))
                                        ->placeholder(__('system.n/a'))
                                        ->columnSpanFull()
                                        ->html()
                                        ->formatStateUsing(fn ($state) => $state ? '
                                            <div style="
                                                white-space: pre-wrap;
                                                line-height: 1.6;
                                                margin: 0;
                                                padding: 0;
                                            ">'.e(trim($state)).'</div>' : 'N/A'),
                                    TextEntry::make('detail_transaction')
                                        ->label(__('system.labels.transaction_details'))
                                        ->columnSpanFull()
                                        ->html()
                                        ->formatStateUsing(fn ($state) => $state ? '
                                            <div style="
                                                white-space: pre-wrap;
                                                line-height: 1.6;
                                                margin: 0;
                                                padding: 0;
                                            ">'.e(trim($state)).'</div>' : 'N/A')
                                        ->extraAttributes([
                                            'class' => 'bg-gray-50 p-4 rounded-xl border border-gray-200 shadow-sm transition',
                                            'style' => 'max-height: 300px; overflow-y: auto; line-height: 1.6;',
                                        ])
                                        ->placeholder('No details available'),
                                ]),
                            ]),
                    ])
                    ->columnSpanFull(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['account.email', 'user'])) // 🟢 TỐI ƯU: Tránh N+1
            ->defaultGroup('account_batch_group')
            ->groups([
                Group::make('account_batch_group')
                    ->label(__('system.labels.account_batch_group'))
                    ->collapsible()
                    ->titlePrefixedWithLabel(false)
                    ->orderQueryUsing(function (Builder $query, string $direction) {
                        $table = $query->getModel()->getTable();
                        // <=> là NULL-safe equals trong MySQL/MariaDB
                        $query->orderByRaw("(SELECT MAX(t2.transaction_date) FROM `{$table}` t2 WHERE t2.account_id = `{$table}`.account_id AND t2.batch_id <=> `{$table}`.batch_id) DESC")
                              ->orderBy('account_id', $direction)
                              ->orderBy('batch_id', $direction)
                              ->orderBy('transaction_date', 'desc');
                    })
                    ->getKeyFromRecordUsing(fn ($record) => $record->account_id.'_'.($record->batch_id ?? 'uncategorized'))
                    ->scopeQueryByKeyUsing(function (Builder $query, string $key) {
                        [$accountId, $batchId] = explode('_', $key, 2);

                        $query->where('account_id', $accountId)
                            ->when(
                                $batchId === 'uncategorized',
                                fn (Builder $q) => $q->whereNull('batch_id'),
                                fn (Builder $q) => $q->where('batch_id', $batchId)
                            );
                    })
                    ->getTitleFromRecordUsing(function ($record) {
                        $email = $record->account?->email?->email ?? 'N/A';
                        $platform = static::getPlatformName($record->account?->platform);
                        $batchName = $record->batch_id ? __('system.labels.batch') . ": {$record->batch_id}" : __('system.labels.uncategorized');
                        $totalLabel = __('system.labels.total');

                        $q = RebateTracker::query()
                            ->where('account_id', $record->account_id);
                            
                        if ($record->batch_id) {
                            $q->where('batch_id', $record->batch_id);
                        } else {
                            $q->whereNull('batch_id');
                        }
                        
                        $totalRebate = $q->sum('rebate_amount');
                        $rebateStr = '$'.number_format($totalRebate, 2);

                        return "{$email} | {$platform} | {$batchName} | {$totalLabel}: {$rebateStr}";
                    }),
            ])
            ->columns([
                // 1. TRANSACTION DATE
                Tables\Columns\TextColumn::make('transaction_date')
                    ->label(__('system.labels.transaction_date'))
                    ->placeholder(__('system.n/a'))
                    ->date('d/m/Y')
                    ->alignment(Alignment::Center),

                // 2. STORE NAME
                Tables\Columns\TextColumn::make('store_name')
                    ->label(__('system.labels.store_name'))
                    ->alignment(Alignment::Center)
                    ->weight('medium')
                    ->searchable(),

                // 3. STATUS
                Tables\Columns\TextColumn::make('status')
                    ->label(__('system.labels.status'))
                    ->alignment(Alignment::Center)
                    ->badge()
                    // 1. Giữ nguyên Icon trạng thái ở phía trước (trái)
                    ->icon(fn (string $state): string => match ($state) {
                        'clicked' => 'heroicon-m-cursor-arrow-rays',
                        'pending' => 'heroicon-m-clock',
                        'confirmed' => 'heroicon-m-check-badge',
                        'missing' => 'heroicon-m-magnifying-glass',
                        'ineligible' => 'heroicon-m-x-circle',
                        default => 'heroicon-m-question-mark-circle',
                    })
                    // 2. Dùng formatStateUsing để "vẽ" thêm icon bút chì vào phía sau (phải)
                    ->formatStateUsing(fn (string $state) => new HtmlString('
                        <div class="flex items-center gap-1.5 justify-center">
                            <span>'.__('system.status.'.$state).'</span>
                                '.Blade::render('<x-heroicon-m-pencil-square class="w-4 h-4 text-gray-400" />').'
                         </div>
                    '))
                    ->color(fn (string $state): string => match ($state) {
                        'confirmed' => 'success',
                        'pending' => 'info',
                        'missing' => 'warning',
                        'ineligible' => 'danger',
                        default => 'gray',
                    })
                    ->action(
                        Tables\Actions\Action::make('quick_set_status')
                            ->label(__('system.labels.quick_set_status'))
                            ->modalHeading(__('system.labels.quick_set_status'))
                            ->modalSubmitActionLabel(__('system.actions.submit'))
                            ->modalCancelActionLabel(__('system.actions.cancel'))
                            ->form([
                                Forms\Components\Select::make('status')
                                    ->label(__('system.labels.status'))
                                    ->options([
                                        'clicked' => __('system.status.clicked'),
                                        'pending' => __('system.status.pending'),
                                        'confirmed' => __('system.status.confirmed'),
                                        'missing' => __('system.status.missing'),
                                        'ineligible' => __('system.status.ineligible'),
                                    ])
                                    ->default(fn ($record) => $record->status)
                                    ->required(),
                            ])
                            ->action(function ($record, array $data) {
                                // 1. Lưu vào Database
                                $record->update($data);

                                // 2. Gọi Cỗ máy để đẩy lên Google Sheet (bọc try-catch để tránh 500)
                                try {
                                    static::syncTrackerWithService($record);
                                } catch (\Exception $e) {
                                    Notification::make()
                                        ->title(__('system.notifications.sync_sheet_failed'))
                                        ->body($e->getMessage())
                                        ->warning()
                                        ->send();

                                    return;
                                }

                                Notification::make()
                                    ->title(__('system.notifications.status_updated_sync'))
                                    ->success()
                                    ->send();
                            })

                    ),

                // 5. REBATE AMOUNT ($)
                Tables\Columns\TextColumn::make('rebate_amount')
                    ->label(__('system.labels.rebate_amount'))
                    ->money('USD')
                    ->color('success')
                    ->weight('bold')
                    ->alignment(Alignment::Right),

                // --- TOGGLEABLE: ẩn mặc định ---

                Tables\Columns\TextColumn::make('order_value')
                    ->label(__('system.labels.order_value'))
                    ->money('USD')
                    ->alignment(Alignment::Right)
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('cashback_percent')
                    ->label(__('system.labels.cashback_percent'))
                    ->numeric(2)
                    ->suffix('%')
                    ->alignment(Alignment::Right)
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('order_id')
                    ->label(__('system.labels.order_id'))
                    ->alignment(Alignment::Center)
                    ->placeholder(__('system.n/a'))
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->searchable(),

                Tables\Columns\TextColumn::make('payout_date')
                    ->label(__('system.labels.payout_date'))
                    ->alignment(Alignment::Center)
                    // 1. Dùng state() để ép giá trị null thành chuỗi 'N/A' TRƯỚC khi render
                    ->state(fn ($record) => $record->payout_date ? $record->payout_date : 'N/A')
                    // 2. Định dạng hiển thị: Nếu là 'N/A' thì giữ nguyên, nếu là ngày thì format
                    ->formatStateUsing(function ($state) {
                        if ($state === 'N/A') {
                            return $state;
                        }
                        try {
                            return Carbon::parse($state)->format('d/m/Y');
                        } catch (\Exception $e) {
                            return $state;
                        }
                    })
                    // 3. Các thuộc tính giao diện (Badge và Icon sẽ hiện cho cả N/A)
                    ->icon('heroicon-m-pencil-square')
                    ->iconPosition(IconPosition::After)
                    ->iconColor('gray')
                    // 4. Action bấm vào để sửa
                    ->action(
                        Tables\Actions\Action::make('quick_set_date')
                            ->label(__('system.labels.quick_set_date'))
                            ->modalHeading(__('system.labels.quick_set_date'))
                            ->modalSubmitActionLabel(__('system.actions.submit'))
                            ->modalCancelActionLabel(__('system.actions.cancel'))
                            ->form([
                                Forms\Components\TextInput::make('payout_date')
                                    ->label(__('system.labels.select_payout_date'))
                                    ->placeholder('dd/mm/yyyy')
                                    ->mask('99/99/9999')
                                    ->default(fn ($record) => optional($record->payout_date ?? now())->format('d/m/Y'))
                                    ->rules(['date_format:d/m/Y'])
                                    ->dehydrateStateUsing(fn ($state) => Carbon::createFromFormat('d/m/Y', $state)->format('Y-m-d'))
                                    ->required(),
                            ])
                            ->action(function ($record, array $data) {
                                $record->update($data);

                                try {
                                    static::syncTrackerWithService($record);
                                } catch (\Exception $e) {
                                    Notification::make()
                                        ->title(__('system.notifications.sync_sheet_failed'))
                                        ->body($e->getMessage())
                                        ->warning()
                                        ->send();

                                    return;
                                }

                                Notification::make()
                                    ->title(__('system.notifications.date_updated_sync'))
                                    ->success()
                                    ->send();
                            })

                    ),
            ])
            ->striped()
            ->defaultSort('transaction_date', 'desc')

            ->filters([
                // Lọc theo Tài khoản (Email)
                Tables\Filters\SelectFilter::make('account_id')
                    ->label(__('system.labels.account_email'))
                    ->options(function () {
                        // B1: Lấy danh sách các account_id ĐÃ ĐƯỢC LÀM trong bảng RebateTracker
                        $activeAccountIds = RebateTracker::whereNotNull('account_id')
                            ->distinct()
                            ->pluck('account_id');

                        // B2: Chỉ móc Email của những account_id nằm trong danh sách trên
                        return Account::whereIn('id', $activeAccountIds)
                            ->with('email')
                            ->get()
                            ->filter(fn ($account) => $account->email) // Bỏ qua nếu lỗi mất email
                            ->pluck('email.email', 'id')
                            ->toArray();
                    })
                    ->searchable()
                    ->preload()
                    ->columnSpan(fn () => auth()->user()?->role === 'operator' ? 2 : 1),

                // Bộ lọc Platform (Quan trọng để Sub-menu chạy)
                Tables\Filters\SelectFilter::make('platform')
                    ->label(__('system.labels.platform'))
                    ->options(function () {
                        // Tương tự, chỉ lấy những Platform của các Account đã có đơn
                        $activeAccountIds = RebateTracker::whereNotNull('account_id')
                            ->distinct()
                            ->pluck('account_id');

                        $platforms = Account::whereIn('id', $activeAccountIds)
                            ->whereNotNull('platform')
                            ->distinct()
                            ->pluck('platform') // Chỉ pluck 1 cột để lấy mảng value
                            ->toArray();

                        // 🟢 2. FORMAT LẠI NHÃN (LABEL) NGAY BÊN TRONG HÀM OPTIONS
                        $platforms_map = Platform::pluck('name', 'slug')->toArray();
                        $formattedOptions = [];
                        foreach ($platforms as $p) {
                            $formattedOptions[$p] = $platforms_map[$p] ?? $p;
                        }

                        return $formattedOptions;
                    })
                    ->query(function (Builder $query, array $data) {
                        if (! empty($data['value'])) {
                            $query->whereHas('account', fn ($q) => $q->where('platform', $data['value']));
                        }
                    })
                    ->searchable()
                    ->visible(static::class === RebateTrackerResource::class)
                    ->columnSpan(1),

                // Bộ lọc Trạng thái (CHỈ HIỆN TRẠNG THÁI ĐÃ CÓ TRONG DỮ LIỆU THỰC TẾ)
                Tables\Filters\SelectFilter::make('status')
                    ->label(__('system.labels.status'))
                    ->options(function () {
                        // 1. Quét tìm các status đang thực sự tồn tại trong DB
                        $activeStatuses = RebateTracker::whereNotNull('status')
                            ->distinct()
                            ->pluck('status');

                        // 2. Bộ từ điển dịch tên Status cho đẹp
                        $labels = [
                            'pending' => __('system.status.pending'),
                            'confirmed' => __('system.status.confirmed'),
                            'ineligible' => __('system.status.ineligible'),
                            'missing' => __('system.status.missing'),
                            'clicked' => __('system.status.clicked'),
                        ];

                        // 3. Ráp dữ liệu: Chỉ tạo Option cho những Status quét được ở Bước 1
                        $options = [];
                        foreach ($activeStatuses as $st) {
                            // Nếu có trong từ điển thì lấy từ điển, nếu status lạ thì tự viết hoa chữ cái đầu
                            $options[$st] = $labels[$st] ?? ucfirst(trim((string) $st));
                        }

                        return $options;
                    })
                    ->multiple()
                    ->columnSpan(1),

                // Bộ lọc theo User (CHỈ HIỆN USER ĐÃ CÓ ĐƠN)
                Tables\Filters\SelectFilter::make('user_id')
                    ->label(__('system.labels.user'))
                    ->visible(fn () => auth()->user()?->isAdmin()) // 🟢 ẨN KHỎI NHÂN VIÊN
                    ->options(function () {
                        // 1. Quét lấy danh sách user_id đang thực sự có đơn
                        $activeUserIds = RebateTracker::whereNotNull('user_id')
                            ->distinct()
                            ->pluck('user_id');

                        // 2. Lấy tên của đúng những User đó
                        return User::whereIn('id', $activeUserIds)
                            ->pluck('name', 'id')
                            ->toArray();
                    })
                    ->searchable()
                    ->preload()
                    ->columnSpan(1),

                // Lọc theo Tên cửa hàng (Store Name)
                Tables\Filters\SelectFilter::make('store_name')
                    ->label(__('system.labels.store_name'))
                    ->options(
                        fn () => RebateTracker::select('store_name')
                            ->whereNotNull('store_name')
                            ->distinct()
                            ->pluck('store_name', 'store_name')
                            ->toArray()
                    )
                    ->searchable()
                    ->columnSpan(1),

                // Lọc theo Ngày Giao dịch (Từ ngày - Đến ngày)
                Tables\Filters\Filter::make('transaction_date')
                    ->form([
                        DatePicker::make('transaction_from')->label(__('system.trackers.filters.transaction_from'))->displayFormat('d/m/Y')->native(false),
                        DatePicker::make('transaction_to')->label(__('system.trackers.filters.transaction_to'))->displayFormat('d/m/Y')->native(false),
                    ])
                    ->columns(2)     // 👈 Ép 2 ô Date nằm ngang nhau
                    ->columnSpan(2)  // 👈 Chiếm 2 phần lưới
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when($data['transaction_from'], fn ($q, $date) => $q->whereDate('transaction_date', '>=', $date))
                            ->when($data['transaction_to'], fn ($q, $date) => $q->whereDate('transaction_date', '<=', $date));
                    }),

                // Lọc theo Ngày Payout (Từ ngày - Đến ngày)
                Tables\Filters\Filter::make('payout_date')
                    ->form([
                        DatePicker::make('payout_from')->label(__('system.trackers.filters.payout_from'))->displayFormat('d/m/Y')->native(false),
                        DatePicker::make('payout_to')->label(__('system.trackers.filters.payout_to'))->displayFormat('d/m/Y')->native(false),
                    ])
                    ->columns(2)     // 👈 Ép 2 ô Date nằm ngang nhau
                    ->columnSpan(2)  // 👈 Chiếm 2 phần lưới
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when($data['payout_from'], fn ($q, $date) => $q->whereDate('payout_date', '>=', $date))
                            ->when($data['payout_to'], fn ($q, $date) => $q->whereDate('payout_date', '<=', $date));
                    }),
                Tables\Filters\TrashedFilter::make(), // 🟢 BẬT TÍNH NĂNG THÙNG RÁC
            ])
            ->filtersLayout(FiltersLayout::AboveContentCollapsible)

            // 2. CHÌA KHÓA Ở ĐÂY: TỰ ĐỘNG CHIA 5 CỘT HOẶC 4 CỘT TÙY VÀO TRANG ĐANG XEM
            ->filtersFormColumns(static::class === RebateTrackerResource::class ? 5 : 4)

            ->actions([
                // Nút Xem chi tiết (Hình con mắt) hiện ra bên ngoài
                Tables\Actions\ViewAction::make()
                    ->label('') // Để trống nhãn để chỉ hiện icon cho gọn
                    ->modalHeading(__('system.labels.asset_info')) // TIÊU ĐỀ CỦA MODAL
                    ->tooltip(__('system.labels.detail')) // Hiện ghi chú khi di chuột vào
                    ->icon('heroicon-o-eye')
                    ->color('gray'), // Màu xám nhẹ nhàng, không lấn át nút cam

                Tables\Actions\ActionGroup::make([
                    // Thêm nút nhân bản
                    Tables\Actions\ReplicateAction::make()
                        ->label(__('system.actions.replicate') ?: 'Replicate')
                        ->icon('heroicon-m-plus-circle')
                        ->color('success')
                        // Có thể yêu cầu điền thông tin mới trước khi tạo
                        ->form([
                            Forms\Components\Grid::make(4)
                                ->schema([
                                    Forms\Components\TextInput::make('store_name')
                                        ->label(__('system.labels.store_name'))
                                        ->placeholder(__('system.placeholders.store_name_example'))
                                        ->required()
                                        ->maxLength(255),
                                    Forms\Components\TextInput::make('order_id')
                                        ->label(__('system.labels.order_id')),
                                    Forms\Components\TextInput::make('order_value')
                                        ->label(__('system.labels.order_value'))
                                        ->placeholder(__('system.placeholders.order_value_example'))
                                        ->numeric()
                                        ->required(),
                                    Forms\Components\TextInput::make('cashback_percent')
                                        ->label(__('system.labels.cashback_percent'))
                                        ->numeric()
                                        ->suffix('%')
                                        ->reactive()
                                        ->default(10),
                                ]),
                            Forms\Components\Grid::make(4)
                                ->schema([
                                    Forms\Components\TextInput::make('transaction_date')
                                        ->label(__('system.labels.transaction_date'))
                                        ->placeholder('dd/mm/yyyy')
                                        ->mask('99/99/9999')
                                        ->rules(['nullable', 'date_format:d/m/Y'])
                                        ->afterStateHydrated(function ($component, $state) {
                                            if ($state) {
                                                $ymd = \Carbon\Carbon::parse($state)->setTimezone(config('app.timezone'))->format('Y-m-d');
                                                [$year, $month, $day] = explode('-', substr($ymd, 0, 10));
                                                $component->state("{$day}/{$month}/{$year}");
                                            }
                                        })
                                        ->dehydrateStateUsing(function ($state) {
                                            if (blank($state)) {
                                                return null;
                                            }

                                            if (! preg_match('#^(\d{2})/(\d{2})/(\d{4})$#', $state, $m)) {
                                                return null;
                                            }

                                            return "{$m[3]}-{$m[2]}-{$m[1]}";
                                        }),
                                    Forms\Components\TextInput::make('payout_date')
                                        ->label(__('system.labels.payout_date'))
                                        ->placeholder('dd/mm/yyyy')
                                        ->mask('99/99/9999')
                                        ->rules(['nullable', 'date_format:d/m/Y'])
                                        ->afterStateHydrated(function ($component, $state) {
                                            if ($state) {
                                                $ymd = \Carbon\Carbon::parse($state)->setTimezone(config('app.timezone'))->format('Y-m-d');
                                                [$year, $month, $day] = explode('-', substr($ymd, 0, 10));
                                                $component->state("{$day}/{$month}/{$year}");
                                            }
                                        })
                                        ->dehydrateStateUsing(function ($state) {
                                            if (blank($state)) {
                                                return null;
                                            }

                                            if (! preg_match('#^(\d{2})/(\d{2})/(\d{4})$#', $state, $m)) {
                                                return null;
                                            }

                                            return "{$m[3]}-{$m[2]}-{$m[1]}";
                                        }),
                                    Forms\Components\Select::make('status')
                                        ->label(__('system.labels.status'))
                                        ->options([
                                            'pending' => __('system.status.pending'),
                                            'confirmed' => __('system.status.confirmed'),
                                            'ineligible' => __('system.status.ineligible'),
                                            'missing' => __('system.status.missing'),
                                            'clicked' => __('system.status.clicked'),
                                        ])
                                        ->default('clicked')
                                        ->required(),
                                    Forms\Components\TextInput::make('device')
                                        ->label(__('system.labels.device'))
                                        ->placeholder('iOS, VMware, BitBrowser Antidetect...'),
                                ]),
                            Forms\Components\Textarea::make('note')
                                ->label(__('system.labels.note'))
                                ->rows(5),
                            Forms\Components\Textarea::make('detail_transaction')
                                ->label(__('system.labels.transaction_details'))
                                ->rows(5),
                        ])
                        ->beforeReplicaSaved(function ($replica, $data) {
                            // Ghi đè dữ liệu mới vào bản sao
                            $replica->fill($data);
                            $replica->rebate_amount = (float) $data['order_value'] * ($replica->cashback_percent / 100);
                        }),
                    Tables\Actions\RestoreAction::make(), // 🟢 Nút khôi phục dòng bị xóa
                    Tables\Actions\ForceDeleteAction::make()
                        ->visible(fn () => auth()->user()?->isAdmin()),
                    Tables\Actions\EditAction::make(),
                    Tables\Actions\DeleteAction::make(),
                ]),
            ])

            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    // NÚT XUẤT GOOGLE SHEET (18 CỘT)
                    Tables\Actions\BulkAction::make('export_to_google_sheet')
                        ->label(__('system.actions.export_to_sheet'))
                        ->icon('heroicon-o-table-cells')
                        ->color('success')
                        ->visible(fn () => auth()->user()?->isAdmin())
                        ->requiresConfirmation()
                        ->action(function (Collection $records, GoogleSyncService $syncService) {
                            try {
                                $syncService->syncTrackers($records);

                                Notification::make()
                                    ->title(__('system.notifications.sync_success'))
                                    ->success()
                                    ->send();
                            } catch (\Exception $e) {
                                Notification::make()
                                    ->title(__('system.notifications.sync_error'))
                                    ->body($e->getMessage())
                                    ->danger()
                                    ->send();
                            }
                        })
                        ->deselectRecordsAfterCompletion(),
                    // Tự động bỏ tick sau khi xuất xong

                    // Nút đổi nhanh sang Pending
                    Tables\Actions\BulkAction::make('assign_batch')
                        ->label(__('system.actions.assign_batch'))
                        ->icon('heroicon-o-rectangle-group')
                        ->form([
                            \Filament\Forms\Components\TextInput::make('batch_id')
                                ->label(__('system.labels.batch_id_placeholder'))
                                ->required(),
                        ])
                        ->action(function (Collection $records, array $data) {
                            foreach ($records as $record) {
                                $record->update(['batch_id' => $data['batch_id']]);
                            }
                            Notification::make()->title(__('system.actions.assign_batch_success', ['count' => $records->count(), 'batch' => $data['batch_id']]))->success()->send();
                        })
                        ->deselectRecordsAfterCompletion(),

                    Tables\Actions\BulkAction::make('remove_batch')
                        ->label(__('system.actions.remove_batch'))
                        ->icon('heroicon-o-rectangle-stack')
                        ->requiresConfirmation()
                        ->action(function (Collection $records) {
                            foreach ($records as $record) {
                                $record->update(['batch_id' => null]);
                            }
                            Notification::make()->title(__('system.actions.remove_batch_success', ['count' => $records->count()]))->success()->send();
                        })
                        ->deselectRecordsAfterCompletion(),

                    static::makeBulkStatusAction('pending', __('system.actions.mark_as_pending') ?: 'Mark as Pending', 'heroicon-o-clock', 'info'),
                    // Nút đổi nhanh sang Confirme
                    static::makeBulkStatusAction('confirmed', __('system.actions.mark_as_confirmed') ?: 'Mark as Confirmed', 'heroicon-o-check-badge', 'success'),

                    Tables\Actions\RestoreBulkAction::make(),     // 🟢 Khôi phục nhiều dòng
                    Tables\Actions\ForceDeleteBulkAction::make()
                        ->visible(fn () => auth()->user()?->isAdmin()),
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    /**
     * 🟢 HÀM GỘP: Xử lý đổi trạng thái và đồng bộ cho hàng loạt record
     */
    public static function bulkUpdateStatus(Collection $records, string $newStatus): void
    {
        $count = 0;
        foreach ($records as $record) {
            // Chỉ cập nhật nếu trạng thái thực sự thay đổi
            if ($record->status !== $newStatus) {
                $record->update(['status' => $newStatus]);
                $count++;
            }
        }

        // Thông báo thành công mượt mà góc màn hình
        Notification::make()
            ->title("Đã chuyển {$count} dòng thành ".ucfirst($newStatus))
            ->success()
            ->send();

        // 💡 LƯU Ý: Chúng ta chỉ cần update DB.
        // RebateTrackerObserver của bạn sẽ tự động "bắt" được sự thay đổi này
        // và tự động đẩy Job lên Google Sheets. Không cần viết lệnh gọi Sheet ở đây nữa!
    }

    /**
     * 🟢 HÀM REFACTOR: Tự động sinh ra các nút Bulk Action đổi trạng thái
     */
    private static function makeBulkStatusAction(string $status, string $label, string $icon, string $color): Tables\Actions\BulkAction
    {
        return Tables\Actions\BulkAction::make('markAs'.ucfirst($status))
            ->label($label)
            ->icon($icon)
            ->color($color)
            ->requiresConfirmation()
            ->action(fn (Collection $records) => static::bulkUpdateStatus($records, $status));
    }

    // =========================================================
    // DÁN ĐOẠN NÀY VÀO ĐÂY (NẰM NGOÀI HÀM TABLE)
    // =========================================================
    public static function syncTrackerWithService($record): void
    {
        app(GoogleSyncService::class)->syncTrackers(collect([$record]));
    }

    // =========================================================

}
