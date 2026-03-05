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


class PayoutMethodResource extends Resource
{
    protected static ?string $model = PayoutMethod::class;

    protected static ?string $navigationIcon = 'heroicon-o-rectangle-stack';
    protected static ?string $navigationGroup = 'WALLET & PAYOUTS';
    protected static ?string $navigationLabel = 'Payout Method';
    protected static ?int $navigationSort = 1;

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();
        $user = auth()->user();

        // Nếu là Admin -> Cho xem tất cả mọi thứ (Sử dụng logic từ Model User)
        if ($user && method_exists($user, 'isAdmin') && $user->isAdmin()) {
            return $query;
        } // Nếu là Staff bình thường -> Chỉ cho xem Account
    }

    // 🟢 QUY TẮC 1: HEADER DUY NHẤT
    public static array $payoutMethodHeaders = [
        'ID',
        'Wallet Name',
        'Method Type',
        'Current Balance (USD)',
        'Email Address',
        'Email Password',
        'PayPal Account',
        'PayPalpassword',
        'Authenticator Code',
        'Full Name',
        'Date of Birth',
        'SSN / Tax ID',
        'Phone Number',
        'Full Address',
        'Question Security 1',
        'Answer 1',
        'Question Security 2',
        'Answer 2',
        'Proxy Type',
        'IP Address',
        'Location',
        'ISP (Network Provider)',
        'Browser Name',
        'Device',
        'Status',
        'Note',
        'Trạng thái kích hoạt',
        'Last sync',
    ];

    // 🟢 QUY TẮC 2: FORMAT DUY NHẤT
    public static function formatPayoutMethodForSheet($record): array
    {
        return [
            (string) $record->id,                                                   // 0. ID
            (string) $record->name,                                                 // 1. Wallet Name
            (string) strtoupper(str_replace('_', ' ', $record->type)),              // 2. Method Type (PAYPAL US, PAYPAL VN...)
            (string) number_format($record->current_balance ?? 0, 2, '.', ''),      // 4. Balance
            (string) $record->email,                                                // 5. Email Address
            (string) $record->password,                                             // 6. Email Password
            (string) $record->paypal_account,                                       // 7. PayPal Account
            (string) $record->paypal_password,                                      // 8. PayPal Password
            (string) $record->auth_code,                                            // 9. Authenticator Code
            (string) $record->full_name,                                            // 10. Full Name
            (string) $record->dob,                                                  // 11. Date of Birth
            (string) $record->ssn,                                                  // 12. SSN /
            (string) $record->phone,                                                // 13. Phone Number
            (string) $record->address,                                              // 14. Full Address
            (string) $record->question_1,                                           // 15. Question
            (string) $record->answer_1,                                             // 16. Answer
            (string) $record->question_2,                                           // 17. Question
            (string) $record->answer_2,                                             // 18. Answer
            (string) $record->proxy_type,                                           // 19. Proxy Type
            (string) $record->ip_address,                                           // 20. IP
            (string) $record->location,                                             // 21. Location
            (string) $record->isp,                                                  // 22. ISP
            (string) $record->browser,                                              // 23. Browser
            (string) $record->device,                                               // 24. Device
            (string) ucwords($record->status),                                      // 25. Status
            (string) $record->note,                                                 // 26. Note
            (string) ($record->is_active ? 'On' : 'Off'),                            // 27. Trạng thái kích hoạt
            (string) now()->format('d/m/Y H:i'),                                    // 28. Thời điểm sync
        ];
    }

    // 🟢 ĐẨY TOÀN BỘ LÊN SHEET
    public static function syncToGoogleSheet(): void
    {
        $sheetService = app(\App\Services\GoogleSheetService::class);
        $targetTab = 'Payout_Methods';

        try {
            $sheetService->createSheetIfNotExist($targetTab);

            // 1. Lấy toàn bộ ví từ Database
            $allMethods = \App\Models\PayoutMethod::all();

            // 2. Chuẩn bị mảng dữ liệu (Dòng 1 là Header)
            $rows = [static::$payoutMethodHeaders];

            foreach ($allMethods as $method) {
                $rows[] = static::formatPayoutMethodForSheet($method);
            }

            // 3. Đẩy lên Google Sheet (Vùng từ A1 đến AB cho 28 cột)
            $sheetService->updateSheet($rows, 'A1:AB', $targetTab);

            // 4. TỰ ĐỘNG ĐỔI MÀU CHO VÍ LIMITED
            $statusIdx = array_search('Status', static::$payoutMethodHeaders);

            $methodColors = [
                'Active'              => ['red' => 0.85, 'green' => 0.95, 'blue' => 0.85], // Xanh lá nhạt
                'Limited'             => ['red' => 1.0,  'green' => 0.8,  'blue' => 0.8],  // Đỏ (Cảnh báo!)
                'Permanently Limited' => ['red' => 0.9,  'green' => 0.5,  'blue' => 0.5],  // Đỏ đậm
                'Restored'            => ['red' => 0.8,  'green' => 0.8,  'blue' => 1.0],  // Xanh dương nhạt
            ];
            $sheetService->applyFormattingWithRules($targetTab, $statusIdx, $methodColors);

            // 5. Định dạng Clip cho các cột dài (Email, Pass, Address, Note)
            $sheetService->formatColumnsAsClip($targetTab, 4, 8);
            $sheetService->formatColumnsAsClip($targetTab, 13, 14);
            $sheetService->formatColumnsAsClip($targetTab, 25, 26);

            \Filament\Notifications\Notification::make()
                ->title('Success')
                ->body("Synced & Formatted successfully!")
                ->success()
                ->send();
        } catch (\Exception $e) {
            \Filament\Notifications\Notification::make()
                ->title('Sync Error')
                ->body($e->getMessage())
                ->danger()
                ->send();
        }
    }

    public static function syncFromGoogleSheet(): void
    {
        try {
            $service = app(\App\Services\GoogleSheetService::class);
            $targetTab = 'Payout_Methods';
            $rows = $service->readSheet('A2:AB', $targetTab); // Đọc từ dòng 2

            if (empty($rows)) return;

            // Tìm vị trí cột động để tránh lỗi nếu bạn thay đổi thứ tự Header
            $statusIdx = array_search('Status', static::$payoutMethodHeaders);
            $noteIdx = array_search('Note', static::$payoutMethodHeaders);

            $count = 0;
            foreach ($rows as $row) {
                if (isset($row[0]) && is_numeric($row[0])) {
                    $method = \App\Models\PayoutMethod::find($row[0]);
                    if ($method) {
                        $method->update([
                            // Cập nhật Trạng thái và Ghi chú từ Sheet về Web
                            'status' => trim($row[$statusIdx] ?? 'active'),
                            'note'   => trim($row[$noteIdx] ?? ''),
                        ]);
                        $count++;
                    }
                }
            }

            \Filament\Notifications\Notification::make()
                ->title("Synced {$count} wallets!")
                ->success()
                ->send();
        } catch (\Exception $e) {
            \Filament\Notifications\Notification::make()
                ->title('Error syncing from Google Sheet')
                ->body($e->getMessage())
                ->danger()
                ->send();
        }
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Tabs::make('Payout Method Details') // Wrap all sections in a Tabs component
                    ->tabs([
                        Tab::make('Wallet / Payout Method Information')
                            // Description is moved into a Placeholder component within the Tab's schema.
                            ->schema([
                                Placeholder::make('wallet_description')
                                    ->content('Define where you receive your money (PayPal accounts or Gift Card storage)')
                                    ->columnSpanFull(),
                                TextInput::make('name')
                                    ->label('Wallet Name')
                                    ->placeholder('e.g., PayPal US - Main')
                                    ->required()
                                    ->maxLength(255),

                                Select::make('type')
                                    ->label('Method Type')
                                    ->options([
                                        'paypal_us' => 'PayPal US',
                                        'paypal_vn' => 'PayPal VN',
                                        'bank_account' => 'Bank Account',
                                    ])
                                    ->required()
                                    ->native(false),

                                TextInput::make('current_balance')
                                    ->label('Current Balance (USD)')
                                    ->numeric()
                                    ->prefix('$')
                                    ->default(0)
                                    ->disabled() // Để hệ thống tự cập nhật từ Payout Logs sau này
                                    ->dehydrated(false),

                                Select::make('status')
                                    ->label('Status')
                                    ->options([
                                        'active' => 'Active',
                                        'limited' => 'Limited',
                                        'restored' => 'Restored',
                                        'permanently_limited' => 'Permanently Limited',
                                    ])
                                    ->required()
                                    ->default('active')
                                    ->native(false),

                                Toggle::make('is_active')
                                    ->label('Trạng thái kích hoạt')
                                    ->default(true)
                                    ->columnSpanFull(),
                            ])->columns(2), // Apply columns to the Tab's schema for consistency

                        // --- TAB 2: TÀI KHOẢN & MẬT KHẨU ---
                        Tab::make('Login Details')
                            ->icon('heroicon-m-key')
                            ->schema([
                                Fieldset::make('Email Login')
                                    ->schema([
                                        TextInput::make('email')->label('Email')->email(),
                                        TextInput::make('password')->label('Email Password'),
                                    ]),
                                Fieldset::make('PayPal Login')
                                    ->schema([
                                        TextInput::make('paypal_account')->label('PayPal Account'),
                                        TextInput::make('paypal_password')->label('PayPal Password'),
                                        TextInput::make('auth_code')->label('Authenticator Code'),
                                    ]),
                            ]),

                        // --- TAB 3: THÔNG TIN CÁ NHÂN & BẢO MẬT ---
                        Forms\Components\Tabs\Tab::make('Personal & Security')
                            ->icon('heroicon-m-user-circle')
                            ->schema([
                                Forms\Components\Grid::make(3)->schema([
                                    Forms\Components\TextInput::make('full_name')->label('Full name'),
                                    Forms\Components\TextInput::make('dob')
                                        ->label('Date of Birth')
                                        ->placeholder('dd/mm/yyyy')
                                        ->displayFormat('d/m/Y')
                                        ->format('Y-m-d') // Định dạng chuẩn để lưu vào MySQL
                                        ->native(false)
                                        ->nullable() // Cho phép để trống
                                        ->default(null),
                                    Forms\Components\TextInput::make('ssn')->label('SSN / Tax ID'),
                                    Forms\Components\TextInput::make('phone')->label('Phone number'),
                                    Forms\Components\Textarea::make('address')
                                        ->label('Full Address')
                                        ->columnSpan(2),
                                ]),
                                Forms\Components\Section::make('Security Questions')
                                    ->description('Used to recover passwords or verify identity.')
                                    ->schema([
                                        Forms\Components\Select::make('question_1')
                                            ->label('Question Security 1')
                                            ->placeholder('Select a question')
                                            ->options([
                                                'What\'s the nickname of your oldest child?' => 'What\'s the nickname of your oldest child?',
                                                'What was the name of your first pet?' => 'What was the name of your first pet?',
                                                'What\'s the name of your favorite childhood cuddly toy?' => 'What\'s the name of your favorite childhood cuddly toy?',
                                                'What is the maiden name of grandmother?' => 'What is the maiden name of grandmother?',
                                                'Who was your first roommate?' => 'Who was your first roommate?',
                                                'What\'s the name of the hospital in which you were born?' => 'What\'s the name of the hospital in which you were born?',
                                                'What was the name of your first school?' => 'What was the name of your first school?',
                                            ]),
                                        Forms\Components\TextInput::make('answer_1')->label('Answer 1'),
                                        Forms\Components\Select::make('question_2')
                                            ->label('Question Security 2')
                                            ->placeholder('Select a question')
                                            ->options([
                                                'What\'s the nickname of your oldest child?' => 'What\'s the nickname of your oldest child?',
                                                'What was the name of your first pet?' => 'What was the name of your first pet?',
                                                'What\'s the name of your favorite childhood cuddly toy?' => 'What\'s the name of your favorite childhood cuddly toy?',
                                                'What is the maiden name of grandmother?' => 'What is the maiden name of grandmother?',
                                                'Who was your first roommate?' => 'Who was your first roommate?',
                                                'What\'s the name of the hospital in which you were born?' => 'What\'s the name of the hospital in which you were born?',
                                                'What was the name of your first school?' => 'What was the name of your first school?',
                                            ]),
                                        Forms\Components\TextInput::make('answer_2')->label('Answer 2'),
                                    ])->columns(2),
                            ]),

                        // --- TAB 4: THÔNG SỐ MẠNG & THIẾT BỊ ---
                        Forms\Components\Tabs\Tab::make('Connection & Device')
                            ->icon('heroicon-m-computer-desktop')
                            ->schema([
                                Forms\Components\Grid::make(3)->schema([
                                    Forms\Components\TextInput::make('proxy_type')->placeholder('Socks5 / HTTP'),
                                    Forms\Components\TextInput::make('ip_address')->label('IP Address'),
                                    Forms\Components\TextInput::make('location')->placeholder('State/City'),
                                    Forms\Components\TextInput::make('isp')->label('ISP (Network Provider)'),
                                    Forms\Components\TextInput::make('browser')->label('Browser Name'),
                                    Forms\Components\TextInput::make('device')->label('Device'),
                                ]),
                                Forms\Components\Textarea::make('note')
                                    ->label('Note')
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
                Infolists\Components\Section::make('Account Overview')
                    ->schema([
                        Infolists\Components\Grid::make(4)->schema([
                            Infolists\Components\TextEntry::make('name')
                                ->label('Wallet Name')
                                ->weight(FontWeight::Bold)
                                ->color('primary'),
                            Infolists\Components\TextEntry::make('type')
                                ->formatStateUsing(fn($state) => strtoupper(str_replace('_', ' ', $state)))
                                ->badge(),
                            Infolists\Components\TextEntry::make('current_balance')
                                ->money('USD')
                                ->color('success'),
                            Infolists\Components\TextEntry::make('status')
                                ->badge()
                                ->color(fn($state) => match ($state) {
                                    'active' => 'success',
                                    'limited' => 'warning',
                                    'permanently_limited' => 'danger',
                                    'restored' => 'info',
                                    default => 'gray',
                                })
                                ->formatStateUsing(fn($state) => ucwords(str_replace('_', ' ', $state)))
                        ]),
                    ]),

                Infolists\Components\Tabs::make('Detailed Information')
                    ->tabs([
                        // TAB 1: THÔNG TIN ĐĂNG NHẬP (Có nút Copy nhanh)
                        Infolists\Components\Tabs\Tab::make('Login Credentials')
                            ->icon('heroicon-m-key')
                            ->schema([
                                Infolists\Components\Grid::make(2)->schema([
                                    Infolists\Components\Group::make([
                                        Infolists\Components\TextEntry::make('email')->label('Email')->copyable()->icon('heroicon-m-envelope'),
                                        Infolists\Components\TextEntry::make('password')->label('Email Password')->copyable()->icon('heroicon-m-lock-closed'),
                                    ])->columnSpan(1),
                                    Infolists\Components\Group::make([
                                        Infolists\Components\TextEntry::make('paypal_account')->label('PayPal Account')->copyable()->color('info'),
                                        Infolists\Components\TextEntry::make('paypal_password')->label('PayPal Password')->copyable(),
                                        Infolists\Components\TextEntry::make('auth_code')->label('2FA / Authen Code')
                                            ->copyable()
                                            ->weight(FontWeight::Bold)
                                            ->color('success'),
                                    ])->columnSpan(1),
                                ]),
                            ]),

                        // TAB 2: ĐỊNH DANH & BẢO MẬT
                        Infolists\Components\Tabs\Tab::make('Identity & Security')
                            ->icon('heroicon-m-identification')
                            ->schema([
                                Infolists\Components\Grid::make(3)->schema([
                                    Infolists\Components\TextEntry::make('full_name')->label('Full Name')->copyable(),
                                    Infolists\Components\TextEntry::make('dob')
                                        ->label('Date of Birth')
                                        ->dateTime('d/m/Y')
                                        ->placeholder('N/A'),
                                    Infolists\Components\TextEntry::make('ssn')->label('SSN / Tax ID')->copyable(),
                                    Infolists\Components\TextEntry::make('phone')->label('Phone')->copyable(),
                                    Infolists\Components\TextEntry::make('address')->label('Address')->columnSpan(2),
                                ]),
                                Infolists\Components\Grid::make(2)->schema([
                                    Infolists\Components\TextEntry::make('question_1')->label('Question Security 1')->color('gray'),
                                    Infolists\Components\TextEntry::make('answer_1')->label('Answer 1')->weight(FontWeight::Bold),
                                    Infolists\Components\TextEntry::make('question_2')->label('Question Security 2')->color('gray'),
                                    Infolists\Components\TextEntry::make('answer_2')->label('Answer 2')->weight(FontWeight::Bold),
                                ])->extraAttributes(['class' => 'bg-gray-50 p-4 rounded-xl mt-4']),
                            ]),

                        // TAB 3: THÔNG SỐ MẠNG (ISP, PROXY...)
                        Infolists\Components\Tabs\Tab::make('Environment Info')
                            ->icon('heroicon-m-globe-alt')
                            ->schema([
                                Infolists\Components\Grid::make(3)->schema([
                                    Infolists\Components\TextEntry::make('proxy_type')->label('Proxy'),
                                    Infolists\Components\TextEntry::make('ip_address')->label('IP')->copyable(),
                                    Infolists\Components\TextEntry::make('location')->label('Location'),
                                    Infolists\Components\TextEntry::make('isp')->label('ISP / Nhà mạng'),
                                    Infolists\Components\TextEntry::make('browser')->label('Browser'),
                                    Infolists\Components\TextEntry::make('device')->label('Device Profile'),
                                ]),
                                Infolists\Components\TextEntry::make('note')
                                    ->label('Note')
                                    ->markdown()
                                    ->columnSpanFull()
                                    ->html() // Cho phép tự định nghĩa HTML để ép khoảng cách
                                    ->formatStateUsing(fn($state) => $state ?
                                        "<div style='white-space: pre-wrap; line-height: 1.6; margin: 0; padding: 0;'>" . e(trim($state)) . "</div>"
                                        : 'N/A'),

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
                    ->label('Wallet Name')
                    ->searchable()
                    ->alignment(Alignment::Center) // Ép tiêu đề vào giữa
                    ->wrap()
                    ->width('250px')
                    ->html()
                    ->state(function ($record) {
                        $name = $record->name;
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
                    ->label('Identifier')
                    ->copyable()
                    ->searchable()
                    ->html()
                    ->alignment(Alignment::Center)
                    ->wrap()
                    ->width('502px')
                    ->copyableState(function ($record) {
                        return
                            "<===== ACCOUNT =====>\n" .
                            "Email: {$record->email} | {$record->password}\n" .
                            "PayPal: {$record->paypal_account} | {$record->paypal_password}\n" .
                            "2FA: {$record->auth_code}\n" .
                            "Status: {$record->status}\n" .
                            "Note: {$record->note}\n" .
                            "<===== PERSONAL INFORMATION =====>\n" .
                            "Name: {$record->full_name}\n" .
                            "DOB: {$record->dob} | \n" .
                            "SSN / Tax ID: {$record->ssn}\n" .
                            "Phone: {$record->phone}\n" .
                            "Address: {$record->address}\n" .
                            "<===== SECURITY QUESTIONS =====>\n" .
                            "Q1: {$record->question_1} -> {$record->answer_1}\n" .
                            "Q2: {$record->question_2} -> {$record->answer_2}\n" .
                            "<===== CONNECTION & DEVICE =====>\n" .
                            "IP: {$record->ip_address} | Location: {$record->location} | ISP: {$record->isp} | \n" .
                            "Browser name: {$record->browser} | Device: {$record->device}\n";
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

                        $statusLabel = ucwords($record->status); // Viết hoa chữ cái đầu
                        $payPalAccount = $record->paypal_account;
                        $paypalPassword = $record->paypal_password;
                        $authcode = $record->auth_code;
                        $fullName = $record->full_name;
                        $dob = $record->dob;
                        $ssn = $record->ssn;
                        $phone = $record->phone;
                        $address = $record->address;

                        return "
                               <div style='display: block; text-align: left; line-height: 1.7;'>
                                    <div style='margin-bottom: 4px;'>
                                        <span style='color: #6b7280; display: inline-block;'>PayPal Account:</span> 
                                        <strong style='color: #111827;'>{$payPalAccount}</strong>
                                        <span style='color: #6b7280; display: inline-block;'> | </span> 
                                        <strong style='color: #111827;'>{$paypalPassword}</strong>
                                    </div>
                                    <div style='margin-bottom: 4px;'>
                                        <span style='color: #6b7280; display: inline-block;'>2FA/Auth Code:</span> 
                                        <span style='color: #111827;'>{$authcode}</span>
                                    </div>
                                    <div style='margin-bottom: 4px;'>
                                        <span style='color: #6b7280; display: inline-block;'>Full Name:</span> 
                                        <span style='color: #111827;'>{$fullName}</span>
                                        </div>
                                        <div style='margin-bottom: 4px;'>
                                        <span style='color: #6b7280; display: inline-block;'>DOB:</span> 
                                        <span style='color: #111827;'>{$dob}</span>
                                        </div>
                                        <div style='margin-bottom: 4px;'>
                                        <span style='color: #6b7280; display: inline-block;'>SSN / Tax ID:</span> 
                                        <span style='color: #111827;'>{$ssn}</span>
                                        </div>
                                        <div style='margin-bottom: 4px;'>
                                        <span style='color: #6b7280; display: inline-block;'>Phone Number:</span> 
                                        <span style='color: #111827;'>{$phone}</span>
                                        </div>
                                        <div style='margin-bottom: 4px;'>
                                        <span style='color: #6b7280; display: inline-block;'>Address:</span> 
                                        <span style='color: #111827;'>{$address}</span>
                                    </div>
                                    <div style='margin-bottom: 4px; white-space: nowrap;'>
                                        <span style='color: #6b7280; display: inline-block;'>Status:</span> 
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
                    ->label('Current Balance')
                    ->alignment(Alignment::Center)
                    ->money('usd')
                    ->color('success')
                    ->weight('bold'),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('type')
                    ->options([
                        'paypal_us' => 'PayPal US',
                        'paypal_vn' => 'PayPal VN',
                    ]),
            ])
            ->actions([
                // Nút Xem chi tiết (Hình con mắt) hiện ra bên ngoài
                Tables\Actions\ViewAction::make()
                    ->label('') // Để trống nhãn để chỉ hiện icon cho gọn
                    ->modalHeading('Account Details') // TIÊU ĐỀ CỦA MODAL
                    ->tooltip('Details') // Hiện ghi chú khi di chuột vào
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
                        ->label('Export to Google Sheet')
                        ->icon('heroicon-o-table-cells')
                        ->color('success')
                        ->action(function (\Illuminate\Database\Eloquent\Collection $records) {
                            try {
                                $sheetService = app(\App\Services\GoogleSheetService::class);

                                // Tên Tab mục tiêu (Bạn có thể đổi tùy ý)
                                $targetTab = 'Payout_Methods';

                                /// 1. Đảm bảo Tab tồn tại
                                $sheetService->createSheetIfNotExist($targetTab);

                                // 2. Định dạng tiêu đề (Nếu là Tab mới)
                                $sheetService->freezeAndFormatHeader($targetTab);

                                // 3. Upsert dữ liệu (Update nếu trùng ID, Append nếu mới)
                                $rows = $records->map(fn($record) => static::formatPayoutMethodForSheet($record))->toArray();

                                // 🟢 TRUYỀN THÊM HEADER VÀO ĐÂY
                                $sheetService->upsertRows($rows, $targetTab, static::$payoutMethodHeaders);

                                // Sync vào Tab riêng
                                $sheetService->formatColumnsAsClip($targetTab, 4, 8);   // Cột Email đến PayPal Pass
                                $sheetService->formatColumnsAsClip($targetTab, 13, 14); // Cột Full Address
                                $sheetService->formatColumnsAsClip($targetTab, 25, 26); // Cột Note

                                // 🟢 TÔ MÀU NGAY SAU KHI UPSERT
                                $statusIdx = array_search('Status', static::$payoutMethodHeaders);
                                $sheetService->applyFormattingWithRules($targetTab, $statusIdx, [
                                    'Limited' => ['red' => 1.0, 'green' => 0.8, 'blue' => 0.8],
                                ]);

                                \Filament\Notifications\Notification::make()
                                    ->title('Synced Successfully!')
                                    ->description('Synced ' . count($records) . ' account(s) to Google Sheets.')
                                    ->success()
                                    ->send();
                            } catch (\Exception $e) {
                                \Filament\Notifications\Notification::make()
                                    ->title('Sync Error!')
                                    ->body($e->getMessage()) // 🟢 Đã đổi sang body()
                                    ->danger()
                                    ->send();
                            }
                        }) // 🟢 Đã đóng ngoặc action chuẩn
                        ->deselectRecordsAfterCompletion(), // Tự động bỏ tick sau khi xuất xong
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }


    public static function getRelations(): array
    {
        return [
            //
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
