<?php

namespace App\Filament\Resources\Traits;

use Filament\Infolists\Infolist;
use Filament\Support\Enums\Alignment;
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

trait HasTrackerSchema
{
    // FIX #8: $usStates được kế thừa từ HasUsStates — KHÔNG khai báo lại ở đây.
    use HasUsStates;

    /**
     * Scope lọc các bản ghi đang ở trạng thái có thể rút tiền
     */
    public function scopeWhereReadyForPayout(Builder $query)
    {
        return $query->whereIn('status', ['pending', 'confirmed', 'Pending', 'Confirmed']);
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                // SECTION 1: Account & Platform
                Forms\Components\Section::make('Account & Platform')
                    ->schema([
                        Forms\Components\Grid::make(4)
                            ->schema([
                                // USER
                                Forms\Components\Select::make('user_id')
                                    ->label('User')
                                    ->relationship('user', 'name')
                                    ->default(auth()->id())
                                    ->searchable()
                                    ->preload()
                                    ->live() // Sử dụng live() để các field sau nhận diện thay đổi ngay
                                    ->afterStateUpdated(function ($set) {
                                        $set('platform', null);
                                        $set('account_email', null);
                                        $set('account_password_display', null);
                                    })
                                    ->columnSpan(1),

                                // SELECT PLATFORM
                                Forms\Components\Select::make('platform')
                                    ->label('Platform')
                                    ->options(function ($get, $record) {
                                        // Ưu tiên User đang chọn, nếu không có lấy User từ bản ghi cũ
                                        $userId = $get('user_id') ?? $record?->user_id;
                                        if (!$userId) return [];

                                        return \App\Models\Account::where('user_id', $userId)
                                            ->distinct()
                                            ->pluck('platform', 'platform')
                                            ->mapWithKeys(fn($p) => [$p => ucfirst((string)$p)]);
                                    })
                                    ->live()
                                    ->required()
                                    // HIỆN THÔNG BÁO LỖI DƯỚI Ô PLATFORM NẾU CẦN
                                    ->helperText(function ($record) {
                                        if ($record && $record->account && $record->account->user_id === null) {
                                            return new \Illuminate\Support\HtmlString(
                                                '<span class="text-danger-600 font-bold animate-pulse">⚠️ Holder: N/A. Please click "Get Account" in Platform to fix!</span>'
                                            );
                                        }
                                        return null;
                                    })
                                    // ÉP NẠP DỮ LIỆU KHI EDIT
                                    ->afterStateHydrated(function ($state, $set, $record) {
                                        if ($record && !$state) {
                                            // Lấy platform từ account liên kết nếu field platform trên form đang trống
                                            $set('platform', $record->account?->platform);
                                        }
                                    })
                                    ->afterStateUpdated(function ($set) {
                                        $set('account_email', null);
                                        $set('account_password_display', null);
                                    })
                                    ->columnSpan(1),

                                // SELECT EMAIL
                                Forms\Components\Select::make('account_email')
                                    ->label('Select Account Email')
                                    ->options(function ($get, $record) {
                                        $userId = $get('user_id') ?? $record?->user_id;
                                        // Quan trọng: Phải lấy được platform hiện tại
                                        $platform = $get('platform')
                                            ?? ($get('account_id') ? \App\Models\Account::find($get('account_id'))?->platform : null)
                                            ?? $record?->account?->platform;

                                        if (!$userId || !$platform) return [];

                                        return \App\Models\Account::where('user_id', $userId)
                                            ->where('platform', $platform)
                                            ->with('email')
                                            ->get()
                                            ->mapWithKeys(function ($account) {
                                                // 1. Tính tổng PENDING (từ RebateTracker: status 'pending' hoặc 'clicked')
                                                $pendingAmount = \App\Models\RebateTracker::where('account_id', $account->id)
                                                    ->whereIn('status', ['pending', 'clicked', 'Pending', 'Clicked'])
                                                    ->sum('rebate_amount') ?? 0;

                                                // 2. Tính tổng CONFIRMED gốc (từ RebateTracker: status 'confirmed')
                                                $totalConfirmed = \App\Models\RebateTracker::where('account_id', $account->id)
                                                    ->whereIn('status', ['confirmed', 'Confirmed'])
                                                    ->sum('rebate_amount') ?? 0;

                                                // 3. Tính tổng ĐÃ RÚT (từ PayoutLog: sử dụng cột 'amount_usd')
                                                // 💡 Chỉ tính các giao dịch loại 'Withdrawal' (Rút tiền từ web về ví)
                                                $paidAmount = \App\Models\PayoutLog::where('account_id', $account->id)
                                                    ->whereIn('transaction_type', ['withdrawal', 'hold']) // 🟢 Chấp nhận cả 2 loại rút
                                                    ->where('status', 'completed')
                                                    ->sum('amount_usd') ?? 0;

                                                // 4. Số dư khả dụng thực tế = Tổng xác nhận - Tổng đã rút
                                                $availableConfirmed = max(0, $totalConfirmed - $paidAmount);

                                                $email = $account->email?->email ?? 'N/A';
                                                $pendingStr = number_format($pendingAmount, 2);
                                                $confirmedStr = number_format($availableConfirmed, 2);

                                                // Hiển thị: email@gmail.com ➔ [P: $10.00] - [C: $5.00]
                                                return [$email => "{$email} ➔ [Pending: \${$pendingStr}] - [Confirmed: \${$confirmedStr}]"];
                                            });
                                    })
                                    ->searchable()
                                    ->live()
                                    ->required()
                                    // ÉP NẠP DỮ LIỆU KHI EDIT
                                    ->afterStateHydrated(function ($state, $set, $record) {
                                        if ($record && !$state) {
                                            // Lấy email thực tế từ quan hệ account
                                            $set('account_email', $record->account?->email?->email);
                                            // Nạp luôn password display cho đồng bộ
                                            $set('account_password_display', $record->account?->password);
                                        }
                                    })
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
                                    ->columnSpan(1),

                                // SHOW PASSWORD
                                Forms\Components\TextInput::make('account_password_display')
                                    ->label('Account Password')
                                    ->readonly()
                                    ->password()
                                    ->revealable()
                                    ->dehydrated(false)
                                    ->formatStateUsing(fn($record) => $record?->account?->password)
                                    ->suffixAction(
                                        Forms\Components\Actions\Action::make('copyPassword')
                                            ->icon('heroicon-m-clipboard')
                                            ->color('warning')
                                            ->action(function (Forms\Get $get, $livewire) {
                                                $accountId = $get('account_id');
                                                $password = \App\Models\Account::find($accountId)?->password;

                                                if ($password) {
                                                    $livewire->dispatch('copy-to-clipboard', text: $password);

                                                    \Filament\Notifications\Notification::make()
                                                        ->title('Copied!')
                                                        ->success()
                                                        ->send();
                                                }
                                            })
                                    )
                                    ->columnSpan(1),
                            ])
                            ->columns(2),

                        // STATUS DISPLAY & TRACKING 
                        Forms\Components\Placeholder::make('account_status_display')
                            ->label('Account Status Tracking')
                            ->visible(fn($get) => $get('account_email'))
                            ->content(function ($get) {
                                $emailState = $get('account_email');
                                $account = \App\Models\Account::whereHas('email', fn($q) => $q->where('email', $emailState))->first();

                                if (!$account) return new \Illuminate\Support\HtmlString("<div class='text-danger'>⚠️ No account found</div>");

                                $statuses = (array) $account->status;
                                if (empty($statuses)) return "No status available.";

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
                                        'used'             => 'In Use',
                                        'limited'          => 'PayPal Limited',
                                        'linked'           => 'Linked PayPal',
                                        'unlinked'         => 'Unlinked PayPal',
                                        'not_linked'       => 'Not Linked to PayPal',
                                        'no_paypal_needed' => 'No PayPal Required',
                                        default            => ucfirst(str_replace('_', ' ', (string)$status)),
                                    };

                                    $arrow = ($index < count($statuses) - 1) ? " <span style='color: #d1d5db; margin: 0 4px;'>→</span> " : "";
                                    return "<span style='color: {$color}; font-weight: 800; font-size: 0.85rem;'>{$label}</span>{$arrow}";
                                })->implode('');

                                return new \Illuminate\Support\HtmlString("<div style='padding:12px; background:#f0f9ff; border-radius:8px;'>{$htmlResult}</div>");
                            })
                            ->columnSpanFull(),

                        // Account ID
                        Forms\Components\Hidden::make('account_id')->required(),
                    ])->columnSpanFull(),

                // SECTION 2: Order Details
                Forms\Components\Section::make('Order Details & Rebate')
                    ->schema([
                        Forms\Components\Grid::make(3)
                            ->schema([
                                Forms\Components\DatePicker::make('transaction_date')
                                    ->label('Transaction date')
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
                                    ->label('Payout date')
                                    ->placeholder('dd/mm/yyyy')
                                    ->displayFormat('d/m/Y')
                                    ->format('Y-m-d') // Định dạng chuẩn để lưu vào MySQL
                                    ->native(false)
                                    ->closeOnDateSelection()
                                    // Logic Validation: Phải sau hoặc bằng ngày giao dịch
                                    ->after('transaction_date')
                                    ->validationMessages([
                                        'after' => 'The payout date must not be before the transaction date.'
                                    ])
                                    ->nullable() // Cho phép để trống
                                    ->default(null)
                                    ->columns(5), // Đảm bảo không tự động lấy ngày hiện tại

                                Forms\Components\Select::make('status')
                                    ->label('Status')
                                    ->options([
                                        'pending' => 'Pending',
                                        'confirmed' => 'Confirmed',
                                        'ineligible' => 'Ineligible',
                                        'missing' => 'Missing',
                                        'clicked' => 'Clicked / Ordered',
                                    ])
                                    ->default('clicked')
                                    ->required(),
                            ]),
                        Forms\Components\Grid::make(5)
                            ->schema([
                                Forms\Components\TextInput::make('store_name')
                                    ->label('Store Name')
                                    ->required(),

                                Forms\Components\TextInput::make('order_id')
                                    ->label('Order ID (#)'),

                                Forms\Components\TextInput::make('order_value')
                                    ->label('Order value ($)')
                                    ->numeric()
                                    ->prefix('$')
                                    ->reactive()
                                    ->required(),

                                Forms\Components\TextInput::make('cashback_percent')
                                    ->label('% Cashback')
                                    ->numeric()
                                    ->suffix('%')
                                    ->reactive()
                                    ->default(10),

                                Forms\Components\Placeholder::make('rebate_amount_display')
                                    ->label('Rebate Amount ($)')
                                    ->content(function ($get) {
                                        $total = (float)$get('order_value') * ((float)$get('cashback_percent') / 100);
                                        return '$ ' . number_format($total, 2);
                                    })
                                    ->extraAttributes(['class' => 'text-success font-bold text-xl']),
                            ]),
                    ])->columnSpanFull(),

                // SECTION 3: Logistics
                Forms\Components\Section::make('Logistics & Note')
                    ->schema([
                        Forms\Components\Grid::make(2)
                            ->schema([
                                Forms\Components\TextInput::make('device')
                                    ->label('Device')
                                    ->placeholder('iOS, VMware, BitBrowser Antidetect...'),
                                Forms\Components\Select::make('state')
                                    ->label('State (US)')
                                    ->searchable()
                                    ->options(self::$usStates),
                            ]),
                        Forms\Components\Textarea::make('note')
                            ->label('Note')
                            ->columnSpanFull()
                            ->rows(5),
                        Forms\Components\Textarea::make('detail_transaction')
                            ->label('Transaction Details')
                            ->columnSpanFull()
                            ->rows(5),
                    ])->columnSpanFull(),
            ]);
    }

    public static function infolist(\Filament\Infolists\Infolist $infolist): \Filament\Infolists\Infolist
    {
        return $infolist
            ->schema([
                // PHẦN 1: EMAIL INFORMATION
                \Filament\Infolists\Components\Section::make('Account & Platform')
                    ->schema([
                        \Filament\Infolists\Components\TextEntry::make('account.email.email')
                            ->label('Email Address')
                            ->placeholder('N/A')
                            ->copyable(), // Cho phép click để copy nhanh
                        // Password (Lấy từ quan hệ: account -> password)
                        \Filament\Infolists\Components\TextEntry::make('account.password')
                            ->label('Password')
                            ->placeholder('N/A'),
                        // Platform (Lấy từ quan hệ: account -> platform)
                        \Filament\Infolists\Components\TextEntry::make('account.platform')
                            ->label('Platform')
                            ->placeholder('N/A'),
                        // User (Để hiện tên thay vì ID số 1)
                        \Filament\Infolists\Components\TextEntry::make('user.name')
                            ->label('User')
                            ->placeholder('N/A'),
                        // Status (Đảm bảo đúng tên cột trong DB
                        \Filament\Infolists\Components\TextEntry::make('account.status')
                            ->label('Account Status Tracking')
                            ->html() // Bắt buộc phải có để Filament render thẻ <span> và <div>
                            ->placeholder('No status history found.')
                            ->formatStateUsing(function ($state, $record) {
                                // Lấy account từ record hiện tại của Infolist
                                $account = $record->account;
                                if (!$account || !$account->status) return null;

                                // Đảm bảo dữ liệu là mảng để chạy vòng lặp map
                                $statusHistory = is_array($account->status)
                                    ? $account->status
                                    : json_decode($account->status, true) ?? [$account->status];

                                $htmlResult = collect($statusHistory)->map(function ($status, $index) use ($statusHistory) {
                                    $color = match ($status) {
                                        'active'           => '#6b7280',
                                        'used'             => '#3b82f6',
                                        'no_paypal_needed' => '#1e3a8a',
                                        'not_linked', 'unlinked' => '#f59e0b',
                                        'linked'           => '#22c55e',
                                        'limited', 'banned' => '#ef4444',
                                        default            => '#6b7280'
                                    };

                                    $label = match ($status) {
                                        'used'             => 'In Use',
                                        'limited'          => 'PayPal Limited',
                                        'linked'           => 'Linked PayPal',
                                        'unlinked'         => 'Unlinked PayPal',
                                        'not_linked'       => 'Not Linked to PayPal',
                                        'no_paypal_needed' => 'No PayPal Required',
                                        default            => ucfirst(str_replace('_', ' ', (string)$status)),
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
                \Filament\Infolists\Components\Section::make('Order Details & Rebate')
                    ->schema([
                        \Filament\Infolists\Components\TextEntry::make('transaction_date')
                            ->label('Transaction Date')
                            ->dateTime('d/m/Y')
                            ->placeholder('N/A'),
                        \Filament\Infolists\Components\TextEntry::make('payout_date')
                            ->label('Payout Date')
                            ->dateTime('d/m/Y')
                            ->placeholder('N/A'),
                        \Filament\Infolists\Components\TextEntry::make('status')
                            ->label('Status')
                            ->placeholder('N/A')
                            ->badge()
                            ->icon(fn(string $state): string => match ($state) {
                                'clicked'     => 'heroicon-m-cursor-arrow-rays',
                                'pending'     => 'heroicon-m-clock',
                                'confirmed'   => 'heroicon-m-check-badge',
                                'missing'     => 'heroicon-m-magnifying-glass', // Hình kính lúp tìm kiếm
                                'ineligible'  => 'heroicon-m-x-circle',        // Hình dấu X tròn
                                default       => 'heroicon-m-question-mark-circle',
                            })
                            ->formatStateUsing(fn(string $state): string => match ($state) {
                                'clicked'   => 'Clicked',
                                'pending' => 'Pending',
                                'confirmed'  => 'Confirmed',
                                'missing'  => 'Missing',
                                'ineligible' => 'Ineligible',
                                default   => ucfirst($state), // Các nhãn khác chỉ viết hoa chữ cái đầu
                            })
                            ->color(fn(string $state): string => match ($state) {
                                'clicked' => 'gray',
                                'pending'   => 'info',
                                'confirmed' => 'success',
                                'missing' => 'warning',
                                'ineligible' => 'danger',
                                default  => 'gray',
                            }),
                        \Filament\Infolists\Components\TextEntry::make('store_name')
                            ->label('Store name')
                            ->placeholder('N/A'),

                        \Filament\Infolists\Components\TextEntry::make('order_id')
                            ->label('Order ID')
                            ->placeholder('N/A'),
                        \Filament\Infolists\Components\TextEntry::make('order_value')
                            ->label('Order ($)')
                            ->placeholder('N/A'),
                        \Filament\Infolists\Components\TextEntry::make('cashback_percent')
                            ->label('Cashback Percent (%)')
                            ->placeholder('N/A'),
                        \Filament\Infolists\Components\TextEntry::make('rebate_amount')
                            ->label('Cashback ($)')
                            ->money('USD')
                            ->weight(FontWeight::Bold)
                            ->color('success'),
                    ])->columns(3),

                // PHẦN 3: Logistics & Note
                \Filament\Infolists\Components\Section::make('Logistics & Note')
                    ->schema([
                        \Filament\Infolists\Components\TextEntry::make('device')
                            ->label('Device')
                            ->placeholder('N/A'),
                        \Filament\Infolists\Components\TextEntry::make('state')
                            ->label('State')
                            ->placeholder('N/A')
                            ->formatStateUsing(fn($state) => $state ? "{$state} - " . (self::$usStates[$state] ?? '') : 'N/A'),
                        \Filament\Infolists\Components\TextEntry::make('note')
                            ->label('Note')
                            ->placeholder('N/A')
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
                            ->label('Detail Transaction')
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
            ->defaultGroup('account_id')
            ->groups([
                Group::make('account_id')
                    ->label('Account')
                    ->collapsible()
                    ->getTitleFromRecordUsing(function ($record) {
                        // Hàng Header bây giờ CHỈ HIỆN EMAIL
                        return $record->account?->email?->email ?? 'N/A';
                    }),
            ])
            ->columns([
                // PLATFORM => ALL REBATE TRACKER/ HIDE: SUB-TRACKER
                Tables\Columns\TextColumn::make('account.platform')
                    ->label('Platform')
                    ->alignment(Alignment::Center)
                    ->searchable(query: function ($query, $search) {
                        $query->whereHas('account', fn($q) => $q->where('platform', 'like', "%{$search}%"));
                    })
                    ->visible(static::class === \App\Filament\Resources\RebateTrackerResource::class),

                // 1. STORE (Đẩy lùi vào để phân cấp)
                Tables\Columns\TextColumn::make('store_name')
                    ->label('Store')
                    ->weight('medium')
                    ->icon('heroicon-m-shopping-bag')
                    ->iconColor('gray')
                    ->alignment(Alignment::Center)
                    ->extraAttributes(['class' => 'pl-10'])
                    ->wrap()
                    ->width('20%')
                    ->searchable(),



                // 2. ORDER VALUE
                Tables\Columns\TextColumn::make('order_value')
                    ->label('Order ($)')
                    ->money('USD')
                    ->alignment(Alignment::Center),

                // 3. CASHBACK PERCENT
                Tables\Columns\TextColumn::make('cashback_percent')
                    ->label('Percent (%)')
                    ->numeric(2)
                    ->suffix('%')
                    ->alignment(Alignment::Center),

                // 4. CASHBACK ($) - ĐÂY LÀ CHÌA KHÓA
                Tables\Columns\TextColumn::make('rebate_amount')
                    ->label('Cashback ($)')
                    ->money('USD')
                    ->color('success')
                    ->weight('bold')
                    ->alignment(Alignment::Center)
                    // Summarize này sẽ tự động hiển thị ở dòng tổng của GROUP (Header/Footer)
                    // và nó sẽ LUÔN THẲNG HÀNG với cột này.
                    ->summarize(
                        Tables\Columns\Summarizers\Sum::make()
                            // 1. Xóa bỏ chữ "Summary" ở cột Account (cột đầu bảng)
                            ->label('') // Triệt tiêu chữ "Summary" mặc định của Filament
                            ->money('USD')
                    ),

                // 5. STATUS
                Tables\Columns\TextColumn::make('status')
                    ->label('Status')
                    ->alignment(Alignment::Center)
                    ->badge()
                    // 1. Giữ nguyên Icon trạng thái ở phía trước (trái)
                    ->icon(fn(string $state): string => match ($state) {
                        'clicked'     => 'heroicon-m-cursor-arrow-rays',
                        'pending'     => 'heroicon-m-clock',
                        'confirmed'   => 'heroicon-m-check-badge',
                        'missing'     => 'heroicon-m-magnifying-glass',
                        'ineligible'  => 'heroicon-m-x-circle',
                        default       => 'heroicon-m-question-mark-circle',
                    })
                    // 2. Dùng formatStateUsing để "vẽ" thêm icon bút chì vào phía sau (phải)
                    ->formatStateUsing(fn(string $state) => new \Illuminate\Support\HtmlString('
                        <div class="flex items-center gap-1.5 justify-center">
                            <span>' . ucfirst($state) . '</span>
                                ' . \Illuminate\Support\Facades\Blade::render('<x-heroicon-m-pencil-square class="w-4 h-4 text-gray-400" />') . '
                         </div>
                    '))
                    ->color(fn(string $state): string => match ($state) {
                        'confirmed'  => 'success',
                        'pending'    => 'info',
                        'missing'    => 'warning',
                        'ineligible' => 'danger',
                        default      => 'gray',
                    })
                    ->action(
                        Tables\Actions\Action::make('quick_set_status')
                            ->form([
                                Forms\Components\Select::make('status')
                                    ->options([
                                        'clicked'    => 'Clicked',
                                        'pending'    => 'Pending',
                                        'confirmed'  => 'Confirmed',
                                        'missing'    => 'Missing',
                                        'ineligible' => 'Ineligible',                                     
                                    ])
                                    ->default(fn($record) => $record->status)
                                    ->required(),
                            ])
                            ->action(function ($record, array $data) {
                                // 1. Lưu vào Database
                                $record->update($data);

                                // 2. Gọi Cỗ máy để đẩy lên Google Sheet
                                static::syncSingleRecordToSheet($record);

                                \Filament\Notifications\Notification::make()
                                    ->title('Status has been updated and synchronized with Google Sheets!')
                                    ->success()
                                    ->send();
                            })
                    ),

                // 6. TIMELINE
                Tables\Columns\TextColumn::make('transaction_date')
                    ->label('Transaction Date')
                    ->placeholder('N/A')
                    ->date('d/m/Y')
                    ->alignment(Alignment::Center)
                    ->sortable(),
                Tables\Columns\TextColumn::make('payout_date')
                    ->label('Payout Date')
                    ->alignment(Alignment::Center)
                    ->sortable()
                    // 1. Dùng state() để ép giá trị null thành chuỗi 'N/A' TRƯỚC khi render
                    ->state(fn($record) => $record->payout_date ? $record->payout_date : 'N/A')
                    // 2. Định dạng hiển thị: Nếu là 'N/A' thì giữ nguyên, nếu là ngày thì format
                    ->formatStateUsing(function ($state) {
                        if ($state === 'N/A') return $state;
                        try {
                            return \Carbon\Carbon::parse($state)->format('d/m/Y');
                        } catch (\Exception $e) {
                            return $state;
                        }
                    })
                    // 3. Các thuộc tính giao diện (Badge và Icon sẽ hiện cho cả N/A)
                    ->icon('heroicon-m-pencil-square')
                    // 4. Action bấm vào để sửa
                    ->action(
                        Tables\Actions\Action::make('quick_set_date')
                            ->form([
                                Forms\Components\DatePicker::make('payout_date')
                                    ->label('Select Payout Date')
                                    ->default(fn($record) => $record->payout_date ?? now())
                                    ->required(),
                            ])
                            ->action(function ($record, array $data) {
                                $record->update($data);
                                static::syncSingleRecordToSheet($record);

                                \Filament\Notifications\Notification::make()
                                    ->title('Payout Date has been updated and synchronized with Google Sheets!')
                                    ->success()
                                    ->send();
                            })
                    ),
            ])
            ->striped()


            ->filters([
                // Lọc theo Tài khoản (Email)
                Tables\Filters\SelectFilter::make('account_id')
                    ->label('Account Email')
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
                    ->columnSpan(1),

                // Bộ lọc Platform (Quan trọng để Sub-menu chạy)
                Tables\Filters\SelectFilter::make('platform')
                    ->label('Platform')
                    ->options(function () {
                        // Tương tự, chỉ lấy những Platform của các Account đã có đơn
                        $activeAccountIds = \App\Models\RebateTracker::whereNotNull('account_id')
                            ->distinct()
                            ->pluck('account_id');

                        return \App\Models\Account::whereIn('id', $activeAccountIds)
                            ->whereNotNull('platform')
                            ->distinct()
                            ->pluck('platform', 'platform')
                            ->toArray();
                    })
                    ->query(function (\Illuminate\Database\Eloquent\Builder $query, array $data) {
                        if (!empty($data['value'])) {
                            $query->whereHas('account', fn($q) => $q->where('platform', $data['value']));
                        }
                    })
                    ->searchable()
                    ->visible(static::class === \App\Filament\Resources\RebateTrackerResource::class)
                    ->columnSpan(1),

                // Bộ lọc Trạng thái (CHỈ HIỆN TRẠNG THÁI ĐÃ CÓ TRONG DỮ LIỆU THỰC TẾ)
                Tables\Filters\SelectFilter::make('status')
                    ->label('Status')
                    ->options(function () {
                        // 1. Quét tìm các status đang thực sự tồn tại trong DB
                        $activeStatuses = \App\Models\RebateTracker::whereNotNull('status')
                            ->distinct()
                            ->pluck('status');

                        // 2. Bộ từ điển dịch tên Status cho đẹp
                        $labels = [
                            'pending'    => 'Pending',
                            'confirmed'  => 'Confirmed',
                            'ineligible' => 'Ineligible',
                            'missing'    => 'Missing',
                            'clicked'    => 'Clicked / Ordered',
                        ];

                        // 3. Ráp dữ liệu: Chỉ tạo Option cho những Status quét được ở Bước 1
                        $options = [];
                        foreach ($activeStatuses as $st) {
                            // Nếu có trong từ điển thì lấy từ điển, nếu status lạ thì tự viết hoa chữ cái đầu
                            $options[$st] = $labels[$st] ?? ucfirst(trim((string)$st));
                        }

                        return $options;
                    })
                    ->multiple()
                    ->columnSpan(1),

                // Bộ lọc theo User (CHỈ HIỆN USER ĐÃ CÓ ĐƠN)
                Tables\Filters\SelectFilter::make('user_id')
                    ->label('User')
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
                    ->label('Store Name')
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
                        \Filament\Forms\Components\DatePicker::make('transaction_from')->label('Transaction From'),
                        \Filament\Forms\Components\DatePicker::make('transaction_to')->label('Transaction To'),
                    ])
                    ->columns(2)     // 👈 Ép 2 ô Date nằm ngang nhau
                    ->columnSpan(2)  // 👈 Chiếm 2 phần lưới
                    ->query(function (\Illuminate\Database\Eloquent\Builder $query, array $data): \Illuminate\Database\Eloquent\Builder {
                        return $query
                            ->when($data['transaction_from'], fn($q, $date) => $q->whereDate('transaction_date', '>=', $date))
                            ->when($data['transaction_to'], fn($q, $date) => $q->whereDate('transaction_date', '<=', $date));
                    }),

                // Lọc theo Ngày Payout (Từ ngày - Đến ngày)
                Tables\Filters\Filter::make('payout_date')
                    ->form([
                        \Filament\Forms\Components\DatePicker::make('payout_from')->label('Payout From'),
                        \Filament\Forms\Components\DatePicker::make('payout_to')->label('Payout To'),
                    ])
                    ->columns(2)     // 👈 Ép 2 ô Date nằm ngang nhau
                    ->columnSpan(2)  // 👈 Chiếm 2 phần lưới
                    ->query(function (\Illuminate\Database\Eloquent\Builder $query, array $data): \Illuminate\Database\Eloquent\Builder {
                        return $query
                            ->when($data['payout_from'], fn($q, $date) => $q->whereDate('payout_date', '>=', $date))
                            ->when($data['payout_to'], fn($q, $date) => $q->whereDate('payout_date', '<=', $date));
                    }),
            ])
            // 1. ÉP BỘ LỌC HIỂN THỊ LỘ THIÊN LÊN TRÊN CÙNG
            ->filtersLayout(\Filament\Tables\Enums\FiltersLayout::AboveContent)

            // 2. CHÌA KHÓA Ở ĐÂY: TỰ ĐỘNG CHIA 5 CỘT HOẶC 4 CỘT TÙY VÀO TRANG ĐANG XEM
            ->filtersFormColumns(static::class === \App\Filament\Resources\RebateTrackerResource::class ? 5 : 4)

            ->actions([
                // Nút Xem chi tiết (Hình con mắt) hiện ra bên ngoài
                Tables\Actions\ViewAction::make()
                    ->label('') // Để trống nhãn để chỉ hiện icon cho gọn
                    ->modalHeading('Account Details') // TIÊU ĐỀ CỦA MODAL
                    ->tooltip('Details') // Hiện ghi chú khi di chuột vào
                    ->icon('heroicon-o-eye')
                    ->color('gray'), // Màu xám nhẹ nhàng, không lấn át nút cam

                Tables\Actions\ActionGroup::make([
                    // Thêm nút nhân bản
                    Tables\Actions\ReplicateAction::make()
                        ->label('Replicate')
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
                            $replica->rebate_amount = (float)$data['order_value'] * ($replica->cashback_percent / 100);
                        }),
                    Tables\Actions\EditAction::make(),
                    Tables\Actions\DeleteAction::make(),
                ])
            ])

            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    // NÚT XUẤT GOOGLE SHEET (18 CỘT)
                    Tables\Actions\BulkAction::make('export_to_google_sheet')
                        ->label('Export to Google Sheet')
                        ->icon('heroicon-o-table-cells')
                        ->color('success')
                        ->action(function (\Illuminate\Database\Eloquent\Collection $records) {
                            $sheetService = app(\App\Services\GoogleSheetService::class);

                            // Nhóm các bản ghi theo Platform để gửi API theo từng đợt (Batch)
                            $groupedRecords = $records->groupBy(fn($record) => $record->account?->platform ?: 'General');

                            foreach ($groupedRecords as $platform => $group) {
                                $rows = $group->map(fn($record) => static::formatRecordForSheet($record))->values()->toArray();
                                // FIX #2: ucfirst để đúng tên tab (Rakuten_Tracker, không phải rakuten_Tracker)
                                $targetTab = ucfirst($platform) . '_Tracker';

                                // FIX #3: truyền $headers để tab mới có hàng tiêu đề
                                $sheetService->createSheetIfNotExist($targetTab);
                                $sheetService->upsertRows($rows, $targetTab, static::$trackerHeaders);
                                $sheetService->formatColumnsAsClip($targetTab, 5, 6);
                                $sheetService->formatColumnsAsClip($targetTab, 15, 16);
                                $sheetService->formatColumnsAsClip($targetTab, 18, 19);

                                // Sync vào Tab tổng
                                $sheetService->upsertRows($rows, 'All_Rebate_Tracker', static::$trackerHeaders);
                                $sheetService->formatColumnsAsClip('All_Rebate_Tracker', 5, 6);
                                $sheetService->formatColumnsAsClip('All_Rebate_Tracker', 15, 16);
                                $sheetService->formatColumnsAsClip('All_Rebate_Tracker', 18, 19);
                            }

                            \Filament\Notifications\Notification::make()
                                ->title('Bulk Sync Complete!')
                                ->success()
                                ->send();
                        })
                        ->deselectRecordsAfterCompletion(), // Tự động bỏ tick sau khi xuất xong

                    // Nút đổi nhanh sang Confirmed
                    Tables\Actions\BulkAction::make('markAsConfirmed')
                        ->label('Set as Confirmed')
                        ->icon('heroicon-o-check-badge')
                        ->color('success')
                        ->requiresConfirmation()
                        ->action(function (\Illuminate\Database\Eloquent\Collection $records) {
                            // FIX: khai báo $ids trước, 1 query UPDATE thay vì N lần
                            $ids = $records->modelKeys();
                            \App\Models\RebateTracker::whereIn('id', $ids)->update(['status' => 'confirmed']);

                            // Reload với relations để formatRecordForSheet() hoạt động đúng
                            $fresh = \App\Models\RebateTracker::with(['account.email', 'user'])
                                ->whereIn('id', $ids)->get();

                            $sheetService = app(\App\Services\GoogleSheetService::class);
                            $grouped = $fresh->groupBy(fn($r) => $r->account?->platform ?: 'General');

                            foreach ($grouped as $platform => $items) {
                                $rows = $items->map(fn($r) => static::formatRecordForSheet($r))->values()->toArray();
                                $sheetService->upsertRows($rows, 'All_Rebate_Tracker', static::$trackerHeaders);
                                $sheetService->upsertRows($rows, ucfirst($platform) . '_Tracker', static::$trackerHeaders);
                            }

                            \Filament\Notifications\Notification::make()->title('Updated & synchronized!')->success()->send();
                        }),

                    // Nút đổi nhanh sang Pending
                    Tables\Actions\BulkAction::make('markAsPending')
                        ->label('Set as Pending')
                        ->icon('heroicon-o-clock')
                        ->color('info')
                        ->action(function (\Illuminate\Database\Eloquent\Collection $records) {
                            // FIX: khai báo $ids trước, 1 query UPDATE thay vì N lần
                            $ids = $records->modelKeys();
                            \App\Models\RebateTracker::whereIn('id', $ids)->update(['status' => 'pending']);

                            // Reload với relations để formatRecordForSheet() hoạt động đúng
                            $fresh = \App\Models\RebateTracker::with(['account.email', 'user'])
                                ->whereIn('id', $ids)->get();

                            $sheetService = app(\App\Services\GoogleSheetService::class);
                            $grouped = $fresh->groupBy(fn($r) => $r->account?->platform ?: 'General');

                            foreach ($grouped as $platform => $items) {
                                $rows = $items->map(fn($r) => static::formatRecordForSheet($r))->values()->toArray();
                                $sheetService->upsertRows($rows, 'All_Rebate_Tracker', static::$trackerHeaders);
                                $sheetService->upsertRows($rows, ucfirst($platform) . '_Tracker', static::$trackerHeaders);
                            }

                            \Filament\Notifications\Notification::make()->title('Updated & synchronized!')->success()->send();
                        }),


                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    // =========================================================
    // DÁN ĐOẠN NÀY VÀO ĐÂY (NẰM NGOÀI HÀM TABLE)
    // =========================================================
    public static array $trackerHeaders = [
        'ID',
        'Email Address',
        'Password',
        'Platform',
        'User',
        'Account Status Tracking',
        'Transaction Date',
        'Store Name',
        'Order ID',
        'Order Value ($)',
        'Cashback Percent (%)',
        'Rebate Amount ($)',
        'Status',
        'Payout Date',
        'Device',
        'State',
        'Note',
        'Detail Transaction'
    ];

    public static function formatRecordForSheet($record): array
    {
        $rawStatuses = $record->account?->status;
        $statusArray = is_array($rawStatuses) ? $rawStatuses : (json_decode($rawStatuses, true) ?? [$rawStatuses]);
        $filteredStatuses = array_filter($statusArray);
        $mappedStatuses = array_map(function ($status) {
            return match ($status) {
                'used'             => 'In Use',
                'limited'          => 'PayPal Limited',
                'linked'           => 'Linked PayPal',
                'unlinked'         => 'Unlinked PayPal',
                'not_linked'       => 'Not Linked to PayPal',
                'no_paypal_needed' => 'No PayPal Required',
                default            => ucfirst(str_replace('_', ' ', (string)$status)),
            };
        }, $filteredStatuses);
        $statusString = implode(' → ', $mappedStatuses);

        $stateCode = $record->state;
        $stateName = $stateCode ? "{$stateCode} - " . (self::$usStates[$stateCode] ?? '') : 'N/A';

        $transDate = $record->transaction_date ? \Carbon\Carbon::parse($record->transaction_date)->format('Y-m-d') : 'N/A';
        $payoutDate = $record->payout_date ? \Carbon\Carbon::parse($record->payout_date)->format('Y-m-d') : 'N/A';

        $txStatus = match ($record->status) {
            'pending'    => 'Pending',
            'confirmed'  => 'Confirmed',
            'ineligible' => 'Ineligible',
            'missing'    => 'Missing',
            'clicked'    => 'Clicked / Ordered',
            default      => ucfirst(trim((string)$record->status ?: 'N/A')),
        };

        return [
            $record->id,                                        // 1. ID
            $record->account?->email?->email ?? 'N/A',          // 2. Email Address
            $record->account?->password ?? 'N/A',               // 3. Password
            $record->account?->platform ?? 'N/A',               // 4. Platform
            $record->user?->name ?? 'N/A',                      // 5. User
            $statusString ?: 'N/A',                             // 6. Account Status Tracking
            $transDate,                                         // 7. Transaction Date
            $record->store_name ?? 'N/A',                       // 8. Store name
            $record->order_id ?? 'N/A',                         // 9. Order ID
            $record->order_value ?? 0,                          // 10. Order value ($)
            $record->cashback_percent ?? 0,                     // 11. Cashback Percent (%)
            $record->rebate_amount ?? 0,                        // 12. Cashback ($)
            $txStatus ?: 'N/A',                                 // 13. Status
            $payoutDate,                                        // 14. Payout Date
            $record->device ?? 'N/A',                           // 15. Device
            $stateName,                                         // 16. State
            $record->note ?? 'N/A',                             // 17. Note
            $record->detail_transaction ?? 'N/A',               // 18. Detail Transaction
        ];
    }

    // =========================================================
    // bootHasTrackerSchema ĐÃ XÓA (FIX #7):
    // RebateTrackerObserver (đăng ký trong AppServiceProvider) đã xử lý
    // created/updated/deleted → không cần boot thêm tránh double-dispatch.
    // =========================================================

    public static function syncSingleRecordToSheet($record): void
    {
        $row = static::formatRecordForSheet($record);
        $sheetService = app(\App\Services\GoogleSheetService::class);

        // 🟢 PHÒNG THỦ: Nếu platform rỗng, dùng 'General' để tránh lỗi Tab "_Tracker"
        $platform = $record->account?->platform ?: 'General';
        // Tên Tab dựa trên Platform + Tracker (Ví dụ: Rakuten_Tracker)

        $sheetService->upsertRows([$row], 'All_Rebate_Tracker', static::$trackerHeaders);
        $sheetService->upsertRows([$row], ucfirst($platform) . '_Tracker', static::$trackerHeaders); // FIX #2: ucfirst
    }


    // =========================================================

}
