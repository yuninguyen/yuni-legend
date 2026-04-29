<?php

namespace App\Filament\Resources;

use App\Filament\Resources\PayoutMethodResource\Pages;
use App\Filament\Resources\PayoutMethodResource\RelationManagers;
use App\Models\PayoutMethod;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\Tabs\Tab;
use Filament\Forms\Components\Fieldset;
use Filament\Forms\Components\Tabs; // Added
use Filament\Forms\Components\Placeholder; // Added
use Filament\Support\Enums\FontWeight;
use Filament\Support\Enums\Alignment;
use Symfony\Component\Console\Color;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Notifications\Notification;
use App\Services\GoogleSheetService;
use App\Filament\Resources\Shared\ActivitiesRelationManager;


class PayoutMethodResource extends Resource
{
    protected static ?string $model = PayoutMethod::class;

    protected static ?string $navigationIcon = 'heroicon-o-rectangle-stack';

    public static function getNavigationGroup(): ?string
    {
        return 'wallet_payout';
    }

    public static function getNavigationLabel(): string
    {
        return __('system.payout_methods.navigation_label');
    }

    public static function getModelLabel(): string
    {
        return __('system.payout_methods.label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('system.payout_methods.plural_label');
    }

    protected static ?int $navigationSort = 1;

    // 🟢 1. ẨN HOÀN TOÀN MENU BÊN TRÁI ĐỐI VỚI STAFF
    public static function shouldRegisterNavigation(): bool
    {
        return (bool) (auth()->user()?->isAdmin() || auth()->user()?->isFinance());
    }

    // 🟢 2. CHẶN TRUY CẬP TRỰC TIẾP TỪ URL (Chống Staff tự gõ link)
    public static function canAccess(): bool
    {
        return (bool) (auth()->user()?->isAdmin() || auth()->user()?->isFinance());
    }

    // 🟢 3. FIX LỖI TYPE ERROR SẬP WEB (Luôn phải return Builder)
    public static function getEloquentQuery(): Builder
    {
        // Vì Staff đã bị chặn từ vòng gửi xe ở 2 hàm trên rồi, 
        // nên ở đây ta chỉ cần trả về mặc định cho Admin xài là xong!
        return parent::getEloquentQuery();
    }

    // 🟢 QUY TẮC 1: HEADER DUY NHẤT

    // 🟢 ĐẨY TOÀN BỘ LÊN SHEET

    public static function syncFromGoogleSheet(): void
    {
        try {
            $service = app(GoogleSheetService::class);
            $targetTab = 'Payout_Methods';
            $rows = $service->readSheet('A2:AB', $targetTab); // Đọc từ dòng 2

            if (empty($rows))
                return;

            // Tìm vị trí cột động để tránh lỗi nếu bạn thay đổi thứ tự Header
            $statusIdx = array_search('Status', \App\Services\GoogleSyncService::$payoutMethodHeaders);
            $noteIdx = array_search('Note', \App\Services\GoogleSyncService::$payoutMethodHeaders);

            $count = 0;
            foreach ($rows as $row) {
                if (isset($row[0]) && is_numeric($row[0])) {
                    $method = PayoutMethod::find($row[0]);
                    if ($method) {
                        $method->update([
                            // Cập nhật Trạng thái và Ghi chú từ Sheet về Web
                            'status' => trim($row[$statusIdx] ?? 'active'),
                            'note' => trim($row[$noteIdx] ?? ''),
                        ]);
                        $count++;
                    }
                }
            }

            Notification::make()
                ->title(__('system.payout_methods.notifications.synced_wallets', ['count' => $count]))
                ->success()
                ->send();
        } catch (\Exception $e) {
            Notification::make()
                ->title(__('system.notifications.sync_error'))
                ->body($e->getMessage())
                ->danger()
                ->send();
        }
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Tabs::make(__('system.payout_methods.tabs.details')) // Wrap all sections in a Tabs component
                    ->tabs([
                        Tab::make(__('system.payout_methods.tabs.wallet_info'))
                            // Description is moved into a Placeholder component within the Tab's schema.
                            ->schema([
                                Placeholder::make('wallet_description')
                                    ->hiddenLabel()
                                    ->content(__('system.payout_methods.fields.wallet_description'))
                                    ->columnSpanFull(),
                                TextInput::make('name')
                                    ->label(__('system.payout_methods.fields.wallet_name'))
                                    ->placeholder(__('system.payout_methods.placeholders.wallet_name_example'))
                                    ->required()
                                    ->maxLength(255),

                                Select::make('type')
                                    ->label(__('system.payout_methods.fields.method_type'))
                                    ->options([
                                        'paypal_us' => 'PayPal US',
                                        'paypal_vn' => 'PayPal VN',
                                        'bank_account' => 'Bank Account',
                                    ])
                                    ->required()
                                    ->native(false),

                                TextInput::make('current_balance')
                                    ->label(__('system.payout_methods.fields.current_balance'))
                                    ->numeric()
                                    ->prefix('$')
                                    ->default(0)
                                    ->disabled() // Để hệ thống tự cập nhật từ Payout Logs sau này
                                    ->dehydrated(false),



                                Select::make('status')
                                    ->label(__('system.payout_methods.fields.status'))
                                    ->options([
                                        'active' => __('system.status.active'),
                                        'limited' => __('system.status.paypal_limited'),
                                        'restored' => __('system.status.live'),
                                        'permanently_limited' => __('system.status.banned'),
                                    ])
                                    ->required()
                                    ->default('active')
                                    ->native(false),

                                Toggle::make('is_active')
                                    ->label(__('system.payout_methods.fields.is_active'))
                                    ->default(true)
                                    ->columnSpanFull(),
                            ])->columns(2), // Apply columns to the Tab's schema for consistency

                        // --- TAB 2: TÀI KHOẢN & MẬT KHẨU ---
                        Tab::make(__('system.payout_methods.tabs.login_details'))
                            ->icon('heroicon-m-key')
                            ->schema([
                                Fieldset::make(__('system.payout_methods.sections.email_login'))
                                    ->schema([
                                        TextInput::make('email')->label(__('system.labels.email_address'))->email(),
                                        TextInput::make('password')->label(__('system.labels.password_email')),
                                    ]),
                                Fieldset::make(__('system.payout_methods.sections.paypal_login'))
                                    ->schema([
                                        TextInput::make('paypal_account')->label(__('system.payout_methods.fields.paypal_account')),
                                        TextInput::make('paypal_password')->label(__('system.payout_methods.fields.paypal_password')),
                                        TextInput::make('auth_code')->label(__('system.payout_methods.fields.auth_code')),
                                    ]),
                            ]),

                        // --- TAB 3: THÔNG TIN CÁ NHÂN & BẢO MẬT ---
                        Forms\Components\Tabs\Tab::make(__('system.payout_methods.tabs.personal_security'))
                            ->icon('heroicon-m-user-circle')
                            ->schema([
                                Forms\Components\Grid::make(3)->schema([
                                    Forms\Components\TextInput::make('full_name')
                                        ->label(__('system.payout_methods.fields.full_name')),
                                    Forms\Components\TextInput::make('dob')
                                        ->label(__('system.payout_methods.fields.dob'))
                                        ->placeholder('dd/mm/yyyy')
                                        ->nullable() // Cho phép để trống
                                        ->default(null) // Đảm bảo không tự động lấy ngày hiện tại
                                        ->mask('99/99/9999') // Tạo khuôn dd/mm/yyyy khi gõ
                                        ->rules(['date_format:d/m/Y'])
                                        ->dehydrateStateUsing(function ($state) {
                                            if (blank($state))
                                                return null;
                                            try {
                                                // Dịch từ chuẩn VN (d/m/Y) sang chuẩn Quốc tế (Y-m-d) để MySQL hiểu
                                                return \Carbon\Carbon::createFromFormat('d/m/Y', $state)->format('Y-m-d');
                                            } catch (\Exception $e) {
                                                return null;
                                            }
                                        }),
                                    Forms\Components\TextInput::make('ssn')->label(__('system.payout_methods.fields.ssn_tax_id')),
                                    Forms\Components\TextInput::make('phone')->label(__('system.payout_methods.fields.phone_number')),
                                    Forms\Components\TextInput::make('address')
                                        ->label(__('system.payout_methods.fields.full_address'))
                                        ->columnSpan(2),
                                ]),
                                Forms\Components\Section::make(__('system.payout_methods.sections.security_questions'))
                                    ->description(__('system.payout_methods.sections.security_questions_desc'))
                                    ->schema([
                                        Forms\Components\Select::make('question_1')
                                            ->label(__('system.payout_methods.fields.question_security_1'))
                                            ->placeholder(__('system.payout_methods.placeholders.select_question'))
                                            ->options([
                                                'What\'s the nickname of your oldest child?' => 'What\'s the nickname of your oldest child?',
                                                'What was the name of your first pet?' => 'What was the name of your first pet?',
                                                'What\'s the name of your favorite childhood cuddly toy?' => 'What\'s the name of your favorite childhood cuddly toy?',
                                                'What is the maiden name of grandmother?' => 'What is the maiden name of grandmother?',
                                                'Who was your first roommate?' => 'Who was your first roommate?',
                                                'What\'s the name of the hospital in which you were born?' => 'What\'s the name of the hospital in which you were born?',
                                                'What was the name of your first school?' => 'What was the name of your first school?',
                                            ]),
                                        Forms\Components\TextInput::make('answer_1')->label(__('system.payout_methods.fields.answer_1')),
                                        Forms\Components\Select::make('question_2')
                                            ->label(__('system.payout_methods.fields.question_security_2'))
                                            ->placeholder(__('system.payout_methods.placeholders.select_question'))
                                            ->options([
                                                'What\'s the nickname of your oldest child?' => 'What\'s the nickname of your oldest child?',
                                                'What was the name of your first pet?' => 'What was the name of your first pet?',
                                                'What\'s the name of your favorite childhood cuddly toy?' => 'What\'s the name of your favorite childhood cuddly toy?',
                                                'What is the maiden name of grandmother?' => 'What is the maiden name of grandmother?',
                                                'Who was your first roommate?' => 'Who was your first roommate?',
                                                'What\'s the name of the hospital in which you were born?' => 'What\'s the name of the hospital in which you were born?',
                                                'What was the name of your first school?' => 'What was the name of your first school?',
                                            ]),
                                        Forms\Components\TextInput::make('answer_2')->label(__('system.payout_methods.fields.answer_2')),
                                    ])->columns(2),
                            ]),

                        // --- TAB 4: THÔNG SỐ MẠNG & THIẾT BỊ ---
                        Forms\Components\Tabs\Tab::make(__('system.payout_methods.tabs.connection_device'))
                            ->icon('heroicon-m-computer-desktop')
                            ->schema([
                                Forms\Components\Grid::make(3)->schema([
                                    Forms\Components\TextInput::make('proxy_type')
                                        ->label(__('system.payout_methods.fields.proxy_type'))
                                        ->placeholder(__('system.payout_methods.placeholders.proxy_example')),
                                    Forms\Components\TextInput::make('ip_address')->label(__('system.payout_methods.fields.ip_address')),
                                    Forms\Components\TextInput::make('location')
                                        ->label(__('system.payout_methods.fields.location'))
                                        ->placeholder(__('system.payout_methods.placeholders.location_example')),
                                    Forms\Components\TextInput::make('isp')->label(__('system.payout_methods.fields.isp')),
                                    Forms\Components\TextInput::make('browser')->label(__('system.payout_methods.fields.browser')),
                                    Forms\Components\TextInput::make('device')->label(__('system.payout_methods.fields.device')),
                                ]),
                                Forms\Components\Textarea::make('note')
                                    ->label(__('system.labels.note'))
                                    ->columnSpanFull(),
                            ]),

                    ])
                    ->columnSpanFull()
                    ->persistTabInQueryString(), // Lưu tab đang chọn lên URL (tiện khi F5 trang)
            ]);
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist
            ->schema([
                Infolists\Components\Section::make(__('system.payout_methods.sections.account_overview'))
                    ->schema([
                        Infolists\Components\Grid::make(4)->schema([
                            Infolists\Components\TextEntry::make('name')
                                ->label(__('system.payout_methods.fields.wallet_name'))
                                ->weight(FontWeight::Bold)
                                ->color('primary'),
                            Infolists\Components\TextEntry::make('type')
                                ->label(__('system.payout_methods.fields.method_type'))
                                ->formatStateUsing(fn($state) => strtoupper(str_replace('_', ' ', $state)))
                                ->badge(),
                            Infolists\Components\TextEntry::make('current_balance')
                                ->label(__('system.payout_methods.fields.current_balance'))
                                ->money('USD')
                                ->color('warning'),
                            Infolists\Components\TextEntry::make('status')
                                ->label(__('system.payout_methods.fields.status'))
                                ->badge()
                                ->color(fn($state) => match ($state) {
                                    'active' => 'success',
                                    'limited' => 'warning',
                                    'permanently_limited' => 'danger',
                                    'restored' => 'info',
                                    default => 'gray',
                                })
                                ->formatStateUsing(function ($state) {
                                    $label = match ($state) {
                                        'active' => __('system.status.active'),
                                        'limited' => __('system.status.paypal_limited'),
                                        'restored' => __('system.status.live'),
                                        'permanently_limited' => __('system.status.banned'),
                                        default => ucwords(str_replace('_', ' ', $state)),
                                    };
                                    return $label;
                                })
                        ]),
                    ]),

                Infolists\Components\Tabs::make(__('system.payout_methods.sections.detailed_info'))
                    ->tabs([
                        // TAB 1: THÔNG TIN ĐĂNG NHẬP (Có nút Copy nhanh)
                        Infolists\Components\Tabs\Tab::make(__('system.payout_methods.tabs.login_details'))
                            ->icon('heroicon-m-key')
                            ->schema([
                                Infolists\Components\Grid::make(2)->schema([
                                    Infolists\Components\Group::make([
                                        Infolists\Components\TextEntry::make('email')->label(__('system.labels.email_address'))->copyable()->icon('heroicon-m-envelope'),
                                        Infolists\Components\TextEntry::make('password')->label(__('system.labels.password_email'))->copyable()->icon('heroicon-m-lock-closed'),
                                    ])->columnSpan(1),
                                    Infolists\Components\Group::make([
                                        Infolists\Components\TextEntry::make('paypal_account')->label(__('system.payout_methods.fields.paypal_account'))->copyable()->color('info'),
                                        Infolists\Components\TextEntry::make('paypal_password')->label(__('system.payout_methods.fields.paypal_password'))->copyable(),
                                        Infolists\Components\TextEntry::make('auth_code')->label(__('system.payout_methods.fields.auth_code'))
                                            ->copyable()
                                            ->weight(FontWeight::Bold)
                                            ->color('success'),
                                    ])->columnSpan(1),
                                ]),
                            ]),

                        // TAB 2: ĐỊNH DANH & BẢO MẬT
                        Infolists\Components\Tabs\Tab::make(__('system.payout_methods.tabs.personal_security'))
                            ->icon('heroicon-m-identification')
                            ->schema([
                                Infolists\Components\Grid::make(3)->schema([
                                    Infolists\Components\TextEntry::make('full_name')->label(__('system.payout_methods.fields.full_name'))->copyable(),
                                    Infolists\Components\TextEntry::make('dob')
                                        ->label(__('system.payout_methods.fields.dob'))
                                        ->dateTime('d/m/Y')
                                        ->placeholder(__('system.n/a')),
                                    Infolists\Components\TextEntry::make('ssn')
                                        ->label(__('system.payout_methods.fields.ssn_tax_id'))
                                        ->copyable()
                                        ->placeholder(__('system.n/a')),
                                    Infolists\Components\TextEntry::make('phone')->label(__('system.payout_methods.fields.phone_number'))->copyable(),
                                    Infolists\Components\TextEntry::make('address')->label(__('system.payout_methods.fields.full_address'))->columnSpan(2),
                                ]),
                                Infolists\Components\Grid::make(2)->schema([
                                    Infolists\Components\TextEntry::make('question_1')->label(__('system.payout_methods.fields.question_security_1'))->color('gray'),
                                    Infolists\Components\TextEntry::make('answer_1')->label(__('system.payout_methods.fields.answer_1'))->weight(FontWeight::Bold),
                                    Infolists\Components\TextEntry::make('question_2')->label(__('system.payout_methods.fields.question_security_2'))->color('gray'),
                                    Infolists\Components\TextEntry::make('answer_2')->label(__('system.payout_methods.fields.answer_2'))->weight(FontWeight::Bold),
                                ])->extraAttributes(['class' => 'bg-gray-50 p-4 rounded-xl mt-4']),
                            ]),

                        // TAB 3: THÔNG SỐ MẠNG (ISP, PROXY...)
                        Infolists\Components\Tabs\Tab::make(__('system.payout_methods.tabs.connection_device'))
                            ->icon('heroicon-m-globe-alt')
                            ->schema([
                                Infolists\Components\Grid::make(3)->schema([
                                    Infolists\Components\TextEntry::make('proxy_type')->label(__('system.payout_methods.fields.proxy_type')),
                                    Infolists\Components\TextEntry::make('ip_address')->label(__('system.payout_methods.fields.ip_address'))->copyable(),
                                    Infolists\Components\TextEntry::make('location')->label(__('system.payout_methods.fields.location')),
                                    Infolists\Components\TextEntry::make('isp')->label(__('system.payout_methods.fields.isp')),
                                    Infolists\Components\TextEntry::make('browser')->label(__('system.payout_methods.fields.browser')),
                                    Infolists\Components\TextEntry::make('device')->label(__('system.payout_methods.fields.device')),
                                ]),
                                Infolists\Components\TextEntry::make('note')
                                    ->label(__('system.labels.note'))
                                    ->markdown()
                                    ->columnSpanFull()
                                    ->html() // Cho phép tự định nghĩa HTML để ép khoảng cách
                                    ->formatStateUsing(fn($state) => $state ?
                                        "<div style='white-space: pre-wrap; line-height: 1.6; margin: 0; padding: 0;'>" . e(trim($state)) . "</div>"
                                        : __('system.n/a')),

                            ]),

                        // TAB 4: LỊCH SỬ GIAO DỊCH (Mini statement)
                        Infolists\Components\Tabs\Tab::make(__('system.labels.transaction_activity'))
                            ->icon('heroicon-m-list-bullet')
                            ->schema([
                                // 🟢 HEADER NHƯ MỘC CÁI BẢNG (Chỉ hiện 1 lần)
                                Infolists\Components\Grid::make(6)
                                    ->schema([
                                        Infolists\Components\TextEntry::make('header_date')
                                            ->state(strtoupper(__('system.labels.date')))
                                            ->hiddenLabel()
                                            ->extraAttributes(['class' => 'text-xs font-bold text-gray-500 uppercase tracking-wider']),
                                        Infolists\Components\TextEntry::make('header_details')
                                            ->state(strtoupper(__('system.labels.transaction_details')))
                                            ->hiddenLabel()
                                            ->columnSpan(2)
                                            ->extraAttributes(['class' => 'text-xs font-bold text-gray-500 uppercase tracking-wider']),
                                        Infolists\Components\TextEntry::make('header_rate')
                                            ->state(strtoupper(__('system.brands.columns.rate')))
                                            ->hiddenLabel()
                                            ->alignment(Alignment::Center)
                                            ->extraAttributes(['class' => 'text-xs font-bold text-gray-500 uppercase tracking-wider pl-12']),
                                        Infolists\Components\TextEntry::make('header_amount')
                                            ->state(strtoupper(__('system.labels.net_amount_usd')))
                                            ->hiddenLabel()
                                            ->alignment(Alignment::Right)
                                            ->extraAttributes(['class' => 'text-xs font-bold text-gray-500 uppercase tracking-wider']),
                                        Infolists\Components\TextEntry::make('header_total_vnd')
                                            ->state(strtoupper('Net Amount (VND)'))
                                            ->hiddenLabel()
                                            ->alignment(Alignment::Right)
                                            ->extraAttributes(['class' => 'text-xs font-bold text-gray-500 uppercase tracking-wider']),
                                    ])
                                    ->columnSpanFull()
                                    ->extraAttributes(['class' => 'border-b border-gray-100 pb-2 mb-2']),

                                Infolists\Components\RepeatableEntry::make('latestPayoutLogs')
                                    ->label('') // Bỏ nhãn cho gọn trong Tab
                                    ->schema([
                                        Infolists\Components\Grid::make(6)->schema([
                                            // 1. NGÀY (1/6)
                                            Infolists\Components\TextEntry::make('created_at')
                                                ->hiddenLabel()
                                                ->dateTime('d/m/Y H:i')
                                                ->size(Infolists\Components\TextEntry\TextEntrySize::Small)
                                                ->color('gray'),

                                            // 2. CHI TIẾT (2/6)
                                            Infolists\Components\TextEntry::make('transaction_type')
                                                ->hiddenLabel()
                                                ->columnSpan(2)
                                                ->html()
                                                ->formatStateUsing(function ($state, $record) {
                                                    $description = '';
                                                    $typeLabel = '';

                                                    if ($state === 'withdrawal') {
                                                        $typeLabel = __('system.payout_logs.transaction_types.withdrawal');
                                                        $platformName = \App\Models\Platform::where('slug', $record->account?->platform)->value('name') ?? ucwords($record->account?->platform ?? __('system.n/a'));
                                                        $accountEmail = $record->account?->email?->email ?? __('system.no_email');
                                                        $description = "{$platformName} - {$accountEmail} - ID #{$record->id}";
                                                    } else {
                                                        $typeLabel = __('system.payout_logs.transaction_types.liquidation');
                                                        $description = $record->note ?? '';

                                                        // 🚀 Smart Extraction: Nếu note bắt đầu bằng [TAG], bóc nó ra làm nhãn chính
                                                        if (preg_match('/^\[(.*?)\]/', $description, $matches)) {
                                                            $typeLabel = $matches[1]; // Lấy nội dung trong ngoặc: SEND, PAYMENT_SERVICE, ...
                                                            $description = trim(str_replace($matches[0], '', $description));
                                                        }

                                                        if (str_contains($description, 'Liquidity from ID')) {
                                                            $description = str_replace('Liquidity from ID', __('system.labels.liquidity_from_id'), $description);
                                                        }
                                                        if (str_contains($description, 'Liquidation from ID')) {
                                                            $description = str_replace('Liquidation from ID', __('system.labels.liquidity_from_id'), $description);
                                                        }
                                                    }

                                                    // 🟢 Green for Inflow (Withdrawal), Orange for Outflow (Liquidation)
                                                    $colorClass = $state === 'withdrawal' ? 'text-success-600' : 'text-orange-600';

                                                    return "<div class='text-sm'><span class='font-bold {$colorClass}'>[" . strtoupper($typeLabel) . "]</span> <span class='text-gray-600 ml-1'>{$description}</span></div>";
                                                }),

                                            // 3. TỶ GIÁ (1/6 - Căn giữa)
                                            Infolists\Components\TextEntry::make('exchange_rate')
                                                ->hiddenLabel()
                                                ->alignment(Alignment::Center)
                                                ->extraAttributes(['class' => 'pr-6'])
                                                // 🟢 Bỏ trống RATE nếu là rút tiền về ví (Withdrawal), Format VNĐ cho Liquidation
                                                ->formatStateUsing(function ($state, \App\Models\PayoutLog $record) {
                                                    if ($record->transaction_type === 'withdrawal') {
                                                        return null; // Trả về null sẽ tự động bỏ trống hoặc dùng placeholder
                                                    }
                                                    return $state ? '₫' . number_format((float) $state, 0, ',', '.') : '-';
                                                })
                                                ->color(fn(\App\Models\PayoutLog $record) => $record->transaction_type === 'liquidation' ? 'success' : 'gray'),

                                            // 4. SỐ TIỀN THỰC NHẬN USD (1/6 - Căn phải)
                                            Infolists\Components\TextEntry::make('net_amount_usd')
                                                ->hiddenLabel()
                                                ->alignment(Alignment::Right)
                                                ->money('USD')
                                                ->weight(FontWeight::Bold)
                                                // 🟢 Xanh cho tiền vào (Withdrawal), Cam cho tiền ra (Liquidation)
                                                ->color(fn(\App\Models\PayoutLog $record) => $record->transaction_type === 'withdrawal' ? 'success' : 'warning'),

                                            // 5. SỐ TIỀN THỰC NHẬN VNĐ (1/6 - Căn phải)
                                            Infolists\Components\TextEntry::make('total_vnd')
                                                ->hiddenLabel()
                                                ->alignment(Alignment::Right)
                                                ->formatStateUsing(function ($state, \App\Models\PayoutLog $record) {
                                                    if ($record->transaction_type === 'withdrawal') {
                                                        return null;
                                                    }
                                                    return $state ? '₫' . number_format((float) $state, 0, ',', '.') : '-';
                                                })
                                                ->color('success')
                                                ->weight(FontWeight::Bold),
                                        ]),
                                    ])
                                    ->columnSpanFull()
                                    ->extraAttributes(['class' => 'divide-y divide-gray-50']),
                            ]),
                    ])
                    ->columnSpanFull(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label(__('system.payout_methods.fields.wallet_name'))
                    ->searchable()
                    ->alignment(Alignment::Center) // Ép tiêu đề vào giữa
                    ->wrap()
                    ->width('250px')
                    ->html()
                    ->state(function ($record) {
                        $name = e($record->name);
                        $type = strtoupper(str_replace('_', ' ', $record->type));

                        // Màu sắc cho Type giống như logic cũ của bạn
                        $typeColor = match ($record->type) {
                            'paypal_us' => '#3b82f6', // primary
                            'paypal_vn' => '#22c55e', // success
                            default => '#6b7280',     // secondary
                        };
                        return "<div style='color: {$typeColor}; font-weight: bold;'>{$type} - {$name}</div>";
                    }),

                Tables\Columns\TextColumn::make('identifier')
                    ->label(__('system.labels.paypal_information'))
                    ->copyable()
                    ->searchable()
                    ->html()
                    ->alignment(Alignment::Center)
                    ->wrap()
                    ->width('502px')
                    ->copyableState(function ($record) {
                        $na = __('system.n/a');
                        $labels = [
                            'email' => __('system.labels.email_address'),
                            'pass' => __('system.labels.password'),
                            'paypal' => __('system.payout_methods.fields.paypal_account'),
                            'paypal_pass' => __('system.payout_methods.fields.paypal_password'),
                            'auth' => __('system.payout_methods.fields.auth_code'),
                            'status' => __('system.labels.status'),
                            'note' => __('system.labels.note'),
                            'name' => __('system.payout_methods.fields.full_name'),
                            'dob' => __('system.payout_methods.fields.dob'),
                            'ssn' => __('system.payout_methods.fields.ssn_tax_id'),
                            'phone' => __('system.payout_methods.fields.phone_number'),
                            'address' => __('system.payout_methods.fields.full_address'),
                            'ip' => __('system.payout_methods.fields.ip_address'),
                            'location' => __('system.payout_methods.fields.location'),
                            'isp' => __('system.payout_methods.fields.isp'),
                            'browser' => __('system.payout_methods.fields.browser'),
                            'device' => __('system.payout_methods.fields.device'),
                        ];

                        $val = fn($v) => $v ? e($v) : $na;

                        return
                            "<===== ACCOUNT =====>\n" .
                            "{$labels['email']}: " . $val($record->email) . " | {$labels['pass']}: " . $val($record->password) . "\n" .
                            "{$labels['paypal']}: " . $val($record->paypal_account) . " | {$labels['paypal_pass']}: " . $val($record->paypal_password) . "\n" .
                            "{$labels['auth']}: " . $val($record->auth_code) . "\n" .
                            "{$labels['status']}: " . (__('system.status.' . $record->status) ?: e($record->status)) . "\n" .
                            "{$labels['note']}: " . $val($record->note) . "\n" .
                            "<===== PERSONAL INFORMATION =====>\n" .
                            "{$labels['name']}: " . $val($record->full_name) . "\n" .
                            "{$labels['dob']}: " . ($record->dob ? \Carbon\Carbon::parse($record->dob)->format('d/m/Y') : $na) . "\n" .
                            "{$labels['ssn']}: " . $val($record->ssn) . "\n" .
                            "{$labels['phone']}: " . $val($record->phone) . "\n" .
                            "{$labels['address']}: " . $val($record->address) . "\n" .
                            "<===== SECURITY QUESTIONS =====>\n" .
                            "Q1: " . $val($record->question_1) . " -> " . $val($record->answer_1) . "\n" .
                            "Q2: " . $val($record->question_2) . " -> " . $val($record->answer_2) . "\n" .
                            "<===== CONNECTION & DEVICE =====>\n" .
                            "{$labels['ip']}: " . $val($record->ip_address) . " | {$labels['location']}: " . $val($record->location) . " | {$labels['isp']}: " . $val($record->isp) . "\n" .
                            "{$labels['browser']}: " . $val($record->browser) . " | {$labels['device']}: " . $val($record->device) . "\n";
                    })
                    ->state(function ($record) {
                        // Xác định màu cho Status
                        $statusColor = match ($record->status) {
                            'active' => '#22c55e', // Green
                            'limited' => '#f59e0b', // Orange
                            'permanently_limited' => '#ef4444', // Red
                            'restored' => '#3b82f6', // Blue
                            default => '#6b7280',
                        };

                        $statusLabel = match ($record->status) {
                            'active' => __('system.status.active'),
                            'limited' => __('system.status.paypal_limited'),
                            'restored' => __('system.status.live'),
                            'permanently_limited' => __('system.status.banned'),
                            default => e(ucwords($record->status)),
                        };

                        $na = __('system.n/a');
                        $payPalAccount = $record->paypal_account ? e($record->paypal_account) : $na;
                        $paypalPassword = $record->paypal_password ? e($record->paypal_password) : $na;
                        $authcode = $record->auth_code ? e($record->auth_code) : $na;
                        $fullName = $record->full_name ? e($record->full_name) : $na;
                        $dob = $record->dob ? \Carbon\Carbon::parse($record->dob)->format('d/m/Y') : $na;
                        $ssn = $record->ssn ? e($record->ssn) : $na;
                        $phone = $record->phone ? e($record->phone) : $na;
                        $address = $record->address ? e($record->address) : $na;

                        $labels = [
                            'paypal' => __('system.payout_methods.fields.paypal_account'),
                            'auth' => __('system.payout_methods.fields.auth_code'),
                            'name' => __('system.payout_methods.fields.full_name'),
                            'dob' => __('system.payout_methods.fields.dob'),
                            'ssn' => __('system.payout_methods.fields.ssn_tax_id'),
                            'phone' => __('system.payout_methods.fields.phone_number'),
                            'address' => __('system.payout_methods.fields.full_address'),
                            'status' => __('system.labels.status'),
                        ];

                        return "
                               <div style='display: block; text-align: left; line-height: 1.7;'>
                                    <div style='margin-bottom: 4px;'>
                                        <span style='color: #6b7280; display: inline-block;'>{$labels['paypal']}:</span> 
                                        <strong style='color: #111827;'>{$payPalAccount}</strong>
                                        <span style='color: #6b7280; display: inline-block;'> | </span> 
                                        <strong style='color: #111827;'>{$paypalPassword}</strong>
                                    </div>
                                    <!-- <div style='margin-bottom: 4px;'>
                                        <span style='color: #6b7280; display: inline-block;'>{$labels['auth']}:</span> 
                                        <span style='color: #111827;'>{$authcode}</span>
                                    </div>
                                    <div style='margin-bottom: 4px;'>
                                        <span style='color: #6b7280; display: inline-block;'>{$labels['name']}:</span> 
                                        <span style='color: #111827;'>{$fullName}</span>
                                        </div>
                                        <div style='margin-bottom: 4px;'>
                                        <span style='color: #6b7280; display: inline-block;'>{$labels['dob']}:</span> 
                                        <span style='color: #111827;'>{$dob}</span>
                                        </div>
                                        <div style='margin-bottom: 4px;'>
                                        <span style='color: #6b7280; display: inline-block;'>{$labels['ssn']}:</span> 
                                        <span style='color: #111827;'>{$ssn}</span>
                                        </div>
                                        <div style='margin-bottom: 4px;'>
                                        <span style='color: #6b7280; display: inline-block;'>{$labels['phone']}:</span> 
                                        <span style='color: #111827;'>{$phone}</span>
                                        </div>
                                        <div style='margin-bottom: 4px;'>
                                        <span style='color: #6b7280; display: inline-block;'>{$labels['address']}:</span> 
                                        <span style='color: #111827;'>{$address}</span>
                                    </div> -->
                                    <div style='margin-bottom: 4px; white-space: nowrap;'>
                                        <span style='color: #6b7280; display: inline-block;'>{$labels['status']}:</span> 
                                        <code style='background: #f3f4f6; color: {$statusColor}; padding: 2px 6px; border-radius: 4px; font-weight: bold;'>{$statusLabel}</code>
                                    </div>
                                </div>
                            ";
                    })
                    ->icon('heroicon-m-clipboard-document')
                    // 🟢 MÀU ICON: Chỉ icon màu vàng, chữ vẫn giữ màu mặc định
                    ->iconColor('warning')
                    // 🟢 ĐƯA ICON SANG BÊN PHẢI
                    ->iconPosition('after')
                    // 🟢 SEARCH: Vì đây là cột ảo, ta phải chỉ định Filament tìm ở các cột thật nào
                    ->searchable(query: function (Builder $query, string $search): Builder {
                        return $query->where('name', 'like', "%{$search}%")
                            ->orWhere('email', 'like', "%{$search}%")
                            ->orWhere('paypal_account', 'like', "%{$search}%")
                            ->orWhere('ssn', 'like', "%{$search}%")
                            ->orWhere('full_name', 'like', "%{$search}%")
                            ->orWhere('ip_address', 'like', "%{$search}%");
                    }),

                Tables\Columns\TextColumn::make('current_balance')
                    ->label(__('system.payout_methods.fields.current_balance'))
                    ->alignment(Alignment::Center)
                    ->money('usd')
                    ->color('warning')
                    ->weight('bold'),

            ])
            ->filters([
                Tables\Filters\SelectFilter::make('type')
                    ->label(__('system.labels.brand'))
                    ->options([
                        'paypal_us' => 'PayPal US',
                        'paypal_vn' => 'PayPal VN',
                    ]),
            ])
            ->actions([
                // Nút Xem chi tiết (Hình con mắt) hiện ra bên ngoài
                Tables\Actions\ViewAction::make()
                    ->label('') // Để trống nhãn để chỉ hiện icon cho gọn
                    ->modalHeading(__('system.payout_methods.sections.detailed_info')) // TIÊU ĐỀ CỦA MODAL
                    ->tooltip(__('system.labels.detail')) // Hiện ghi chú khi di chuột vào
                    ->icon('heroicon-o-eye')
                    ->color('gray'), // Màu xám nhẹ nhàng, không lấn át nút cam
                Tables\Actions\ActionGroup::make([
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
                        ->action(function (\Illuminate\Database\Eloquent\Collection $records, \App\Services\GoogleSyncService $syncService) {
                            try {
                                $syncService->syncPayoutMethods($records);

                                Notification::make()
                                    ->title(__('system.notifications.synced_successfully'))
                                    ->description(__('system.notifications.sync_success_msg', ['count' => count($records)]))
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
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }


    public static function getRelations(): array
    {
        return [
            ActivitiesRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPayoutMethods::route('/'),
            'create' => Pages\CreatePayoutMethod::route('/create'),
            'edit' => Pages\EditPayoutMethod::route('/{record}/edit'),
        ];
    }
}
