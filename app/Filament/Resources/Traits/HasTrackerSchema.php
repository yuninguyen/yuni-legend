<?php

namespace App\Filament\Resources\Traits;

use Filament\Infolists\Infolist;
use Filament\Support\Enums\Alignment;
use Filament\Support\Enums\IconPosition;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Tables\Grouping\Group;
use Illuminate\Support\HtmlString;
use Filament\Support\Enums\FontWeight;
use Illuminate\Database\Eloquent\Builder;
use App\Filament\Resources\Traits\HasUsStates;
use App\Filament\Resources\Traits\HasPlatform;

trait HasTrackerSchema
{
    // FIX #8: $usStates được kế thừa từ HasUsStates — KHÔNG khai báo lại ở đây.
    use HasUsStates;
    use HasPlatform;
    use HasPlatformCache;

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
                                    ->relationship('user', 'name', fn (Builder $query) => $query->whereHas('accounts'))
                                    ->default(fn() => auth()->id())
                                    ->hidden(fn() => !auth()->user()?->isAdmin())
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
                                        if (!$userId)
                                            return $allPlatforms;

                                        $activePlatformKeys = \App\Models\Account::where('user_id', $userId)
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
                                        if (!$userId || !$platform)
                                            return [];

                                        return \App\Models\Account::query()
                                            ->where('user_id', $userId)
                                            ->where('platform', $platform)
                                            ->select(['id', 'email_id', 'user_id', 'platform'])
                                            ->with('email:id,email')
                                            ->withSum(['rebateTrackers as pending_amount' => fn($q) => $q->whereIn('status', ['pending', 'clicked'])], 'rebate_amount')
                                            ->withSum(['rebateTrackers as confirmed_amount' => fn($q) => $q->whereIn('status', ['confirmed'])], 'rebate_amount')
                                            ->withSum(['payoutLogs as paid_amount' => fn($q) => $q->whereIn('transaction_type', ['withdrawal', 'hold'])->where('status', 'completed')], 'amount_usd')
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
                                    ->suffixAction(
                                        Forms\Components\Actions\Action::make('copyEmail')
                                            ->icon('heroicon-m-clipboard-document')
                                            ->color('warning')
                                            ->tooltip(__('system.actions.copy') . ' Email')
                                            ->action(function (Forms\Get $get, $livewire) {
                                                $email = $get('account_email');

                                                if ($email) {
                                                    // Sử dụng chung công nghệ dispatch copy của Sếp
                                                    $livewire->dispatch('copy-to-clipboard', text: $email);

                                                    \Filament\Notifications\Notification::make()
                                                        ->title(__('system.labels.email_copied'))
                                                        ->success()
                                                        ->send();
                                                }
                                            })
                                    )
                                    ->afterStateUpdated(function ($state, $get, $set) {
                                        if (!$state) {
                                            $set('account_id', null);
                                            $set('account_password_display', null);
                                            return;
                                        }
                                        $account = \App\Models\Account::whereHas('email', fn($q) => $q->where('email', $state))
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
                                    ->formatStateUsing(fn($record) => $record?->account?->password)
                                    ->suffixAction(
                                        Forms\Components\Actions\Action::make('copyPassword')
                                            ->icon('heroicon-m-clipboard-document')
                                            ->color('warning')
                                            ->action(function (Forms\Get $get, $livewire) {
                                                $accountId = $get('account_id');
                                                $password = \App\Models\Account::find($accountId)?->password;

                                                if ($password) {
                                                    $livewire->dispatch('copy-to-clipboard', text: $password);

                                                    \Filament\Notifications\Notification::make()
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
                            ->visible(fn($get) => $get('account_email'))
                            ->content(function ($get) {
                                // ... (Giữ nguyên toàn bộ nội dung HtmlString bên trong của Sếp)
                                $emailState = $get('account_email');
                                $account = \App\Models\Account::whereHas('email', fn($q) => $q->where('email', $emailState))->first();
                                if (!$account)
                                    return new HtmlString("<div class='text-danger'>⚠️ " . __('system.notifications.no_records_found') . "</div>");
                                $statuses = (array) $account->status;
                                if (empty($statuses))
                                    return __('system.n/a');
                                $htmlResult = collect($statuses)->map(function ($status, $index) use ($statuses) {
                                    $color = match ($status) {
                                        'active' => '#6b7280',
                                        'used' => '#3b82f6',
                                        'no_paypal_needed' => '#1e3a8a',
                                        'not_linked', 'unlinked' => '#f59e0b',
                                        'linked' => '#22c55e',
                                        'limited', 'banned' => '#ef4444',
                                        default => '#6b7280'
                                    };
                                    $label = match ($status) {
                                        'active' => __('system.status.active'),
                                        'used' => __('system.status.used'),
                                        'no_paypal_needed' => __('system.status.no_paypal_required'),
                                        'not_linked' => __('system.status.not_linked_paypal'),
                                        'unlinked' => __('system.status.unlinked_paypal'),
                                        'linked' => __('system.status.linked_paypal'),
                                        'limited' => __('system.status.paypal_limited'),
                                        'banned' => __('system.status.banned'),
                                        default => __('system.status.' . $status)
                                    };
                                    $arrow = ($index < count($statuses) - 1) ? " <span style='color: #d1d5db; margin: 0 4px;'>→</span> " : "";
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
                                Forms\Components\DatePicker::make('transaction_date')
                                    ->label(__('system.labels.transaction_date'))
                                    ->placeholder('dd/mm/yyyy')
                                    ->displayFormat('d/m/Y')
                                    ->format('Y-m-d') // Định dạng chuẩn để lưu vào MySQL
                                    ->native(false)
                                    ->closeOnDateSelection()
                                    ->reactive() // Quan trọng: Để Payout Date có thể nhận diện thay đổi của Transaction Date
                                    ->nullable() // Cho phép để trống
                                    ->default(null)
                                    ->columns(5), // Đảm bảo không tự động lấy ngày hiện tại

                                Forms\Components\DatePicker::make('payout_date')
                                    ->label(__('system.labels.payout_date'))
                                    ->placeholder('dd/mm/yyyy')
                                    ->displayFormat('d/m/Y')
                                    ->format('Y-m-d') // Định dạng chuẩn để lưu vào MySQL
                                    ->native(false)
                                    ->closeOnDateSelection()
                                    // Logic Validation: Phải sau hoặc bằng ngày giao dịch
                                    ->after('transaction_date')
                                    ->validationMessages([
                                        'after' => __('system.notifications.date_updated_sync') // Tạm dùng key này hoặc tạo mới
                                    ])
                                    ->nullable() // Cho phép để trống
                                    ->default(null)
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
                                    ->reactive()
                                    ->required(),

                                Forms\Components\TextInput::make('cashback_percent')
                                    ->label(__('system.labels.cashback_percent'))
                                    ->numeric()
                                    ->suffix('%')
                                    ->reactive()
                                    ->default(10),

                                Forms\Components\Placeholder::make('rebate_amount_display')
                                    ->label(__('system.labels.rebate_amount'))
                                    ->content(function ($get) {
                                        $total = (float) $get('order_value') * ((float) $get('cashback_percent') / 100);
                                        return '$ ' . number_format($total, 2);
                                    })
                                    ->extraAttributes(['class' => 'text-success font-bold text-xl']),
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
                // PHẦN 1: EMAIL INFORMATION
                \Filament\Infolists\Components\Section::make(__('system.account_claim.section_title'))
                    ->schema([
                        \Filament\Infolists\Components\TextEntry::make('account.email.email')
                            ->label(__('system.labels.email_address'))
                            ->placeholder('N/A')
                            ->copyable(), // Cho phép click để copy nhanh
                        // Password (Lấy từ quan hệ: account -> password)
                        \Filament\Infolists\Components\TextEntry::make('account.password')
                            ->label(__('system.labels.password'))
                            ->placeholder('N/A'),
                        // Platform (Lấy từ quan hệ: account -> platform)
                        \Filament\Infolists\Components\TextEntry::make('account.platform')
                            ->label(__('system.labels.platform'))
                            ->placeholder('N/A')
                            ->formatStateUsing(fn($state) => $state ? static::getPlatformName($state) : 'N/A'),
                        // User (Để hiện tên thay vì ID số 1)
                        \Filament\Infolists\Components\TextEntry::make('user.name')
                            ->label(__('system.labels.user'))
                            ->placeholder('N/A'),
                        // Status (Đảm bảo đúng tên cột trong DB
                        \Filament\Infolists\Components\TextEntry::make('account.status')
                            ->label(__('system.labels.account_status_tracking'))
                            ->html() // Bắt buộc phải có để Filament render thẻ <span> và <div>
                            ->placeholder('No status history found.')
                            ->formatStateUsing(function ($state, $record) {
                                // Lấy account từ record hiện tại của Infolist
                                $account = $record->account;
                                if (!$account || !$account->status)
                                    return null;

                                // Đảm bảo dữ liệu là mảng để chạy vòng lặp map
                                $statusHistory = is_array($account->status)
                                    ? $account->status
                                    : json_decode($account->status, true) ?? [$account->status];

                                $htmlResult = collect($statusHistory)->map(function ($status, $index) use ($statusHistory) {
                                    $color = match ($status) {
                                        'active' => '#6b7280',
                                        'used' => '#3b82f6',
                                        'no_paypal_needed' => '#1e3a8a',
                                        'not_linked', 'unlinked' => '#f59e0b',
                                        'linked' => '#22c55e',
                                        'limited', 'banned' => '#ef4444',
                                        default => '#6b7280'
                                    };

                                    $label = match ($status) {
                                        'active' => __('system.status.active'),
                                        'used' => __('system.status.used'),
                                        'no_paypal_needed' => __('system.status.no_paypal_required'),
                                        'not_linked' => __('system.status.not_linked_paypal'),
                                        'unlinked' => __('system.status.unlinked_paypal'),
                                        'linked' => __('system.status.linked_paypal'),
                                        'limited' => __('system.status.paypal_limited'),
                                        'banned' => __('system.status.banned'),
                                        default => __('system.status.' . $status)
                                    };

                                    $isLast = $index === count($statusHistory) - 1;
                                    $arrow = !$isLast ? " <span style='color: #9ca3af; margin: 0 10px;'>→</span> " : "";

                                    return "<span style='color: {$color}; font-weight: 800; font-size: 0.9rem;'>{$label}</span>{$arrow}";
                                })->implode('');

                                return new HtmlString("
                                    <div style='padding: 10px; background: #f9fafb; border: 1px solid #e5e7eb; border-radius: 6px; display: inline-block;'>
                                        {$htmlResult}
                                    </div>
                                ");
                            })
                            ->columnSpanFull()
                    ])->columns(2),

                // PHẦN 2: PLATFORM & SOURCE INFORMATION
                \Filament\Infolists\Components\Section::make(__('system.rebate_tracker.order_detail'))
                    ->schema([
                        \Filament\Infolists\Components\TextEntry::make('transaction_date')
                            ->label(__('system.labels.transaction_date'))
                            ->dateTime('d/m/Y')
                            ->placeholder('N/A'),
                        \Filament\Infolists\Components\TextEntry::make('payout_date')
                            ->label(__('system.labels.payout_date'))
                            ->dateTime('d/m/Y')
                            ->placeholder('N/A'),
                        \Filament\Infolists\Components\TextEntry::make('status')
                            ->label(__('system.labels.status'))
                            ->placeholder('N/A')
                            ->badge()
                            ->icon(fn(string $state): string => match ($state) {
                                'clicked' => 'heroicon-m-cursor-arrow-rays',
                                'pending' => 'heroicon-m-clock',
                                'confirmed' => 'heroicon-m-check-badge',
                                'missing' => 'heroicon-m-magnifying-glass', // Hình kính lúp tìm kiếm
                                'ineligible' => 'heroicon-m-x-circle',        // Hình dấu X tròn
                                default => 'heroicon-m-question-mark-circle',
                            })
                            ->formatStateUsing(fn(string $state): string => match ($state) {
                                'clicked' => __('system.status.clicked'),
                                'pending' => __('system.status.pending'),
                                'confirmed' => __('system.status.confirmed'),
                                'missing' => __('system.status.missing'),
                                'ineligible' => __('system.status.ineligible'),
                                default => ucfirst($state), // Các nhãn khác chỉ viết hoa chữ cái đầu
                            })
                            ->color(fn(string $state): string => match ($state) {
                                'clicked' => 'gray',
                                'pending' => 'info',
                                'confirmed' => 'success',
                                'missing' => 'warning',
                                'ineligible' => 'danger',
                                default => 'gray',
                            }),
                        \Filament\Infolists\Components\TextEntry::make('store_name')
                            ->label(__('system.labels.store_name'))
                            ->placeholder(__('system.n/a')),

                        \Filament\Infolists\Components\TextEntry::make('order_id')
                            ->label(__('system.labels.order_id'))
                            ->placeholder(__('system.n/a')),
                        \Filament\Infolists\Components\TextEntry::make('order_value')
                            ->label(__('system.labels.order_value'))
                            ->placeholder(__('system.n/a')),
                        \Filament\Infolists\Components\TextEntry::make('cashback_percent')
                            ->label(__('system.labels.cashback_percent'))
                            ->placeholder(__('system.n/a')),
                        \Filament\Infolists\Components\TextEntry::make('rebate_amount')
                            ->label(__('system.labels.rebate_amount'))
                            ->money('USD')
                            ->weight(FontWeight::Bold)
                            ->color('success'),
                    ])->columns(3),

                // PHẦN 3: Logistics & Note
                \Filament\Infolists\Components\Section::make(__('system.labels.note'))
                    ->schema([
                        \Filament\Infolists\Components\TextEntry::make('device')
                            ->label(__('system.labels.device'))
                            ->placeholder(__('system.n/a')),
                        \Filament\Infolists\Components\TextEntry::make('state')
                            ->label(__('system.labels.state_us'))
                            ->placeholder(__('system.n/a'))
                            ->formatStateUsing(fn($state) => $state ? "{$state} - " . (self::$usStates[$state] ?? '') : 'N/A'),
                        \Filament\Infolists\Components\TextEntry::make('note')
                            ->label(__('system.labels.note'))
                            ->placeholder(__('system.n/a'))
                            ->columnSpanFull()
                            ->html() // Cho phép tự định nghĩa HTML để ép khoảng cách
                            ->formatStateUsing(fn($state) => $state ? '
                                <div style="
                                    white-space: pre-wrap;
                                    line-height: 1.6; /* Thu hẹp tối đa khoảng cách giữa các dòng */
                                    margin: 0;
                                    padding: 0;
                                ">' . e(trim($state)) . '</pre>' : 'N/A'),
                        \Filament\Infolists\Components\TextEntry::make('detail_transaction')
                            ->label(__('system.labels.transaction_details'))
                            ->columnSpanFull()
                            ->html() // Cho phép tự định nghĩa HTML để ép khoảng cách
                            ->formatStateUsing(fn($state) => $state ? '
                                <div style="
                                    white-space: pre-wrap;
                                    line-height: 1.6; /* Thu hẹp tối đa khoảng cách giữa các dòng */
                                    margin: 0;
                                    padding: 0;
                                ">' . e(trim($state)) . '</pre>' : 'N/A')
                            ->extraAttributes([
                                'class' => 'bg-gray-50 p-4 rounded-xl border border-gray-200 shadow-sm transition',
                                'style' => 'max-height: 300px; overflow-y: auto; line-height: 1.6;'
                            ])
                            ->placeholder('No details available'),

                    ])->columns(2),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn(Builder $query) => $query->with(['account.email', 'user'])) // 🟢 TỐI ƯU: Tránh N+1
            ->defaultGroup('account_id')
            ->groups([
                Group::make('account_id')
                    ->label(__('system.labels.account_email'))
                    ->collapsible()
                    ->getTitleFromRecordUsing(function ($record) {
                        $email = $record->account?->email?->email ?? 'N/A';
                        $platform = static::getPlatformName($record->account?->platform);
                        
                        // 🟢 TÍNH TỔNG CHO HEADER (Do phiên bản này chưa hỗ trợ ->summary() trên Group)
                        $totalRebate = \App\Models\RebateTracker::query()
                            ->where('account_id', $record->account_id)
                            ->sum('rebate_amount');

                        $rebateStr = '$' . number_format($totalRebate, 2);

                        return "{$email} | {$platform} | Total: {$rebateStr}";
                    }),
            ])
            ->columns([
                // PLATFORM => ALL REBATE TRACKER/ HIDE: SUB-TRACKER
                Tables\Columns\TextColumn::make('account.platform')
                    ->label(__('system.labels.platform'))
                    ->alignment(Alignment::Center)
                    ->extraHeaderAttributes(['style' => 'width: 90px; min-width: 90px'])
                    ->extraAttributes(['style' => 'width: 90px; min-width: 90px'])
                    ->searchable(query: function ($query, $search) {
                        $query->whereHas('account', fn($q) => $q->where('platform', 'like', "%{$search}%"));
                    })
                    ->formatStateUsing(fn($state) => $state ? static::getPlatformName($state) : 'N/A')
                    ->visible(false),

                // 1. STORE (Đẩy lùi vào để phân cấp)
                Tables\Columns\TextColumn::make('store_name')
                    ->label(__('system.labels.store_name'))
                    ->weight('medium')
                    ->icon('heroicon-m-shopping-bag')
                    ->iconColor('gray')
                    ->alignment(Alignment::Left)
                    ->extraAttributes(['class' => 'pl-10'])
                    ->wrap()
                    ->width('18%')
                    ->searchable(),

                // 1b. ORDER ID
                Tables\Columns\TextColumn::make('order_id')
                    ->label(__('system.labels.order_id'))
                    ->alignment(Alignment::Left)
                    ->placeholder(__('system.n/a'))
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->searchable(),

                // 2. ORDER VALUE
                Tables\Columns\TextColumn::make('order_value')
                    ->label(__('system.labels.order_value'))
                    ->money('USD')
                    ->alignment(Alignment::Right),

                // 3. CASHBACK PERCENT
                Tables\Columns\TextColumn::make('cashback_percent')
                    ->label(__('system.labels.cashback_percent'))
                    ->numeric(2)
                    ->suffix('%')
                    ->alignment(Alignment::Right),

                // 4. CASHBACK ($) - ĐÂY LÀ CHÌA KHÓA
                Tables\Columns\TextColumn::make('rebate_amount')
                    ->label(__('system.labels.rebate_amount'))
                    ->money('USD')
                    ->color('success')
                    ->weight('bold')
                    ->alignment(Alignment::Right),

                // 5. STATUS
                Tables\Columns\TextColumn::make('status')
                    ->label(__('system.labels.status'))
                    ->alignment(Alignment::Center)
                    ->badge()
                    // 1. Giữ nguyên Icon trạng thái ở phía trước (trái)
                    ->icon(fn(string $state): string => match ($state) {
                        'clicked' => 'heroicon-m-cursor-arrow-rays',
                        'pending' => 'heroicon-m-clock',
                        'confirmed' => 'heroicon-m-check-badge',
                        'missing' => 'heroicon-m-magnifying-glass',
                        'ineligible' => 'heroicon-m-x-circle',
                        default => 'heroicon-m-question-mark-circle',
                    })
                    // 2. Dùng formatStateUsing để "vẽ" thêm icon bút chì vào phía sau (phải)
                    ->formatStateUsing(fn(string $state) => new HtmlString('
                        <div class="flex items-center gap-1.5 justify-center">
                            <span>' . __('system.status.' . $state) . '</span>
                                ' . \Illuminate\Support\Facades\Blade::render('<x-heroicon-m-pencil-square class="w-4 h-4 text-gray-400" />') . '
                         </div>
                    '))
                    ->color(fn(string $state): string => match ($state) {
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
                                    ->default(fn($record) => $record->status)
                                    ->required(),
                            ])
                            ->action(function ($record, array $data) {
                                // 1. Lưu vào Database
                                $record->update($data);

                                // 2. Gọi Cỗ máy để đẩy lên Google Sheet (bọc try-catch để tránh 500)
                                try {
                                    static::syncTrackerWithService($record);
                                } catch (\Exception $e) {
                                    \Filament\Notifications\Notification::make()
                                        ->title(__('system.notifications.sync_sheet_failed'))
                                        ->body($e->getMessage())
                                        ->warning()
                                        ->send();
                                    return;
                                }

                                \Filament\Notifications\Notification::make()
                                    ->title(__('system.notifications.status_updated_sync'))
                                    ->success()
                                    ->send();
                            })

                    ),

                // 6. TIMELINE
                Tables\Columns\TextColumn::make('transaction_date')
                    ->label(__('system.labels.transaction_date'))
                    ->placeholder(__('system.n/a'))
                    ->date('d/m/Y')
                    ->alignment(Alignment::Center),

                // 7. TRANSACTION DETAILS
                Tables\Columns\TextColumn::make('detail_transaction')
                    ->label(__('system.labels.transaction_details'))
                    ->alignment(Alignment::Left)
                    ->placeholder(__('system.n/a'))
                    ->limit(40)
                    ->tooltip(fn($state) => $state)
                    ->toggleable(isToggledHiddenByDefault: false),
                Tables\Columns\TextColumn::make('payout_date')
                    ->label(__('system.labels.payout_date'))
                    ->alignment(Alignment::Center)
                    // 1. Dùng state() để ép giá trị null thành chuỗi 'N/A' TRƯỚC khi render
                    ->state(fn($record) => $record->payout_date ? $record->payout_date : 'N/A')
                    // 2. Định dạng hiển thị: Nếu là 'N/A' thì giữ nguyên, nếu là ngày thì format
                    ->formatStateUsing(function ($state) {
                        if ($state === 'N/A')
                            return $state;
                        try {
                            return \Carbon\Carbon::parse($state)->format('d/m/Y');
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
                                Forms\Components\DatePicker::make('payout_date')
                                    ->label(__('system.labels.select_payout_date'))
                                    ->default(fn($record) => $record->payout_date ?? now())
                                    ->required(),
                            ])
                            ->action(function ($record, array $data) {
                                $record->update($data);

                                try {
                                    static::syncTrackerWithService($record);
                                } catch (\Exception $e) {
                                    \Filament\Notifications\Notification::make()
                                        ->title(__('system.notifications.sync_sheet_failed'))
                                        ->body($e->getMessage())
                                        ->warning()
                                        ->send();
                                    return;
                                }

                                \Filament\Notifications\Notification::make()
                                    ->title(__('system.notifications.date_updated_sync'))
                                    ->success()
                                    ->send();
                            })

                    ),
            ])
            ->striped()


            ->filters([
                // Lọc theo Tài khoản (Email)
                Tables\Filters\SelectFilter::make('account_id')
                    ->label(__('system.labels.account_email'))
                    ->options(function () {
                        // B1: Lấy danh sách các account_id ĐÃ ĐƯỢC LÀM trong bảng RebateTracker
                        $activeAccountIds = \App\Models\RebateTracker::whereNotNull('account_id')
                            ->distinct()
                            ->pluck('account_id');

                        // B2: Chỉ móc Email của những account_id nằm trong danh sách trên
                        return \App\Models\Account::whereIn('id', $activeAccountIds)
                            ->with('email')
                            ->get()
                            ->filter(fn($account) => $account->email) // Bỏ qua nếu lỗi mất email
                            ->pluck('email.email', 'id')
                            ->toArray();
                    })
                    ->searchable()
                    ->preload()
                    ->columnSpan(fn() => auth()->user()?->role === 'operator' ? 2 : 1),

                // Bộ lọc Platform (Quan trọng để Sub-menu chạy)
                Tables\Filters\SelectFilter::make('platform')
                    ->label(__('system.labels.platform'))
                    ->options(function () {
                        // Tương tự, chỉ lấy những Platform của các Account đã có đơn
                        $activeAccountIds = \App\Models\RebateTracker::whereNotNull('account_id')
                            ->distinct()
                            ->pluck('account_id');

                        $platforms = \App\Models\Account::whereIn('id', $activeAccountIds)
                            ->whereNotNull('platform')
                            ->distinct()
                            ->pluck('platform') // Chỉ pluck 1 cột để lấy mảng value
                            ->toArray();

                        // 🟢 2. FORMAT LẠI NHÃN (LABEL) NGAY BÊN TRONG HÀM OPTIONS
                        $platforms_map = \App\Models\Platform::pluck('name', 'slug')->toArray();
                        $formattedOptions = [];
                        foreach ($platforms as $p) {
                            $formattedOptions[$p] = $platforms_map[$p] ?? $p;
                        }

                        return $formattedOptions;
                    })
                    ->query(function (Builder $query, array $data) {
                        if (!empty($data['value'])) {
                            $query->whereHas('account', fn($q) => $q->where('platform', $data['value']));
                        }
                    })
                    ->searchable()
                    ->visible(static::class === \App\Filament\Resources\RebateTrackerResource::class)
                    ->columnSpan(1),

                // Bộ lọc Trạng thái (CHỈ HIỆN TRẠNG THÁI ĐÃ CÓ TRONG DỮ LIỆU THỰC TẾ)
                Tables\Filters\SelectFilter::make('status')
                    ->label(__('system.labels.status'))
                    ->options(function () {
                        // 1. Quét tìm các status đang thực sự tồn tại trong DB
                        $activeStatuses = \App\Models\RebateTracker::whereNotNull('status')
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
                    ->visible(fn() => auth()->user()?->isAdmin()) // 🟢 ẨN KHỎI NHÂN VIÊN
                    ->options(function () {
                        // 1. Quét lấy danh sách user_id đang thực sự có đơn
                        $activeUserIds = \App\Models\RebateTracker::whereNotNull('user_id')
                            ->distinct()
                            ->pluck('user_id');

                        // 2. Lấy tên của đúng những User đó
                        return \App\Models\User::whereIn('id', $activeUserIds)
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
                        fn() => \App\Models\RebateTracker::select('store_name')
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
                        \Filament\Forms\Components\DatePicker::make('transaction_from')->label(__('system.trackers.filters.transaction_from')),
                        \Filament\Forms\Components\DatePicker::make('transaction_to')->label(__('system.trackers.filters.transaction_to')),
                    ])
                    ->columns(2)     // 👈 Ép 2 ô Date nằm ngang nhau
                    ->columnSpan(2)  // 👈 Chiếm 2 phần lưới
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when($data['transaction_from'], fn($q, $date) => $q->whereDate('transaction_date', '>=', $date))
                            ->when($data['transaction_to'], fn($q, $date) => $q->whereDate('transaction_date', '<=', $date));
                    }),

                // Lọc theo Ngày Payout (Từ ngày - Đến ngày)
                Tables\Filters\Filter::make('payout_date')
                    ->form([
                        \Filament\Forms\Components\DatePicker::make('payout_from')->label(__('system.trackers.filters.payout_from')),
                        \Filament\Forms\Components\DatePicker::make('payout_to')->label(__('system.trackers.filters.payout_to')),
                    ])
                    ->columns(2)     // 👈 Ép 2 ô Date nằm ngang nhau
                    ->columnSpan(2)  // 👈 Chiếm 2 phần lưới
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when($data['payout_from'], fn($q, $date) => $q->whereDate('payout_date', '>=', $date))
                            ->when($data['payout_to'], fn($q, $date) => $q->whereDate('payout_date', '<=', $date));
                    }),
                Tables\Filters\TrashedFilter::make(), // 🟢 BẬT TÍNH NĂNG THÙNG RÁC
            ])
            ->filtersLayout(\Filament\Tables\Enums\FiltersLayout::AboveContentCollapsible)

            // 2. CHÌA KHÓA Ở ĐÂY: TỰ ĐỘNG CHIA 5 CỘT HOẶC 4 CỘT TÙY VÀO TRANG ĐANG XEM
            ->filtersFormColumns(static::class === \App\Filament\Resources\RebateTrackerResource::class ? 5 : 4)

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
                            Forms\Components\TextInput::make('store_name')->required(),
                            Forms\Components\TextInput::make('order_value')->numeric()->required(),
                        ])
                        ->beforeReplicaSaved(function ($replica, $data) {
                            // Ghi đè dữ liệu mới vào bản sao
                            $replica->fill($data);
                            $replica->status = 'clicked'; // Reset trạng thái về mặc định
                            $replica->rebate_amount = (float) $data['order_value'] * ($replica->cashback_percent / 100);
                        }),
                    Tables\Actions\RestoreAction::make(), // 🟢 Nút khôi phục dòng bị xóa
                    Tables\Actions\ForceDeleteAction::make()
                        ->visible(fn() => auth()->user()?->isAdmin()),
                    Tables\Actions\EditAction::make(),
                    Tables\Actions\DeleteAction::make(),
                ])
            ])

            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    // NÚT XUẤT GOOGLE SHEET (18 CỘT)
                    Tables\Actions\BulkAction::make('export_to_google_sheet')
                        ->label(__('system.actions.export_to_sheet'))
                        ->icon('heroicon-o-table-cells')
                        ->color('success')
                        ->visible(fn() => auth()->user()?->isAdmin())
                        ->requiresConfirmation()
                        ->action(function (\Illuminate\Database\Eloquent\Collection $records, \App\Services\GoogleSyncService $syncService) {
                            try {
                                $syncService->syncTrackers($records);

                                \Filament\Notifications\Notification::make()
                                    ->title(__('system.notifications.sync_success'))
                                    ->success()
                                    ->send();
                            } catch (\Exception $e) {
                                \Filament\Notifications\Notification::make()
                                    ->title(__('system.notifications.sync_error'))
                                    ->body($e->getMessage())
                                    ->danger()
                                    ->send();
                            }
                        })
                        ->deselectRecordsAfterCompletion(),
                    // Tự động bỏ tick sau khi xuất xong



                    // Nút đổi nhanh sang Pending
                    static::makeBulkStatusAction('pending', __('system.actions.mark_as_pending') ?: 'Mark as Pending', 'heroicon-o-clock', 'info'),
                    // Nút đổi nhanh sang Confirme
                    static::makeBulkStatusAction('confirmed', __('system.actions.mark_as_confirmed') ?: 'Mark as Confirmed', 'heroicon-o-check-badge', 'success'),

                    Tables\Actions\RestoreBulkAction::make(),     // 🟢 Khôi phục nhiều dòng
                    Tables\Actions\ForceDeleteBulkAction::make()
                        ->visible(fn() => auth()->user()?->isAdmin()),
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    /**
     * 🟢 HÀM GỘP: Xử lý đổi trạng thái và đồng bộ cho hàng loạt record
     */
    public static function bulkUpdateStatus(\Illuminate\Database\Eloquent\Collection $records, string $newStatus): void
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
        \Filament\Notifications\Notification::make()
            ->title("Đã chuyển {$count} dòng thành " . ucfirst($newStatus))
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
        return Tables\Actions\BulkAction::make('markAs' . ucfirst($status))
            ->label($label)
            ->icon($icon)
            ->color($color)
            ->requiresConfirmation()
            ->action(fn(\Illuminate\Database\Eloquent\Collection $records) => static::bulkUpdateStatus($records, $status));
    }

    // =========================================================
    // DÁN ĐOẠN NÀY VÀO ĐÂY (NẰM NGOÀI HÀM TABLE)
    // =========================================================
    public static function syncTrackerWithService($record): void
    {
        app(\App\Services\GoogleSyncService::class)->syncTrackers(collect([$record]));
    }



    // =========================================================

}
