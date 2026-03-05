<?php

namespace App\Filament\Resources\Traits;

use App\Models\Account;
use App\Models\RebateTracker;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Support\Enums\Alignment;
use Filament\Tables\Columns\TextColumn;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Filament\Infolists\Infolist;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Enums\FiltersLayout;
use Illuminate\Support\Facades\DB;
use Filament\Forms\Components\Select;
use Filament\Tables\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Enums\FontWeight;
use Filament\Tables\Actions\BulkAction;
use Illuminate\Database\Eloquent\Collection;
use Filament\Navigation\NavigationItem;
use App\Filament\Resources\Traits\HasUsStates;
use App\Filament\Resources\Traits\HasPlatform;

use function Livewire\wrap;

// ... (nhớ import các thư viện TextInput, TextColumn, v.v. giống hệt bên file cũ của bạn)

trait HasAccountSchema
{

    // Dùng chung $usStates từ HasUsStates thay vì khai báo lại ở đây.
    use HasUsStates;
    // Dùng chung $platforms từ HasPlatform thay vì khai báo lại ở đây.
    use HasPlatform;


    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                // Gom nhóm các ô nhập liệu vào một Section để giao diện gọn gàng
                Forms\Components\Section::make('Account Details')
                    ->schema([
                        Forms\Components\Select::make('platform')
                            ->label('Platform')
                            ->placeholder('Rakuten, RetailMeNot, PayPal...')
                            ->options(self::$platform)
                            ->required()
                            ->native(false), // Giúp giao diện đồng bộ đẹp hơn

                        Forms\Components\Select::make('email_id')
                            ->label('Email Address')
                            ->placeholder('Select Email')
                            ->relationship('email', 'email') // Liên kết với bảng email
                            ->searchable()
                            ->preload()
                            ->required()
                            ->createOptionForm([ // Nút (+) tạo nhanh Email mới
                                Forms\Components\TextInput::make('email')->email()->required(),
                                Forms\Components\TextInput::make('email_password')->required(),
                                Forms\Components\TextInput::make('recovery_email')->email(),
                                Forms\Components\TextInput::make('two_factor_code')
                                    ->label('2FA Code / Recovery Code')
                                    ->placeholder('Nhập mã 2FA hoặc mã khôi phục...'),
                                Forms\Components\Select::make('status')
                                    ->label('Status')
                                    ->options([
                                        'active' => 'Live',
                                        'disabled' => 'Disabled',
                                        'locked' => 'Locked',
                                    ])
                                    ->default('active')
                                    ->required()
                                    ->native(false),

                                // ĐÂY LÀ CỘT NOTE BẠN YÊU CẦU
                                Forms\Components\Textarea::make('email_note')
                                    ->label('Email Note')
                                    ->placeholder('Lưu ý riêng cho email này...'),
                            ]),

                        Forms\Components\TextInput::make('password')
                            ->password() // Tự động ẩn mật khẩu khi nhập
                            ->revealable() // Thêm icon con mắt để bấm xem
                            ->required(),

                        Forms\Components\Select::make('state')
                            ->label('States')
                            ->placeholder('Select state')
                            ->options(self::$usStates),

                        Forms\Components\TextInput::make('device')
                            ->label('Device/Antidetect Create')
                            ->placeholder('iPhone 13, Windows 10, BitBrowser...'),

                        Forms\Components\Textarea::make('paypal_info')
                            ->label('PayPal Information')
                            ->placeholder('Full name, Full address...'),
                        //->columnSpanFull(),

                        Forms\Components\Textarea::make('device_linked_paypal')
                            ->label('Device/Antidetect Linked PayPal')
                            ->placeholder('iPhone 13, Windows 10, BitBrowser...'),

                        Forms\Components\Select::make('status')
                            ->multiple() // Cho phép chọn nhiều cái cùng lúc
                            ->options([
                                'active' => 'Active',
                                'used' => 'In Use',
                                'banned' => 'Banned',
                                'no_paypal_needed' => 'No PayPal Required',
                                'not_linked' => 'Not Linked to PayPal',
                                'limited' => 'PayPal Limited',
                                'linked' => 'Linked PayPal',
                                'unlinked' => 'Unlinked PayPal',
                            ])
                            ->searchable()
                            ->preload()
                            ->native(false), // Dùng giao diện hiện đại của Filament

                        // --- CHÈN ĐOẠN MÃ CỦA BẠN VÀO ĐÂY ---
                        Forms\Components\Select::make('user_id')
                            ->relationship('user', 'name')
                            ->label('Holder')
                            ->searchable()
                            ->preload(),

                        //Thêm ngày tạo tài khoản
                        Forms\Components\TextInput::make('account_created_at')
                            ->label('Date Create')
                            ->placeholder('dd/mm/yyyy (Leave blank if it doesn\'t exist)')
                            //->displayFormat('d/m/Y') // Định dạng hiển thị khi nhập
                            //->format('Y-m-d') // Định dạng chuẩn để lưu vào MySQL
                            //->native(false) // Dùng giao diện hiện đại của Filament
                            ->nullable() // Cho phép để trống
                            ->default(null) // Đảm bảo không tự động lấy ngày hiện tại
                            ->mask('99/99/9999') // Tạo khuôn dd/mm/yyyy khi gõ
                            ->rules(['date_format:d/m/Y'])
                            //->dehydrated(true), // Đảm bảo trường này được gửi về backend
                            //->live(),  // Đồng bộ dữ liệu ngay lập tức,
                            ->dehydrateStateUsing(function ($state) {
                                if (blank($state)) return null;
                                try {
                                    // Dịch từ chuẩn VN (d/m/Y) sang chuẩn Quốc tế (Y-m-d) để MySQL hiểu
                                    return \Carbon\Carbon::createFromFormat('d/m/Y', $state)->format('Y-m-d');
                                } catch (\Exception $e) {
                                    return null;
                                }
                            }),

                        //Thêm ngày linked paypal
                        Forms\Components\TextInput::make('paypal_linked_at')
                            ->label('Date Linked PayPal')
                            ->placeholder('dd/mm/yyyy (Leave blank if it doesn\'t exist)')
                            //->displayFormat('d/m/Y') // Định dạng hiển thị khi nhập
                            //->format('Y-m-d') // Định dạng chuẩn để lưu vào MySQL
                            //->native(false) // Dùng giao diện hiện đại của Filament
                            ->nullable() // Cho phép để trống
                            ->default(null) // Đảm bảo không tự động lấy ngày hiện tại
                            ->mask('99/99/9999') // Tạo khuôn dd/mm/yyyy khi gõ
                            ->rules(['date_format:d/m/Y'])
                            //->dehydrated(true), // Đảm bảo trường này được gửi về backend
                            //->live(),  // Đồng bộ dữ liệu ngay lập tức,
                            ->dehydrateStateUsing(function ($state) {
                                if (blank($state)) return null;
                                try {
                                    // Dịch từ chuẩn VN (d/m/Y) sang chuẩn Quốc tế (Y-m-d) để MySQL hiểu
                                    return \Carbon\Carbon::createFromFormat('d/m/Y', $state)->format('Y-m-d');
                                } catch (\Exception $e) {
                                    return null;
                                }
                            }),

                        Forms\Components\Textarea::make('note')
                            ->label('Note')
                            ->placeholder('Enter any special notes for this account if applicable...')
                            ->columnSpanFull(), // Chiếm trọn 2 cột nếu bạn đang chia grid
                        // -----------------------------------
                    ])
                    ->columns(2) // Chia làm 2 cột cho đẹp
            ]);
    }

    public static function infolist(\Filament\Infolists\Infolist $infolist): \Filament\Infolists\Infolist
    {
        return $infolist
            ->schema([
                // PHẦN 1: EMAIL INFORMATION
                \Filament\Infolists\Components\Section::make('Email Information')
                    ->schema([
                        \Filament\Infolists\Components\TextEntry::make('email.email')
                            ->label('Email Address')
                            ->placeholder('N/A')
                            ->copyable(), // Cho phép click để copy nhanh
                        \Filament\Infolists\Components\TextEntry::make('email.email_password')
                            ->label('Email Password')
                            ->placeholder('N/A'),
                        \Filament\Infolists\Components\TextEntry::make('email.recovery_email')
                            ->label('Recovery Email')
                            ->placeholder('N/A'),
                        \Filament\Infolists\Components\TextEntry::make('email.two_factor_code')
                            ->label('2FA/Code')
                            ->placeholder('N/A'),
                        \Filament\Infolists\Components\TextEntry::make('email.note')
                            ->label('Note (Email)')
                            ->placeholder('N/A'),
                        //->columnSpanFull(),
                        \Filament\Infolists\Components\TextEntry::make('email.status')
                            ->label('Status')
                            ->placeholder('N/A')
                            ->formatStateUsing(fn(string $state): string => match ($state) {
                                'active' => 'Live',
                                'disabled' => 'Disabled',
                                'locked' => 'Locked',
                                default => ucfirst($state),
                            })
                            ->color(fn(string $state): string => match ($state) {
                                'active' => 'success', // Màu xanh cho Live
                                'disabled' => 'warning',  // Màu vàng cho Disabled
                                'locked' => 'danger', // Màu đỏ cho Locked
                                default => 'gray',
                            })
                    ])->columns(2),

                // PHẦN 2: PLATFORM & SOURCE INFORMATION
                \Filament\Infolists\Components\Section::make('Platform & Source Information')
                    ->schema([
                        \Filament\Infolists\Components\TextEntry::make('platform')
                            ->label('Platform')
                            ->placeholder('N/A')
                            ->formatStateUsing(fn($state) => $state ? (self::$platform[$state] ?? '') : 'N/A'),
                        \Filament\Infolists\Components\TextEntry::make('password')
                            ->label('Platform Password')
                            ->placeholder('N/A'),
                        \Filament\Infolists\Components\TextEntry::make('state')
                            ->label('State')
                            ->placeholder('N/A')
                            ->formatStateUsing(fn($state) => $state ? "{$state} - " . (self::$usStates[$state] ?? '') : 'N/A'),
                        \Filament\Infolists\Components\TextEntry::make('device')
                            ->label('Device Create')
                            ->placeholder('N/A'),
                        \Filament\Infolists\Components\TextEntry::make('account_created_at')
                            ->label('Date Create')
                            ->dateTime('d/m/Y')
                            ->placeholder('N/A'),
                        \Filament\Infolists\Components\TextEntry::make('user.name')
                            ->label('Holder')
                            ->placeholder('N/A'),
                        \Filament\Infolists\Components\TextEntry::make('status')
                            ->label('Platform Status')
                            ->badge()
                            ->placeholder('N/A')
                            ->color(fn(string $state): string => match ($state) {
                                'active' => 'gray',    // Màu xám nhạt
                                'used'   => 'info',    // Màu xanh dương nhạt
                                'no_paypal_needed' => 'warning', // Màu xanh dương đậm
                                'not_linked' => 'warning', // Màu vàng nhạt
                                'linked' => 'success', // Màu xanh lá nhạt
                                'limited' => 'danger',   // Màu đỏ đậm
                                'unlinked' => 'warning', // Màu vàng nhạt
                                'banned' => 'danger',  // Màu đỏ đậm
                                default  => 'gray',
                            })
                            ->separator(',') // Hiển thị các nhãn cách nhau bằng dấu phẩy
                            ->formatStateUsing(fn(string $state): string => match ($state) {
                                'used'   => 'In Use', // Đổi nhãn used thành In Use cho rõ nghĩa hơn
                                'limited' => 'PayPal Limited', // Đổi riêng nhãn limited 
                                'linked'  => 'Linked PayPal', // Đổi nhãn linked cho rõ ràng
                                'unlinked'  => 'Unlinked PayPal', // Đổi nhãn unlinked
                                'not_linked' => 'Not Linked to PayPal', // Đổi nhãn not_linked
                                'no_paypal_needed' => 'No PayPal Required', // Đổi nhãn no_paypal_needed
                                default   => ucfirst($state), // Các nhãn khác chỉ viết hoa chữ cái đầu
                            }),
                        \Filament\Infolists\Components\TextEntry::make('note')
                            ->label('Platform Note')
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
                    ])->columns(3),

                // PHẦN 3: PAYPAL INFORMATION
                \Filament\Infolists\Components\Section::make('Paypal Information')
                    ->schema([
                        \Filament\Infolists\Components\TextEntry::make('device_linked_paypal')
                            ->label('Device Linked')
                            ->placeholder('N/A'),
                        \Filament\Infolists\Components\TextEntry::make('paypal_linked_at')
                            ->label('Date Linked PayPal')
                            ->dateTime('d/m/Y')
                            ->placeholder('N/A'),
                        \Filament\Infolists\Components\TextEntry::make('paypal_info')
                            ->label('Personal Information')
                            ->placeholder('N/A')
                            ->columnSpanFull(),
                    ])->columns(2),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->recordUrl(null) // Tắt link dẫn đến trang Edit khi click hàng
            ->columns([
                Tables\Columns\TextColumn::make('id')
                    ->label('ID')
                    ->searchable() // Cho phép tìm kiếm theo ID
                    ->toggleable() // Mặc định ẩn, khi nào cần thì bật lên cho đỡ chật bảng (isToggledHiddenByDefault: true)
                    ->color('gray'),

                // Hiển thị nền tảng (Rakuten, PayPal...)
                Tables\Columns\TextColumn::make('platform')
                    ->searchable()
                    ->alignment(Alignment::Center)
                    ->visible(static::class === \App\Filament\Resources\AccountResource::class)
                    ->formatStateUsing(fn($state) => $state ? (self::$platform[$state] ?? '') : 'N/A'),

                // HIỂN THỊ EMAIL TỪ BẢNG LIÊN KẾT
                // Lấy từ quan hệ email() trong Model Account
                TextColumn::make('email.email')
                    ->label('Email Address')
                    ->alignment(Alignment::Center)
                    ->searchable()
                    ->wrap()
                    ->width('25%')
                    ->copyable()
                    ->copyMessage('Copied email to clipboard!')
                    ->html()
                    ->formatStateUsing(function (Account $record): string {
                        $email = $record->email?->email ?? 'N/A';
                        $pass = $record->email?->email_password ?? 'N/A';
                        $rec = $record->email?->recovery_email ?? 'N/A';
                        $twoFA = $record->email?->two_factor_code ?? 'N/A';
                        $emailNote = $record->email?->note ?? 'N/A'; // Lấy Note từ bảng Email
                        $emailStatus = $record->email?->status ?? 'N/A'; // Lấy Status từ bảng Email
                        // Lấy label status chuẩn
                        [$emailstatusLabels, $emailstatusLabelsColor] = match ($emailStatus) {
                            'active' => ['Live', '#22c55e'],
                            'disabled' => ['Disabled', '#f59e0b'],
                            'locked' => ['Locked', '#ef4444'],
                            default   => [ucfirst($emailStatus), '#6b7280'],
                        };

                        return "
                            <div style='text-align: left; font-size: 13px; line-height: 1.6; padding-left: 5px;'>
                                <div style='margin-bottom: 2px; font-size: 14px;'>
                                    <span style='color: #1e293b; font-weight: 700;'>{$email}</span>
                                </div>
                
                                <div style='margin-bottom: 2px;'>
                                    <span style='color: #64748b;'>Email Password: </span> 
                                    <span style='color: #1e293b;'>{$pass}</span>
                                </div>

                                <div style='margin-bottom: 2px; overflow: hidden; text-overflow: ellipsis; white-space: wrap;'>
                                    <span style='color: #64748b;'>Recovery Email: </span> 
                                    <span style='color: #1e293b;'>{$rec}</span>
                                </div>

                                <div style='margin-bottom: 2px;'>
                                    <span style='color: #64748b;'>2FA/Code: </span> 
                                    <span style='color: #1e293b;'>{$twoFA}</span>
                                </div>
                            
                                <div style='margin-top: 8px; padding-top: 4px; border-top: 1px solid #f1f5f9; line-height: 1.8;'>
                                    <div style='margin-top: 2px; overflow: hidden; text-overflow: ellipsis; white-space: wrap;'>
                                        <span style='color: #64748b;'>Status: </span> 
                                        <span style='color: {$emailstatusLabelsColor};'>{$emailstatusLabels}</span>
                                    </div>

                                    <div style='margin-bottom: 2px;'>
                                        <span style='color: #64748b;'>Note: </span> 
                                        <span style='color: #1e293b;'>{$emailNote}</span>
                                    </div>
                                </div>
                            </div>
                        ";
                    }),

                // Hiển thị Password và cho phép Click để Copy
                TextColumn::make('password')
                    ->label('Platform Password')
                    ->alignment(Alignment::Center)
                    ->copyable()
                    ->copyMessage('Copied password to clipboard!')
                    ->copyMessageDuration(1500),

                // Column Gộp: Metadata (States, Device, PayPal Information)
                TextColumn::make('state')
                    ->label('Source Information')
                    ->width('250px')
                    ->alignment(Alignment::Center)
                    ->toggleable()
                    ->copyable()
                    ->wrap()
                    ->html()
                    ->formatStateUsing(function (Account $record): string {
                        $stateCode = $record->state ?? 'N/A';
                        $stateName = self::$usStates[$stateCode] ?? '';
                        // Nếu có tên bang thì hiện "CA - California", nếu không thì hiện "CA" hoặc "N/A"
                        $stateDisplay = $stateName ? "{$stateCode} - {$stateName}" : $stateCode;

                        $device = $record->device ?? 'N/A';
                        $paypal = $record->paypal_info ?? 'N/A';
                        $devicePaypal = $record->device_linked_paypal ?? 'N/A';
                        $created = $record->account_created_at ? $record->account_created_at->format('d/m/Y') : 'N/A';
                        $linked = $record->paypal_linked_at ? $record->paypal_linked_at->format('d/m/Y') : 'N/A';

                        return "
                            <div style='justity-content: flex-start !important; text-align: left; font-size: 13px; line-height: 1.6; max-width: 250px; padding-left: 5px;'>
                                <div style='margin-bottom: 2px;'>
                                    <span style='color: #64748b;'>State:</span> 
                                    <span style='color: #1e293b; font-weight: 500;'>{$stateDisplay}</span>
                                </div>
                
                            <div style='margin-bottom: 2px;'>
                                    <span style='color: #64748b;'>Device Create:</span> 
                                    <span style='color: #1e293b;'>{$device}</span>
                            </div>

                            <div style='margin-bottom: 2px;'>
                                    <span style='color: #64748b;'>Date Create:</span> 
                                    <span style='color: #1e293b;'>{$created}</span>
                            </div>
                
                            <div style='margin-top: 10px; overflow: hidden; text-overflow: ellipsis; white-space: wrap;'>
                                    <span style='color: #64748b;'>PayPal Information:</span> 
                                    <span style='color: #3b82f6; font-weight: 500;'>{$paypal}</span>
                            </div>
                
                            <div style='margin-top: 2px;'>
                                    <span style='color: #64748b;'>Device Linked:</span> 
                                    <span style='color: #1e293b;'>{$devicePaypal}</span>
                            </div>

                            <div style='margin-bottom: 2px;'>
                                    <span style='color: #64748b;'>Date Linked PayPal:</span> 
                                    <span style='color: #1e293b;'>{$linked}</span>
                            </div>
                        </div>
                    ";
                    }),

                // Column Platform Status hiển thị trạng thái bằng Badge
                TextColumn::make('status')
                    ->label('Platform Status')
                    ->badge()
                    ->alignment(Alignment::Center)
                    ->color(fn(string $state): string => match ($state) {
                        'active' => 'gray',    // Màu xám nhạt
                        'used'   => 'info',    // Màu xanh dương nhạt
                        'no_paypal_needed' => 'warning', // Màu xanh dương đậm
                        'not_linked' => 'warning', // Màu vàng nhạt
                        'linked' => 'success', // Màu xanh lá nhạt
                        'limited' => 'danger',   // Màu đỏ đậm
                        'unlinked' => 'warning', // Màu vàng nhạt
                        'banned' => 'danger',  // Màu đỏ đậm
                        default  => 'gray',
                    })
                    ->separator(',') // Hiển thị các nhãn cách nhau bằng dấu phẩy
                    ->formatStateUsing(fn(string $state): string => match ($state) {
                        'used'   => 'In Use', // Đổi nhãn used thành In Use cho rõ nghĩa hơn
                        'limited' => 'PayPal Limited', // Đổi riêng nhãn limited 
                        'linked'  => 'Linked PayPal', // Đổi nhãn linked cho rõ ràng
                        'unlinked'  => 'Unlinked PayPal', // Đổi nhãn unlinked
                        'not_linked' => 'Not Linked to PayPal', // Đổi nhãn not_linked
                        'no_paypal_needed' => 'No PayPal Required', // Đổi nhãn no_paypal_needed
                        default   => ucfirst($state), // Các nhãn khác chỉ viết hoa chữ cái đầu
                    })
                    ->tooltip(function (Tables\Columns\TextColumn $column, Account $record): string {
                        // Lấy mảng các trạng thái hiện có của tài khoản
                        $statuses = is_array($record->status) ? $record->status : [$record->status];
                        $explanations = [
                            'active' => 'Tài khoản mới tạo, sẵn sàng để gán cho Holder.',
                            'used' => 'Tài khoản đang trong quá trình sử dụng.',
                            'limited' => 'PayPal bị giới hạn (Limited), cần gỡ hoặc kiểm tra.',
                            'banned' => 'Tài khoản đã bị khóa, không thể sử dụng tiếp.',
                            'linked' => 'Đã liên kết thành công với PayPal Account.',
                            'unlinked' => 'Đã gỡ liên kết PayPal khỏi tài khoản này.',
                            'not_linked' => 'Tài khoản chưa được liên kết với PayPal.',
                            'no_paypal_needed' => 'Không yêu link PayPal.',
                        ];

                        // Duyệt qua các trạng thái hiện có và lấy giải thích tương ứng
                        return collect($statuses)
                            ->map(fn($s) => ($explanations[$s] ?? 'Trạng thái hệ thống'))
                            ->join("\n"); // Các giải thích sẽ xuống dòng nếu có nhiều badge

                    }),

                //Hiển thị ghi chú (note) nếu có, dưới dạng chữ nhỏ màu xanh dương
                TextColumn::make('note')
                    ->label('Note')
                    ->alignment(Alignment::Center)
                    ->limit(10) // Giữ hiển thị ngắn gọn ngoài bảng
                    ->tooltip(fn($state) => $state) // Đây là nơi gửi dữ liệu xuống dòng vào Tooltip
                    ->html()
                    ->formatStateUsing(fn($state) => nl2br(e($state)) ?? 'N/A'),

                // Hiển thị người đang giữ tài khoản
                TextColumn::make('user.name')
                    ->label('Holder')
                    ->alignment(Alignment::Center)
                    // Nếu chưa có người nhận (null), chúng sẽ hiển thị N/A
                    ->default('N/A')

                    ->color(fn(Account $record) => $record->user_id === null ? 'gray' : 'default')
                    ->html()
                    // CSS cho Get account
                    ->description(function (Account $record): ?\Illuminate\Support\HtmlString {
                        if ($record->user_id === null) {
                            return new \Illuminate\Support\HtmlString(
                                '<span class = "get-account-btn">Get account</span>'
                            );
                        }
                        return null;
                    })

                    ->extraAttributes(function (Account $record) {
                        $styles = 'font-size: 13px !important; font-weight: 400 !important; line-height: 1.2;';

                        if ($record->user_id === null) {
                            return [
                                'class' => 'cursor-pointer transition hover:opacity-70',
                                // Tuyệt chiêu: Gọi Action chính chủ của Filament
                                'wire:click.stop' => "mountTableAction('get_account', '{$record->id}')",
                            ];
                        }
                        return ['style' => $styles];
                    }),
            ])

            ->persistFiltersInSession() // Ghi nhớ bộ lọc trong phiên làm việc
            ->filters([
                // Thêm bộ lọc Sắp xếp
                Tables\Filters\SelectFilter::make('sort_by')
                    ->label('Sort Order')
                    ->options([
                        'newest' => 'Newest First', // Mới nhất đến cũ
                        'oldest' => 'Oldest First', // Cũ nhất đến mới
                    ])
                    ->placeholder('Default')
                    ->query(function ($query, array $data) {
                        if ($data['value'] === 'newest') {
                            return $query->reorder('account_created_at', 'desc');
                        }
                        if ($data['value'] === 'oldest') {
                            return $query->reorder('account_created_at', 'asc');
                        }
                    }),
                // Lọc theo Status Email
                SelectFilter::make('email_status')
                    ->label('Email Status')
                    ->options([
                        'active' => 'Live',
                        'disabled' => 'Disabled',
                        'locked' => 'Locked',
                    ])
                    ->query(fn($query, $data) => $query->when(
                        $data['value'],
                        fn($q, $value) => $q->whereHas('email', fn($q) => $q->where('status', $value))
                    )),

                // Lọc theo Platform (Rakuten, JoinHoney, ...)
                SelectFilter::make('platform')
                    ->label('Platform')
                    ->multiple() // Cho phép tích nhiều checkbox
                    ->options(function () {
                        $platforms = \App\Models\Account::query()
                            ->distinct()
                            ->whereNotNull('platform')
                            ->pluck('platform', 'platform')
                            ->map(fn($label) => (string)$label)
                            ->toArray();

                        // 🟢 2. FORMAT LẠI NHÃN (LABEL) NGAY BÊN TRONG HÀM OPTIONS
                        $formattedOptions = [];
                        foreach ($platforms as $p) {
                            // Dùng mảng $platform từ Trait HasPlatform của bạn để map label, 
                            // nếu không có thì giữ nguyên tên gốc
                            $formattedOptions[$p] = self::$platform[$p] ?? $p;
                        }

                        return $formattedOptions;
                    })
                    ->searchable(),

                // Lọc Date Create theo Year
                Filter::make('year_created')
                    ->form([
                        \Filament\Forms\Components\Select::make('year')
                            ->label('Year Create')
                            ->placeholder('All Year')
                            ->multiple() // Cho phép tích nhiều checkbox
                            ->options(function () {
                                // Lấy tất cả năm từ cột account_created_at, sắp xếp mới nhất lên đầu
                                return \App\Models\Account::query()
                                    ->selectRaw('YEAR(account_created_at) as year')
                                    ->whereNotNull('account_created_at')
                                    ->distinct()
                                    ->orderBy('year', 'desc')
                                    ->pluck('year', 'year')
                                    ->toArray();
                            }),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query->when(
                            $data['year'],
                            fn(Builder $query, $year): Builder => $query->whereYear('account_created_at', $year),
                        );
                    }),

                //Lọc Holder (Quản lý nhân sự)
                SelectFilter::make('user_id')
                    ->label('Holder')
                    ->options(function () {
                        // Lấy danh sách tất cả User
                        return \App\Models\User::query()
                            ->whereNotNull('name') // Chỉ lấy User có tên
                            ->where('name', '!=', '') // Loại bỏ chuỗi rỗng
                            ->pluck('name', 'id')
                            ->map(fn($name) => (string) $name) // Ép kiểu string chắc chắn
                            ->prepend('N/A (Chưa có Holder)', 'unassigned') // Thêm N/A vào đầu danh sách
                            ->toArray();
                    })

                    ->query(function (Builder $query, array $data) {
                        if ($data['value'] === 'unassigned') {
                            // Nếu chọn N/A, lọc các bản ghi có user_id là null
                            return $query->whereNull('user_id');
                        }

                        // Ngược lại, lọc theo ID của User bình thường
                        return $query->when($data['value'], fn($q, $id) => $q->where('user_id', $id));
                    })
                    ->searchable()
                    ->preload(),

            ])


            // Giới hạn số cột hiển thị trên 1 hàng (ví dụ 4 cột cho 4 bộ lọc)
            ->filtersFormColumns(5)

            // HIỂN THỊ DÀN HÀNG NGANG TRÊN ĐẦU BẢNG
            ->filtersLayout(FiltersLayout::AboveContent)


            ->actions([
                // Hành động ẩn xử lý khi bấm vào chữ Get account từ cột Holder
                Tables\Actions\Action::make('get_account')
                    ->label('Get account')
                    // Không dùng hidden() hay visible(false) vì sẽ gây lỗi khi gọi
                    // Ẩn bằng CSS nhưng Action vẫn tồn tại trong hệ thống
                    ->extraAttributes([
                        'style' => 'display: none !important;',
                    ])
                    // GIỮ NGUYÊN LOGIC NHẬN DIỆN USER CỦA BẠN
                    ->action(function (Account $record) {
                        $record->update(['user_id' => auth()->id()]);

                        \Filament\Notifications\Notification::make()
                            ->title('Account claimed successfully!')
                            ->success()
                            ->send();
                    }),

                // Nút Xem chi tiết (Hình con mắt) hiện ra bên ngoài
                Tables\Actions\ViewAction::make()
                    ->label('') // Để trống nhãn để chỉ hiện icon cho gọn
                    ->modalHeading('Account Details') // TIÊU ĐỀ CỦA MODAL
                    ->tooltip('Details') // Hiện ghi chú khi di chuột vào
                    ->icon('heroicon-o-eye')
                    ->color('gray'), // Màu xám nhẹ nhàng, không lấn át nút cam

                // Nút 3 chấm (Add Quick Order, Edit, Copy...)
                Tables\Actions\ActionGroup::make([
                    //Thêm đơn từ List accounts
                    Tables\Actions\Action::make('add_quick_order')
                        ->label('Add Quick Order')
                        ->icon('heroicon-m-plus-circle')
                        ->color('success')
                        ->modalWidth('2xl')
                        // Form hiện lên trong Pop-up (Modal)
                        ->form([
                            Forms\Components\Grid::make(2)
                                ->schema([
                                    Forms\Components\TextInput::make('transaction_date')
                                        ->label('Transaction date')
                                        ->placeholder('dd/mm/yyyy')
                                        //->displayFormat('d/m/Y')
                                        //->format('Y-m-d') // Định dạng chuẩn để lưu vào MySQL
                                        //->native(false)
                                        ->nullable() // Cho phép để trống
                                        ->default(null) // Đảm bảo không tự động lấy ngày hiện tại
                                        ->mask('99/99/9999') // Tạo khuôn dd/mm/yyyy khi gõ
                                        ->rules(['date_format:d/m/Y'])
                                        //->dehydrated(true), // Đảm bảo trường này được gửi về backend
                                        //->live(),  // Đồng bộ dữ liệu ngay lập tức,
                                        ->dehydrateStateUsing(function ($state) {
                                            if (blank($state)) return null;
                                            try {
                                                // Dịch từ chuẩn VN (d/m/Y) sang chuẩn Quốc tế (Y-m-d) để MySQL hiểu
                                                return \Carbon\Carbon::createFromFormat('d/m/Y', $state)->format('Y-m-d');
                                            } catch (\Exception $e) {
                                                return null;
                                            }
                                        }),

                                    Forms\Components\TextInput::make('store_name')
                                        ->label('Store Name')
                                        ->required()
                                        ->columnSpanFull(),
                                    Forms\Components\TextInput::make('order_id')
                                        ->label('Order ID (#)'),
                                    Forms\Components\TextInput::make('order_value')
                                        ->label('Order Value ($)')
                                        ->numeric()
                                        ->prefix('$')
                                        ->required()
                                        ->reactive(),
                                    Forms\Components\TextInput::make('cashback_percent')
                                        ->label('% Cashback')
                                        ->numeric()
                                        ->default(10)
                                        ->suffix('%'),
                                ]),
                        ])
                        ->action(function ($record, array $data) {
                            // $record chính là Account đang chọn
                            RebateTracker::create([
                                'account_id' => $record->id,
                                'user_id' => auth()->id(),
                                'transaction_date' => $data['transaction_date'],
                                'store_name' => $data['store_name'],
                                'order_id' => $data['order_id'],
                                'order_value' => $data['order_value'],
                                'cashback_percent' => $data['cashback_percent'],
                                'rebate_amount' => (float)$data['order_value'] * ($data['cashback_percent'] / 100),
                                'status' => 'clicked', // Mặc định là đã click/mua
                            ]);
                        })
                        ->successNotificationTitle('Added successfully!'),

                    Tables\Actions\EditAction::make(),
                    Tables\Actions\DeleteAction::make(),

                    Tables\Actions\Action::make('copy_full_info')
                        ->label('Copy')
                        ->icon('heroicon-m-clipboard-document-check')
                        ->action(function ($record, $livewire) {

                            //  Khai báo Header
                            $header = " | ID | Email Status | Year Created | Email Address | Email Password | Recovery Email | 2FA Code | Email Note | Platform | Platform Password | State | Device Create | Date Create | Platform Status | Platform Note | Holder | Personal Information | Device Linked | Date Linked PayPal | ";

                            $id = $record->id; // Lấy ID của Account

                            // Lấy thông tin từ Email gốc
                            $emailStatus = $record->email?->status ?? 'N/A'; // Lấy Status từ bảng Email
                            // Chuyển đổi sang nhãn hiển thị Live/Disabled/Locked
                            $emailstatusLabels = [
                                'active' => 'Live',
                                'disabled' => 'Disabled',
                                'locked'   => 'Locked',
                            ];
                            $emailStatus = $emailstatusLabels[$emailStatus] ?? ucfirst($emailStatus); // Kết quả: "Active, Locked" hoặc "Disabled"

                            // Lấy ngày tạo từ bảng Email thay vì bảng Account
                            $emailDateCreated = $record->email?->email_created_at ? $record->email->email_created_at->format('d/m/Y') : 'N/A';
                            // Lấy năm từ email_created_at của Email, nếu không có thì lấy N/A
                            $yearCreated = $record->email?->email_created_at ? $record->email->email_created_at->format('Y') : 'N/A';

                            $email = $record->email?->email ?? 'N/A';
                            $emailPass = $record->email?->email_password ?? 'N/A';
                            $recovery = $record->email?->recovery_email ?? 'N/A';
                            $twoFA = $record->email?->two_factor_code ?? 'N/A';
                            $emailNote = $record->email?->note ?? 'N/A';

                            // Lấy thông tin từ Account (Platform)
                            $platform = $record->platform ?? 'N/A';
                            $platformPass = $record->password ?? 'N/A';
                            $stateName = self::$usStates[$record->state] ?? $record->state ?? 'N/A';
                            // Định dạng Ngày tạo account (Nếu trống thì hiện "N/A")
                            $platformDateCreated = $record->account_created_at ? $record->account_created_at->format('d/m/Y') : 'N/A';
                            $device = $record->device ?? 'N/A';
                            $paypal = $record->paypal_info ?? 'N/A';
                            $devicePaypal = $record->device_linked_paypal ?? 'N/A';
                            // Định dạng Ngày Linked PayPal (Nếu trống thì hiện "N/A")
                            $dateLinked = $record->paypal_linked_at ? $record->paypal_linked_at->format('d/m/Y') : 'N/A';
                            $platformstatus = $record->status ? implode(', ', (array)$record->status) : 'N/A';
                            $statusLabels = [
                                'active' => 'Active',
                                'used' => 'In Use',
                                'limited' => 'PayPal Limited',
                                'banned' => 'Banned',
                                'linked' => 'Linked PayPal',
                                'unlinked' => 'Unlinked PayPal',
                                'not_linked' => 'Not Linked to PayPal',
                                'no_paypal_needed' => 'No PayPal Required',
                            ];
                            // Chuyển mảng status (nếu có nhiều status) thành chuỗi nhãn đẹp
                            $currentStatuses = is_array($record->status) ? $record->status : explode(',', (string)$record->status);

                            $platformstatus = collect($currentStatuses)
                                ->map(function ($s) use ($statusLabels) {
                                    $key = trim($s); // Loại bỏ khoảng trắng thừa nếu có
                                    return $statusLabels[$key] ?? ucfirst($key);
                                })
                                ->join(', '); // Kết quả: "Active, Not Linked to PayPal"

                            $note = $record->note ?? 'N/A';
                            $holder = $record->user?->name ?? 'N/A';



                            // ĐỊNH DẠNG 1: Tất cả trên 1 dòng (Ngăn cách bằng dấu |)
                            $singleLine = " | {$id} | {$emailStatus} | {$yearCreated} | {$email} | {$emailPass} | {$recovery} | {$twoFA} | {$emailNote} | {$record->platform} | {$record->password} | {$stateName} | {$device} | {$platformDateCreated} | {$platformstatus} | {$note} | {$holder} | {$paypal} | {$devicePaypal} | {$dateLinked} | \n";
                            $finalSingleLine = $header . "\n" . $singleLine; // Kết hợp header và data

                            // ĐỊNH DẠNG 2: Chia thành nhiều dòng chi tiết
                            $multiLine =
                                "EMAIL INFORMATION:\n" .
                                "Email Status: {$emailStatus}\n" .
                                "Year Created: {$yearCreated}\n" .
                                "Email Address: {$email}\n" .
                                "Email Password: {$emailPass}\n" .
                                "Recovery Email: {$recovery}\n" .
                                "2FA Code: {$twoFA}\n" .
                                "Email Note: {$emailNote}\n" .
                                "--------------------------\n" .
                                "SOURCE & PLATFORM:\n" .
                                "Platform: {$platform}\n" .
                                "Platform Password: {$platformPass}\n" .
                                "State: {$stateName}\n" .
                                "Device Create: {$device}\n" .
                                "Date Create: {$platformDateCreated}\n" .
                                "Platform Status: {$platformstatus}\n" .
                                "Platform Note: {$note}\n" .
                                "Holder: {$holder}\n" .
                                "--------------------------\n" .
                                "PAYPAL INFORMATION:\n" .
                                "Personal Information: {$paypal}\n" .
                                "Device Linked: {$devicePaypal}\n" .
                                "Date Linked PayPal: {$dateLinked}\n";

                            // Gộp cả 2 định dạng vào 1 lần copy
                            $info = $finalSingleLine . "\n\n" . $multiLine;

                            // Gửi sự kiện để JavaScript thực hiện copy
                            $livewire->dispatch('copy-to-clipboard', text: $info);

                            \Filament\Notifications\Notification::make()
                                ->title('Copied Successfully!')
                                ->success()
                                ->send();
                        }),
                ])
            ])

            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\BulkAction::make('export_accounts_to_google_sheet')
                        ->label('Export to Google Sheet')
                        ->icon('heroicon-o-table-cells')
                        ->color('success')
                        ->action(function (Collection $records) {
                            $sheetService = app(\App\Services\GoogleSheetService::class);
                            // FIX: Nhóm theo platform trước, rồi upsert từng tab 1 lần.
                            // Trước đây chỉ lấy platform của record đầu tiên → các platform khác bị đẩy sai tab.
                            $grouped = $records->groupBy(fn($r) => $r->platform ?: 'General');

                            $totalUpdated  = 0;
                            $totalAppended = 0;

                            foreach ($grouped as $platform => $items) {
                                $targetTab = ucfirst($platform) . '_Accounts';

                                // Gom toàn bộ rows của platform này thành 1 mảng — 1 lần gọi API duy nhất
                                $rows = $items
                                    ->map(fn($r) => static::formatAccountForSheet($r))
                                    ->values()
                                    ->toArray();

                                $sheetService->createSheetIfNotExist($targetTab);

                                // FIX #3 (BulkAction): Truyền $headers để tab mới có hàng tiêu đề
                                $result = $sheetService->upsertRows($rows, $targetTab, static::$accountHeaders);

                                // Ép định dạng Clip
                                $sheetService->formatColumnsAsClip($targetTab, 5, 6);   // Note Email (F)
                                $sheetService->formatColumnsAsClip($targetTab, 14, 15); // Platform Note (O)
                                $sheetService->formatColumnsAsClip($targetTab, 17, 18); // Personal Info (R)

                                $totalUpdated  += $result['updated'];
                                $totalAppended += $result['appended'];
                            }

                            \Filament\Notifications\Notification::make()
                                ->title('Accounts Synced!')
                                ->body("Updated {$totalUpdated}, Appended {$totalAppended} across " . $grouped->count() . ' tab(s).')
                                ->success()
                                ->send();
                        })
                        ->deselectRecordsAfterCompletion(),  // Tự động bỏ tick sau khi xuất xong

                    Tables\Actions\BulkAction::make('get_bulk_accounts')
                        ->label('Get Selected Accounts')
                        ->icon('heroicon-m-user-plus')
                        ->color('success')
                        ->requiresConfirmation()
                        ->action(function (\Illuminate\Database\Eloquent\Collection $records) {
                            $currentUserId = auth()->id(); // Lấy con số ID (ví dụ: 1, 2, 3)

                            foreach ($records as $record) {
                                // Kiểm tra nếu chưa có ai nhận (user_id đang trống)
                                if (empty($record->user_id)) {
                                    $record->update([
                                        'user_id' => $currentUserId,
                                    ]);

                                    // Ép buộc làm mới để giao diện nhận diện thay đổi
                                    $record->refresh();
                                }
                            }
                            \Filament\Notifications\Notification::make()
                                ->title("Claimed Successfully!")
                                ->success()
                                ->send();
                        })
                        // QUAN TRỌNG: Lệnh này giúp Filament biết cần phải render lại bảng
                        ->deselectRecordsAfterCompletion(),



                    \Filament\Tables\Actions\ExportBulkAction::make()
                        ->exporter(\App\Filament\Exports\AccountExporter::class)
                        ->label('Export Selected')
                        ->icon('heroicon-m-arrow-down-tray')
                        ->color('success')
                        ->deselectRecordsAfterCompletion(),

                    Tables\Actions\BulkAction::make('copy_account_selected')
                        ->label('Copy Selected')
                        ->icon('heroicon-m-clipboard-document-list')
                        ->color('warning')
                        ->action(function (\Illuminate\Database\Eloquent\Collection $records, $livewire) {
                            // 1. Tạo hàng tiêu đề (Header)
                            $header = " | ID | Email Status | Year Created | Email Address | Email Password | Recovery Email | 2FA Code | Email Note | Platform | Platform Password | State | Device Create | Date Create | Platform Status | Platform Note | Holder | Personal Information | Device Linked | Date Linked PayPal | ";
                            $output = $header . "\n";

                            foreach ($records as $record) {
                                $id = $record->id; // Lấy ID của Account
                                // 2. Thu thập dữ liệu từ quan hệ Email và Account
                                $emailStatus = $record->email?->status ?? 'N/A'; // Lấy Status từ bảng Email
                                // Chuyển đổi sang nhãn hiển thị Live/Disabled/Locked
                                $emailstatusLabels = [
                                    'active' => 'Live',
                                    'disabled' => 'Disabled',
                                    'locked'   => 'Locked',
                                ];
                                $emailStatus = $emailstatusLabels[$emailStatus] ?? ucfirst($emailStatus); // Kết quả: "Active, Locked" hoặc "Disabled"

                                $yearCreated = $record->email?->email_created_at ? $record->email->email_created_at->format('Y') : 'N/A';
                                $email = $record->email?->email ?? 'N/A';
                                $emailPass = $record->email?->email_password ?? 'N/A';
                                $recovery = $record->email?->recovery_email ?? 'N/A';
                                $twoFA = $record->email?->two_factor_code ?? 'N/A';
                                $emailNote = $record->email?->note ?? 'N/A'; // Email Note từ bảng Email

                                // Lấy thông tin từ Account (Platform)
                                $platform = $record->platform ?? 'N/A';
                                $passPlatform = $record->password ?? 'N/A';



                                // Định dạng Ngày tạo account (Nếu trống thì hiện "N/A")
                                $platformDateCreated = $record->account_created_at ? $record->account_created_at->format('d/m/Y') : 'N/A';
                                $device = $record->device ?? 'N/A';
                                $paypal = $record->paypal_info ?? 'N/A';
                                $devicePaypal = $record->device_linked_paypal ?? 'N/A';

                                // Định dạng Ngày Linked PayPal (Nếu trống thì hiện "N/A")
                                $dateLinked = $record->paypal_linked_at ? $record->paypal_linked_at->format('d/m/Y') : 'N/A';


                                $platformstatus = $record->status ? implode(', ', (array)$record->status) : 'N/A';
                                $stateName = self::$usStates[$record->state] ?? $record->state ?? 'N/A';
                                $statusLabels = [
                                    'active' => 'Active',
                                    'used' => 'In Use',
                                    'limited' => 'PayPal Limited',
                                    'banned' => 'Banned',
                                    'linked' => 'Linked PayPal',
                                    'unlinked' => 'Unlinked PayPal',
                                    'not_linked' => 'Not Linked to PayPal',
                                    'no_paypal_needed' => 'No PayPal Required',
                                ];

                                // XỬ LÝ STATUS CHUẨN: Chuyển "used, linked" thành "In Use, Linked PayPal"
                                $currentStatuses = is_array($record->status) ? $record->status : [$record->status];
                                $platformstatus = collect($currentStatuses)
                                    ->map(fn($s) => $statusLabels[$s] ?? ucfirst($s))
                                    ->join(', '); // Đổi từ | sang dấu phẩy ở đây

                                $accNote = $record->note ?? 'N/A'; // Account Note
                                $holder = $record->user?->name ?? 'N/A';

                                // 3. Gộp thành một dòng dữ liệu
                                $output .= " | {$id} | {$emailStatus} | {$yearCreated} | {$email} | {$emailPass} | {$recovery} | {$twoFA} | {$emailNote} | {$record->platform} | {$record->password} | {$stateName} | {$device} | {$platformDateCreated} | {$platformstatus} | {$accNote} | {$holder} | {$paypal} | {$devicePaypal} | {$dateLinked} | \n";
                            }

                            // Gửi lệnh copy tới trình duyệt
                            $livewire->dispatch('copy-to-clipboard', text: $output);

                            // Thông báo thành công
                            \Filament\Notifications\Notification::make()
                                ->title('Copied Successfully!')
                                ->success()
                                ->send();
                        })
                        ->deselectRecordsAfterCompletion(), // Tự động bỏ chọn sau khi copy xong

                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    // =========================================================
    // DÁN ĐOẠN NÀY VÀO ĐÂY (NẰM NGOÀI HÀM TABLE)
    // =========================================================
    // 1. Khai báo 19 Header khớp với index 0 -> 18
    public static array $accountHeaders = [
        'ID',
        'Email Address',
        'Email Password',
        'Recovery Email',
        '2FA/Code',
        'Note (Email)',
        'Status (Email)',
        'Platform',
        'Platform Password',
        'State Create',
        'Device Create',
        'Date Create',
        'Platform Status',
        'Holder',
        'Platform Note',
        'Device Linked',
        'Date Linked PayPal',
        'Personal Information',
        'Last Sync'
    ];

    public static function formatAccountForSheet($record): array
    {
        // 1. Logic xử lý PLATFORM STATUS (Đã làm trước đó)
        $rawStatuses = $record->status;
        $statusArray = is_array($rawStatuses) ? $rawStatuses : (json_decode($rawStatuses, true) ?? [$rawStatuses]);
        $mappedPlatformStatuses = array_map(function ($status) {
            return match ($status) {
                'used'             => 'In Use',
                'limited'          => 'PayPal Limited',
                'linked'           => 'Linked PayPal',
                'unlinked'         => 'Unlinked PayPal',
                'not_linked'       => 'Not Linked to PayPal',
                'no_paypal_needed' => 'No PayPal Required',
                default            => ucfirst(str_replace('_', ' ', (string)$status)),
            };
        }, array_filter($statusArray));
        $platformStatusString = implode(' → ', $mappedPlatformStatuses);

        // 2. Logic xử lý EMAIL STATUS (Yêu cầu mới của bạn)
        $rawEmailStatus = $record->email?->status;
        $emailStatusLabel = match ($rawEmailStatus) {
            'active'   => 'Live',
            'disabled' => 'Disabled',
            'locked'   => 'Locked',
            null       => 'N/A',
            default    => ucfirst((string)$rawEmailStatus),
        };

        // 3. KHỞI TẠO KHUNG 19 CỘT (0 -> 18) ĐỂ TRÁNH LỖI JSON PAYLOAD 400
        $data = array_fill(0, 19, '');

        $data[0]  = $record->id;                                                                                // ID
        $data[1]  = $record->email?->email ?? 'N/A';                                                            // Email Address
        $data[2]  = $record->email?->email_password ?? 'N/A';                                                   // Email Password
        $data[3]  = $record->email?->recovery_email ?? 'N/A';                                                   // Recovery Email
        $data[4]  = $record->email?->two_factor_code ?? 'N/A';                                                  // 2FA/Code
        $data[5]  = $record->email?->note ?? 'N/A';                                                             // Note (Email)
        $data[6]  = $emailStatusLabel;                                                                          // CỘT G: Status (Email) - ĐÃ APPLY MAP
        $data[7]  = $record->platform;                                                                          // Platform
        $data[8]  = $record->password;                                                                          // Platform Password
        $data[9]  = $record->state ? "{$record->state} - " . (self::$usStates[$record->state] ?? '') : 'N/A';   // State Create
        $data[10] = $record->device ?? 'N/A';                                                                   // Device Create
        $data[11] = $record->account_created_at?->format('d/m/Y') ?? 'N/A';                                     // Date Create        
        $data[12] = $platformStatusString;                                                                      // Platform Status
        $data[13] = $record->user?->name ?? 'N/A';                                                              // Holder
        $data[14] = $record->note ?? '';                                                                        // Platform Note
        $data[15] = $record->device_linked_paypal ?? 'N/A';                                                     // Device Linked
        $data[16] = $record->paypal_linked_at?->format('d/m/Y') ?? 'N/A';                                       // Date Linked PayPal       
        $data[17] = $record->paypal_info ?? 'N/A';                                                              // Personal Information
        $data[18] = now()->format('d/m/Y H:i:s');                                                               // Last Sync

        return array_values($data); // Đảm bảo mảng luôn liên tục (sequential)
    }

    public static function syncSingleAccountToSheet($record): void
    {
        // 1. Lấy dữ liệu 19 cột đã format
        $row = static::formatAccountForSheet($record);
        $sheetService = app(\App\Services\GoogleSheetService::class);

        // 🟢 CẢI TIẾN: Nếu platform rỗng, dùng 'General' thay vì để nó ra '_Accounts'
        $platform = $record->platform ?: 'General';
        $targetTab = ucfirst($platform) . '_Accounts'; // FIX #2: ucfirst để đúng tên tab

        // 2. Gọi hàm mới: Tìm ID ở cột A và ghi đè đúng hàng đó
        // Truyền vào mảng [$row] vì hàm upsertRows nhận danh sách các hàng
        // Đồng bộ vào Tab riêng (Ví dụ: Rakuten_Accounts hoặc General_Accounts)
        // 🟢 CẢI TIẾN: Truyền thêm Header để tự tạo Tab mới chuẩn cột
        $sheetService->upsertRows([$row], $targetTab, static::$accountHeaders);

        // 3. Định dạng Clip riêng cho tab này
        //$sheetService->formatColumnsAsClip($targetTab, 5, 6);   // Note Email (F)
        //$sheetService->formatColumnsAsClip($targetTab, 14, 15); // Platform Note (O)
        //$sheetService->formatColumnsAsClip($targetTab, 17, 18); // Personal Info (R)
    }

    // bootHasAccountSchema ĐÃ XÓA (FIX #7):
    // Sync Account lên Sheet được thực hiện qua Observer hoặc gọi trực tiếp
    // syncSingleAccountToSheet() trong action. Không dùng boot() để tránh
    // double-dispatch nếu sau này thêm AccountObserver.
}
