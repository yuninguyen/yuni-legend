<?php

namespace App\Filament\Resources;

use App\Filament\Resources\PayoutLogResource\Pages;
use App\Filament\Resources\PayoutLogResource\RelationManagers;
use App\Models\PayoutLog;
use App\Models\PayoutMethod;
use App\Models\Account;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Filament\Support\Enums\Alignment;
use Filament\Support\Enums\FontWeight;
use Illuminate\Database\Eloquent\Model;
use Filament\Forms\Get;

class PayoutLogResource extends Resource
{
    protected static ?string $model = PayoutLog::class;

    protected static ?string $navigationIcon = 'heroicon-o-rectangle-stack';
    protected static ?string $navigationGroup = 'WALLET & PAYOUTS';
    protected static ?string $navigationLabel = 'Payout Logs';
    protected static ?int $navigationSort = 2;

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();

        // 1. Khóa chặt dòng Con luôn nằm ngay dưới dòng Cha tương ứng của nó
        $query->orderByRaw('COALESCE(parent_id, id) DESC')->orderBy('id', 'ASC');

        // 🟢 THÊM LẠI DÒNG NÀY: Phục hồi bùa đếm dòng con để hiện chữ Exchanged!
        $query->withCount('children');

        $user = auth()->user();

        // 2. Nếu là Admin -> Cho xem tất cả
        if ($user && method_exists($user, 'isAdmin') && $user->isAdmin()) {
            return $query;
        }

        // 3. Chốt chặn cho Staff
        return $query->where(function ($q) use ($user) {
            $q->where('payout_logs.user_id', $user->id)
                ->orWhereIn('payout_logs.parent_id', function ($subQuery) use ($user) {
                    $subQuery->select('id')
                        ->from('payout_logs')
                        ->where('user_id', $user->id);
                });
        });
    }

    /**
     * 🟢 QUY TẮC 1: TIÊU ĐỀ DUY NHẤT TẠI ĐÂY
     */
    public static array $payoutLogHeaders = [
        'ID',
        'Date',
        'Email',
        'Platform',
        'Wallet',
        'Asset type',
        'Gift Card Brand',
        'Card number',
        'PIN',
        'Transaction type',
        'Amount',
        'Fee',
        'Boost (%)',
        'Net USD',
        'Rate',
        'VND',
        'Status',
        'Note'

    ];

    /**
     * 🟢 QUY TẮC 2: LOGIC ĐỔ DỮ LIỆU DUY NHẤT TẠI ĐÂY
     */
    public static function formatPayoutLogForSheet($record): array
    {
        return [
            (string) $record->id,
            (string) $record->created_at->format('d/m/Y H:i'),
            (string) ($record->account?->email?->email ?? 'N/A'),
            (string) strtoupper($record->account?->platform ?? 'N/A'),
            (string) ($record->payoutMethod?->name ?? ($record->asset_type === 'gift_card' ? 'In-Hand' : 'N/A')),
            (string) strtoupper(str_replace('_', ' ', $record->asset_type ?? 'N/A')),
            (string) ucwords(str_replace('_', ' ', $record->gc_brand ?? 'N/A')),
            (string) ($record->gc_code ?? 'N/A'),
            (string) ($record->gc_pin ?? 'N/A'),
            (string) ucfirst($record->transaction_type ?? 'N/A'),
            (string) number_format($record->amount_usd, 2, '.', ''),
            (string) number_format($record->fee_usd, 2, '.', ''),
            (string) $record->boost_percentage . '%',
            (string) number_format($record->net_amount_usd, 2, '.', ''),
            (string) number_format($record->exchange_rate ?? 0, 0, '.', ','),
            (string) number_format($record->total_vnd ?? 0, 0, '.', ','),
            (string) ucfirst($record->status),
            (string) ($record->note ?? ''),
        ];
    }

    /**
     * 🟢 QUY TẮC 3: CÁC HÀM SYNC GỌI LẠI QUY TẮC 1 & 2
     */

    public static function syncToGoogleSheet(): void
    {
        $sheetService = app(\App\Services\GoogleSheetService::class);
        $targetTab = 'Payout_Logs';

        try {
            $sheetService->createSheetIfNotExist($targetTab);

            $allPayouts = \App\Models\PayoutLog::with(['account.email', 'payoutMethod'])
                ->orderBy('created_at', 'desc')
                ->get();

            // 1. Lấy Header từ Quy tắc 1
            $rows = [static::$payoutLogHeaders];

            // 2. Lấy Data từ Quy tắc 2
            foreach ($allPayouts as $p) {
                // Gọi hàm Format duy nhất
                $rows[] = static::formatPayoutLogForSheet($p);
            }

            // Ghi dữ liệu từ A1 đến R (18 cột)
            $sheetService->updateSheet($rows, 'A1:R', $targetTab);

            \Filament\Notifications\Notification::make()
                ->title('Success')
                ->body("Data pushed to {$targetTab}")
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
            $targetTab = 'Payout_Logs';
            $rows = $service->readSheet('A2:R', $targetTab);

            if (empty($rows)) return;

            // 🟢 TỰ ĐỘNG TÌM VỊ TRÍ CỘT ĐỂ TRÁNH LỆCH KHI THÊM CỘT SAU NÀY
            $statusIdx = array_search('Status', static::$payoutLogHeaders);
            $noteIdx = array_search('Note', static::$payoutLogHeaders);

            $count = 0;
            foreach ($rows as $row) {
                if (isset($row[0]) && is_numeric($row[0])) {
                    // 🟢 FIX: Dùng find() để kích hoạt Observer cập nhật Balance ví
                    $log = PayoutLog::find($row[0]);
                    if ($log) {
                        // 🟢 GẮN CỜ ẢO TRƯỚC KHI UPDATE ĐỂ BÁO CHO OBSERVER BIẾT
                        $log->is_syncing_from_sheet = true;

                        $log->update([
                            'status' => strtolower(trim($row[$statusIdx] ?? 'pending')),         // Index 16 là Status
                            'note'   => trim($row[$noteIdx] ?? ''),                            // Index 17 là Note
                        ]);
                        $count++;
                    }
                }
            }

            \Filament\Notifications\Notification::make()
                ->title("Synced {$count} records!")
                ->success()
                ->send();
        } catch (\Exception $e) {
            \Filament\Notifications\Notification::make()
                ->title('Error: ' . $e->getMessage())
                ->danger()
                ->send();
        }
    }

    // --- 🟢 TRANG XEM CHI TIẾT (INFOLIST) ---
    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist
            ->schema([
                Infolists\Components\Section::make('Transaction Details')
                    ->schema([
                        Infolists\Components\TextEntry::make('created_at')->label('Date')
                            ->dateTime('d/m/Y H:i'),
                        Infolists\Components\TextEntry::make('status')
                            ->badge()
                            ->color(fn(string $state): string => match ($state) {
                                'pending' => 'warning',
                                'completed' => 'success',
                                'rejected' => 'danger',
                                default => 'gray',
                            })
                            ->formatStateUsing(fn($state) => ucfirst($state)),
                        Infolists\Components\TextEntry::make('account.email.email')
                            ->label('Source Account'),
                        Infolists\Components\TextEntry::make('payoutMethod.name')
                            ->label('Target Wallet')
                            // 🟢 CHỈ HIỆN NẾU LÀ PAYPAL
                            ->visible(fn($record) => $record->asset_type === 'paypal'),
                    ])->columns(2),

                Infolists\Components\Section::make('Asset & Gift Card Info')
                    ->schema([
                        Infolists\Components\TextEntry::make('asset_type')
                            ->label('Asset Type')
                            ->badge()
                            ->color('info')
                            ->formatStateUsing(fn(string $state): string => strtoupper(str_replace('_', ' ', $state))),

                        Infolists\Components\TextEntry::make('transaction_type')
                            ->label('Transaction Type')
                            ->formatStateUsing(function ($record, $state) {
                                // Tái hiện lại đúng logic mảng options như ở Form
                                $options = match ($record->asset_type) {
                                    'paypal' => [
                                        'withdrawal' => 'Withdrawal (To Wallet)',
                                        'liquidation' => 'Currency Exchange',
                                    ],
                                    'gift_card' => [
                                        'hold' => 'Hold (Keep Code)',
                                        'liquidation' => 'Currency Exchange',
                                    ],
                                    default => [],
                                };

                                // Trả về nhãn tương ứng, nếu không thấy thì trả về chính giá trị đó
                                return $options[$state] ?? ucfirst($state);
                            }),
                    ])->columns(2),

                Infolists\Components\Section::make('Gift Card Details')
                    ->schema([
                        Infolists\Components\TextEntry::make('gc_brand')
                            ->label('Brand')
                            ->weight('bold')
                            ->formatStateUsing(fn($state) => match ($state) {
                                'amazon' => 'Amazon',
                                'visa' => 'Visa/Mastercard',
                                'walmart' => 'Walmart',
                                'target' => 'Target',
                                'nike' => 'Nike',
                                'macy\'s' => 'Macy\'s',
                                'sephora' => 'Sephora',
                                'victoria\'s_secret' => 'Victoria\'s Secret',
                                'apple' => 'Apple',
                                'ebay' => 'eBay',
                                'walgreens' => 'Walgreens',
                                default => ucwords(str_replace('_', ' ', $state)),
                            }),
                        Infolists\Components\TextEntry::make('gc_code')
                            ->label('Card Number')
                            ->copyable()
                            ->copyMessage('Copied to clipboard!')
                            ->suffixAction(
                                Infolists\Components\Actions\Action::make('copyAll')
                                    ->icon('heroicon-m-clipboard-document')
                                    ->tooltip('Copy')
                                    ->color('warning')
                                    // 🟢 THAY ĐỔI Ở ĐÂY: Dùng $record thay vì $state
                                    ->action(function ($record, $livewire) {
                                        // Tính toán lại chuỗi copy từ $record
                                        $brandName = match ($record->gc_brand) {
                                            'amazon' => 'Amazon',
                                            'visa' => 'Visa/Mastercard',
                                            'walmart' => 'Walmart',
                                            'target' => 'Target',
                                            'nike' => 'Nike',
                                            'macy\'s' => 'Macy\'s',
                                            'sephora' => 'Sephora',
                                            'victoria\'s_secret' => 'Victoria\'s Secret',
                                            'apple' => 'Apple',
                                            'ebay' => 'eBay',
                                            'walgreens' => 'Walgreens',
                                            default => ucfirst(str_replace('_', ' ', $record->gc_brand)),
                                        };
                                        $amount = number_format($record->net_amount_usd, 2);
                                        $fullText = "{$brandName} eGift Card | Amount: \${$amount} | Card number: {$record->gc_code} | PIN: {$record->gc_pin} | ";

                                        // Thực hiện lệnh copy
                                        $livewire->dispatch('copy-to-clipboard', text: $fullText);

                                        \Filament\Notifications\Notification::make()
                                            ->title('Copied to clipboard!')
                                            ->success()
                                            ->send();
                                    })
                            ),
                        Infolists\Components\TextEntry::make('gc_pin')
                            ->label('PIN')
                            ->copyable(),
                    ])
                    ->columns(2)
                    ->visible(fn($record) => $record->asset_type === 'gift_card'),

                Infolists\Components\Section::make('Financial Summary')
                    ->schema([
                        Infolists\Components\TextEntry::make('amount_usd')
                            ->label('Amount (USD)')
                            ->money('usd')
                            ->numeric(2),
                        Infolists\Components\TextEntry::make('fee_usd')
                            ->label('Fee (USD)')
                            ->money('usd')
                            ->numeric(2),
                        Infolists\Components\TextEntry::make('boost_percentage')
                            ->label('Boost (%)')
                            ->numeric(2)
                            ->visible(fn($record) => $record->asset_type === 'gift_card'),
                        Infolists\Components\TextEntry::make('net_amount_usd')
                            ->label('Net Amount (USD)')
                            ->money('usd')
                            ->weight(FontWeight::Bold)
                            ->color('warning'),
                        Infolists\Components\TextEntry::make('exchange_rate')
                            ->label('Exchange Rate')
                            ->numeric(0, ',', '.')
                            ->visible(fn($record) => $record->transaction_type === 'liquidation'),

                        Infolists\Components\TextEntry::make('total_vnd')
                            ->label('VND Equivalent')
                            ->money('VND')
                            ->numeric(0, ',', '.')
                            ->weight(FontWeight::Bold)
                            ->color('success')
                            ->visible(fn($record) => $record->transaction_type === 'liquidation'),
                    ])->columns(2),

                Infolists\Components\Section::make('Note')
                    ->schema([
                        Infolists\Components\TextEntry::make('note')->label('')->placeholder('No notes provided.'),
                    ]),
            ]);
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('General Information')
                    ->schema([
                        // CHỌN USER
                        Forms\Components\Select::make('user_id')
                            ->label('User')
                            ->placeholder('Select User')
                            ->options(\App\Models\User::all()->pluck('name', 'id'))
                            ->searchable()
                            // 🟢 THIẾU DÒNG NÀY: Tự động gán ID của người đang đăng nhập
                            ->default(fn() => auth()->id())
                            // TUYỆT CHIÊU TÀNG HÌNH: Ẩn hoàn toàn khỏi mắt nhân viên
                            ->hidden(fn() => !auth()->user()?->isAdmin())
                            // BẮT BUỘC CÓ: Ép hệ thống CÓ LƯU ID này vào DB dù ô bị ẩn
                            ->dehydrated(true)
                            ->required()
                            ->live() // Kích hoạt việc load lại ô Account bên dưới
                            ->afterStateUpdated(fn($set) => $set('account_id', null)), // Reset ô Account khi đổi User

                        // CHỌN ACCOUNT (Phụ thuộc vào User ở trên)
                        Forms\Components\Select::make('account_id')
                            ->label('Source Account')
                            ->options(function (Forms\Get $get, ?\App\Models\PayoutLog $record) {
                                $userId = $get('user_id');
                                if (!$userId) return [];

                                $query = \App\Models\Account::query()->where('user_id', $userId);

                                return $query->with('email')
                                    ->get()
                                    // 🟢 CỐT LÕI NẰM Ở ĐÂY: Lọc bỏ tài khoản $0
                                    ->filter(function ($acc) use ($record) {
                                        // Giữ lại account nếu đang sửa đơn cũ
                                        if ($record && $record->account_id === $acc->id) return true;

                                        // Chỉ cho phép hiển thị các account có số dư > 0
                                        return self::getAvailableBalance($acc->id) > 0;
                                    })
                                    ->mapWithKeys(function ($acc) {
                                        $email = (string) ($acc->email?->email ?? 'No Email');
                                        $platform = (string) strtoupper($acc->platform ?? 'N/A');

                                        // Hiển thị thêm số dư bên cạnh tên để nhân viên tự tin chọn
                                        $balance = number_format(self::getAvailableBalance($acc->id), 2);

                                        return [$acc->id => "{$email} - {$platform} (\${$balance})"];
                                    })
                                    ->toArray();
                            })
                            ->searchable()
                            ->preload()
                            ->required()
                            ->live()
                            ->disabled(fn(Forms\Get $get) => !$get('user_id'))
                            ->placeholder(fn(Forms\Get $get) => !$get('user_id') ? 'Please select a User first...' : 'Select an Account.')
                            ->afterStateUpdated(function ($state, $set, $get) {
                                $set('gc_brand', null);
                                if (!$state) {
                                    $set('amount_usd', 0);
                                    return;
                                }
                                $set('amount_usd', round(self::getAvailableBalance($state), 2));
                                static::calculateNet($set, $get);
                            }),

                        // CHỌN ASSET
                        // 1. Ô CHỌN ASSET TYPE (Cực kỳ quan trọng: Phải có ->live())
                        // Khi Sếp thêm ->live(), hệ thống sẽ "lắng nghe" sự thay đổi ở ô này 
                        // để lập tức biến đổi các ô bên dưới mà không cần load lại trang.
                        Forms\Components\Select::make('asset_type') // (Hoặc tên cột Sếp đang dùng)
                            ->label('Asset Type')
                            ->options([
                                'paypal' => 'PayPal',
                                'gift_card' => 'Gift Card',
                            ])
                            ->required()
                            ->live()
                            ->afterStateUpdated(function ($state, $set) {
                                // Nếu chọn PayPal, xóa sạch dấu vết của Gift Card
                                if ($state === 'paypal') {
                                    $set('gc_brand', null);
                                    $set('gc_code', null);
                                    $set('gc_pin', null);
                                    $set('boost_percentage', 0); // Reset luôn cả phần thưởng Gift Card
                                }
                                // Nếu chọn Gift Card, xóa ví mục tiêu
                                if ($state === 'gift_card') {
                                    $set('payout_method_id', null);
                                }
                            }),

                        Forms\Components\Select::make('payout_method_id')
                            ->label('Target Wallet')
                            ->options(PayoutMethod::pluck('name', 'id')) // Chỉ cần 1 dòng này
                            // 🟢 Ẩn Ví mục tiêu đối với Staff khi chọn PayPal (Gift Card vốn dĩ đã ẩn sẵn)
                            ->hidden(
                                fn(Get $get) =>
                                $get('asset_type') === 'gift_card' ||
                                    (!auth()->user()?->isAdmin() && $get('asset_type') === 'paypal')
                            )
                            // Mờ đi khi là Gift Card
                            ->disabled(fn($get) => $get('asset_type') === 'gift_card')

                            // Không lưu vào DB nếu là Gift Card (để đảm bảo cột này null trong database)
                            ->dehydrated(fn($get) => $get('asset_type') !== 'gift_card')
                            //->visible(fn($get) => $get('asset_type') === 'paypal')
                            ->required(fn($get) => $get('asset_type') === 'paypal')
                            // Chỉ bắt buộc với Admin khi chọn PayPal
                            ->required(fn(Get $get) => auth()->user()?->isAdmin() && $get('asset_type') === 'paypal'),

                        // CHỌN BRAND
                        Forms\Components\Select::make('gc_brand')
                            ->label('Brand')
                            ->placeholder('Select Brand')
                            ->searchable()
                            ->visible(fn($get) => $get('asset_type') === 'gift_card')
                            ->required(fn($get) => $get('asset_type') === 'gift_card')
                            ->dehydrateStateUsing(fn($state) => str_replace('\\', '', $state))
                            // 1. HIỂN THỊ NHÃN ĐẦY ĐỦ THÔNG TIN
                            ->options(function (Forms\Get $get) {
                                $accId = $get('account_id');
                                if (!$accId) return [];

                                $account = \App\Models\Account::find($accId);
                                if (!$account) return [];

                                return \App\Models\Brand::where('platform', $account->platform)
                                    ->get()
                                    ->mapWithKeys(function ($brand) {
                                        $boost = $brand->boost_percentage ?? 0;
                                        $limit = $brand->maximum_limit > 0 ? "\${$brand->maximum_limit}" : "No Limit";

                                        // Format: Gap - Boost: 3% - Maximum: $250
                                        $label = "{$brand->name} - Boost: {$boost}% - Maximum: {$limit}";

                                        return [$brand->slug => $label];
                                    })
                                    ->toArray();
                            })
                            // 2. LÀM MỜ NẾU VƯỢT GIỚI HẠN
                            ->disableOptionWhen(function (string $value, Forms\Get $get) {
                                $accId = $get('account_id');
                                if (!$accId) return false;

                                // 🟢 FIX DRY
                                $availableBalance = self::getAvailableBalance($accId);
                                $brand = \App\Models\Brand::where('slug', $value)->first();

                                return ($brand && $brand->maximum_limit > 0 && $availableBalance > $brand->maximum_limit);
                            })

                            // 3. THÊM BOOST VÀ MAXIMUM VÀO MODAL TẠO NHANH (+)
                            ->createOptionForm([
                                Forms\Components\Grid::make(2)
                                    ->schema([
                                        Forms\Components\TextInput::make('name')
                                            ->label('Brand Name')
                                            ->required()
                                            ->live(onBlur: true)
                                            ->afterStateUpdated(fn($state, $set) => $set('slug', \Str::slug($state))),

                                        Forms\Components\TextInput::make('slug')
                                            ->required()
                                            ->unique('brands', 'slug'),

                                        Forms\Components\TextInput::make('boost_percentage')
                                            ->label('Boost (%)')
                                            ->numeric()
                                            ->default(0)
                                            ->suffix('%'),

                                        Forms\Components\TextInput::make('maximum_limit')
                                            ->label('Maximum Limit ($)')
                                            ->numeric()
                                            ->prefix('$')
                                            ->placeholder('0 = No Limit'),
                                    ]),
                            ])
                            ->createOptionUsing(function (array $data, Forms\Get $get) {
                                $account = \App\Models\Account::find($get('account_id'));
                                if (!$account) throw new \Exception("Please select an account first.");

                                $data['platform'] = $account->platform;
                                $brand = \App\Models\Brand::create($data);
                                return $brand->slug;
                            })
                            ->afterStateUpdated(function ($state, $set, $get) {
                                if (!$state) return;
                                $brand = \App\Models\Brand::where('slug', $state)->first();
                                if ($brand) {
                                    $set('boost_percentage', $brand->boost_percentage ?? 0);
                                    static::calculateNet($set, $get);
                                }
                            })
                            ->rules([
                                fn(Forms\Get $get): \Closure => function (string $attribute, $value, \Closure $fail) use ($get) {
                                    $brand = \App\Models\Brand::where('slug', $value)->first();
                                    if (!$brand || $brand->maximum_limit <= 0) return;

                                    // 🟢 FIX DRY
                                    $balance = self::getAvailableBalance($get('account_id'));

                                    if ($balance > $brand->maximum_limit) {
                                        $fail("This Brand cannot be selected because the balance (\$ {$balance}) exceeds the allowed limit (\$ {$brand->maximum_limit}).");
                                    }
                                },
                            ]),

                        Forms\Components\TextInput::make('gc_code')
                            ->label('Card Number')
                            ->placeholder('XXXX-XXXX-XXXX')
                            ->visible(fn($get) => $get('asset_type') === 'gift_card'),

                        Forms\Components\TextInput::make('gc_pin')
                            ->label('PIN')
                            ->placeholder('1234')
                            ->visible(fn($get) => $get('asset_type') === 'gift_card'),

                        Forms\Components\Select::make('transaction_type')
                            ->label('Transaction Type')
                            ->options(fn($get) => match ($get('asset_type')) {
                                'paypal' => [
                                    'withdrawal' => 'Withdrawal (To Wallet)',       // Bản chất là: withdrawal
                                    'liquidation' => 'Currency Exchange',   // Bản chất là: liquidation
                                ],
                                'gift_card' => [
                                    'hold' => 'Hold (Keep Code)',              // Bản chất là: withdrawal
                                    'liquidation' => 'Currency Exchange',   // Bản chất là: liquidation
                                ],
                                default => [],
                            })
                            // 🟢 Ẩn khỏi Staff khi chọn PayPal
                            ->hidden(fn(Get $get) => !auth()->user()?->isAdmin() && $get('asset_type') === 'paypal')
                            ->default('withdrawal') // Mặc định rút tiền để DB không bị lỗi null
                            ->dehydrated(true)
                            ->required(fn(Get $get) => auth()->user()?->isAdmin() || $get('asset_type') !== 'paypal')
                            ->live(),

                        Forms\Components\Select::make('status')
                            ->options([
                                'pending' => 'Pending',
                                'completed' => 'Completed',
                                'rejected' => 'Rejected',
                            ])
                            ->default('pending')
                            // 🟢 Tàng hình hoàn toàn với Staff, tự động lưu Pending
                            ->hidden(fn() => !auth()->user()?->isAdmin())
                            ->dehydrated(true)
                            ->required(),

                        // 🟢 THÊM Ô NOTE ĐỂ DÁN LINK CLAIM PAYPAL
                        Forms\Components\Textarea::make('note')
                            ->label(fn(Get $get) => $get('asset_type') === 'paypal' ? 'Link Claim PayPal' : 'Note')
                            ->helperText(fn(Get $get) => $get('asset_type') === 'paypal' ? '💡 If you are using PayPal VN, please paste the Claim Link here. PayPal US users can skip this step.' : '')
                            // Sếp gắn chính xác dòng của Sếp vào đây:
                            ->visible(fn(Get $get) => $get('asset_type') === 'paypal')
                            ->columnSpanFull(),

                    ])->columns(2),

                Forms\Components\Section::make('Financials')
                    ->schema([
                        Forms\Components\TextInput::make('amount_usd')
                            ->label('Amount (USD)')
                            ->numeric()
                            ->prefix('$')
                            ->required()
                            ->live(onBlur: true) // Chỉ tính toán khi nhập xong để tránh giật lag
                            // Hiển thị số dư khả dụng dưới dạng chữ nhỏ màu xanh
                            ->hint(function ($get) {
                                $accountId = $get('account_id');
                                if (!$accountId) return null;

                                $lifetimeTotal = \App\Models\RebateTracker::where('account_id', $accountId)
                                    ->whereIn('status', ['pending', 'clicked', 'confirmed'])
                                    ->sum('rebate_amount') ?? 0;

                                $pending = \App\Models\RebateTracker::where('account_id', $accountId)
                                    ->whereIn('status', ['pending', 'clicked'])
                                    ->sum('rebate_amount') ?? 0;

                                // 🟢 FIX DRY: Dùng luôn hàm Helper cho số Confirmed
                                $availableConfirmed = self::getAvailableBalance($accountId);

                                $totalStr = number_format($lifetimeTotal, 2);
                                $pendingStr = number_format($pending, 2);
                                $confirmedStr = number_format($availableConfirmed, 2);

                                return "Total: \${$totalStr} | Pending: \${$pendingStr} | Confirmed: \${$confirmedStr}";
                            })
                            ->hintColor('primary') // Màu xanh thương hiệu cho nổi bật
                            ->afterStateUpdated(fn($set, $get) => self::calculateNet($set, $get)),

                        Forms\Components\TextInput::make('fee_usd')
                            ->label('Fee (USD)')
                            ->numeric()
                            ->prefix('$')
                            ->default(0)
                            ->live(onBlur: true) // Chỉ tính toán khi nhập xong để tránh giật lag
                            ->afterStateUpdated(fn($set, $get) => self::calculateNet($set, $get)),

                        // Boost chỉ hiện khi là Gift Card
                        Forms\Components\TextInput::make('boost_percentage')
                            ->label('Boost (%)')
                            ->numeric()
                            ->default(0)
                            ->visible(fn($get) => $get('asset_type') === 'gift_card')
                            ->live(onBlur: true) // Chỉ tính toán khi nhập xong để tránh giật lag
                            ->afterStateUpdated(fn($set, $get) => self::calculateNet($set, $get)),

                        Forms\Components\TextInput::make('net_amount_usd')
                            ->label('Net Amount (USD)')
                            ->numeric()
                            ->prefix('$')
                            ->readOnly()
                            ->helperText('Final amount to be added to wallet')
                            ->extraInputAttributes(['class' => 'font-bold text-success-600']),

                        // --- KHU VỰC LIQUIDATION (ĐỔI TIỀN) ---
                        Forms\Components\TextInput::make('exchange_rate')
                            ->label('Exchange Rate')
                            ->prefix('1$ =')
                            ->placeholder('Eg: 20000')
                            //->mask('99.999') // 🟢 Tự động thêm dấu chấm vào vị trí thứ 3
                            ->suffix('VNĐ/$')
                            // CHỈ HIỆN KHI LÀ LIQUIDATION
                            ->visible(fn($get) => $get('transaction_type') === 'liquidation')
                            ->required(fn($get) => $get('transaction_type') === 'liquidation')
                            ->live(onBlur: true) // Chỉ tính toán khi nhập xong để tránh giật lag
                            ->afterStateUpdated(fn($set, $get) => self::calculateVnd($set, $get))
                            ->dehydrateStateUsing(fn($state) => $state ? str_replace('.', '', $state) : null),

                        Forms\Components\TextInput::make('total_vnd')
                            ->label('Total VND')
                            ->prefix('₫')
                            ->readOnly()
                            // CHỈ HIỆN KHI LÀ LIQUIDATION
                            ->visible(fn($get) => $get('transaction_type') === 'liquidation')
                            ->formatStateUsing(fn($state) => $state ? number_format((float)$state, 0, ',', '.') : null)
                            ->extraInputAttributes(['class' => 'font-bold text-primary-600'])
                            ->dehydrateStateUsing(fn($state) => $state ? str_replace('.', '', $state) : 0),
                    ])->columns(2),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            // 🟢 PHẢI ĐẶT Ở ĐÂY: Vô hiệu hóa click vào cả hàng để không lỗi
            // Trong phần Table configuration
            ->recordAction(null) // Tắt click action
            ->recordUrl(null)    // Tắt URL navigation
            // 🟢 BƯỚC 2: Sửa từ code của bạn (Dùng group_id để không bao giờ bị lỗi NULL)
            /*->groups([
                Tables\Grouping\Group::make('group_id')
                    ->label('Original Transaction')
                    ->getTitleFromRecordUsing(fn($record) => $record->parent_id ? "Original ID #" . $record->parent_id : "Original ID #" . $record->id)
                    ->collapsible(),
            ])
            ->defaultGroup('group_id') */

            // 🟢 BƯỚC 3: Hiệu ứng thụt lề và đổi màu cho dòng con (Liquidation)
            // Dòng con: Có vạch xanh, thụt lề nhẹ
            // Dòng cha: Trắng tinh, chữ đậm
            ->recordClasses(fn($record) => $record->parent_id ? 'bg-gray-50/50 border-l-4 border-primary-500 ml-4' : 'bg-white font-medium')
            ->columns([
                // Date - Platform
                Tables\Columns\TextColumn::make('created_at')
                    ->label('Date')
                    ->dateTime('d/m/Y H:i')
                    ->alignment(Alignment::Center),

                // Account Email - Platform
                Tables\Columns\TextColumn::make('account_id')
                    ->label('Account Email')
                    ->alignment(Alignment::Center)
                    ->copyable()
                    ->copyMessage('Copied to clipboard!')
                    ->wrap()
                    ->width('200px')
                    ->html() // Cho phép xuống dòng bằng thẻ <br>
                    ->formatStateUsing(function ($record) {
                        $account = $record->account;
                        if (!$account) return 'N/A';

                        // Lấy email từ bảng Email liên kết với Account
                        $email = $account->email?->email ?? 'N/A';

                        // Lấy platform trực tiếp từ bảng Account (Cột platform có sẵn trong bảng accounts)
                        $platform = strtoupper($account->platform ?? 'N/A');

                        return "
                                <div style='display: inline-block; text-align: left; line-height: 1.7;'>
                                    <div style='margin-bottom: 4px;'>
                                        <span style='color: #111827;'>$email</span>
                                        <span style='color: #6b7280; display: inline-block;'>Platform:</span> 
                                        <span style='color: #4b5563;'>$platform</span>
                                    </div>
                                </div>
                            ";
                    })
                    // Tìm kiếm xuyên bảng: tìm theo email hoặc tìm theo platform trong bảng accounts
                    ->searchable(query: function ($query, string $search) {
                        $query->whereHas('account', function ($q) use ($search) {
                            $q->where('platform', 'like', "%{$search}%")
                                ->orWhereHas('email', function ($q2) use ($search) {
                                    $q2->where('email', 'like', "%{$search}%");
                                });
                        });
                    }),

                // Hiển thị Wallet PayPal hoặc Info Gift Card    
                Tables\Columns\TextColumn::make('asset_info')
                    ->label('Asset Details')
                    ->alignment(Alignment::Start)
                    ->copyable()
                    ->copyMessage('Copied to clipboard!')
                    ->wrap()
                    ->width('250px')
                    ->html() // Cho phép xuống dòng bằng thẻ <br>
                    // 🟢 PHẢI CÓ: Ngăn sự kiện click bị trôi ra ngoài hàng (Row)
                    // 🟢 FIX 1: Thêm attribute để wrapper bao quanh toàn bộ cell
                    ->extraAttributes([
                        'class' => 'cursor-default relative',
                        // 🟢 Dùng onclick để chặn sự kiện click lan ra hàng (Row)
                        'onclick' => 'event.stopPropagation();',
                    ])
                    ->copyableState(function ($record) {
                        $data = $record->parent_id ? $record->parent : $record;
                        if (!$data) return '';
                        $prettyBrand = ucwords(str_replace('_', ' ', $data->gc_brand ?? 'N/A'));
                        $amount = number_format($record->net_amount_usd, 2);
                        // Trả về text thuần, không xuống dòng để copy chuẩn nhất
                        return "Brand: {$prettyBrand} | Amount: \${$amount} | Card number: {$data->gc_code} | PIN: {$data->gc_pin}";
                    })
                    ->state(function ($record) {
                        if ($record->asset_type === 'paypal') {
                            $walletName = $record->payoutMethod?->name ?? 'N/A';

                            return "<div style='display: inline-block; text-align: left; line-height: 1.7;'>
                                        <div style='margin-bottom: 4px;'>
                                            <span style='color: #6b7280; display: inline-block;'>PayPal Withdrawal:</span> 
                                            <strong style='color: #111827;'>$walletName</strong>
                                        </div>
                                    <div>";
                        }

                        // Định dạng cho Gift Card theo ý bạn
                        $assetType = $record->asset_type ? ucwords(str_replace('_', ' ', $record->asset_type)) : 'N/A';
                        $brand = $record->gc_brand ? ucfirst(str_replace('_', ' ', $record->gc_brand)) : 'N/A';
                        $code = $record->gc_code ?? '---';
                        $pin = $record->gc_pin ?? '---';
                        return "
                                <div style='display: inline-block; text-align: left; line-height: 1.7;'>
                                <div style='margin-bottom: 4px;'>
                                        <span style='color: #6b7280; display: inline-block;'>Asset Type:</span> 
                                        <strong style='color: #111827;'>{$assetType}</strong>
                                    </div>
                                    <div style='margin-bottom: 4px;'>
                                        <span style='color: #6b7280; display: inline-block;'>Brand:</span> 
                                        <span style='color: #111827;'>{$brand}</span>
                                    </div>
                                    <div style='margin-bottom: 4px; white-space: nowrap;'>
                                        <span style='color: #6b7280; display: inline-block;'>Card number:</span> 
                                        <code style='background: #f3f4f6; padding: 2px 6px; border-radius: 4px;'>{$code}</code>
                                    </div>
                                    <div>
                                        <span style='color: #6b7280; display: inline-block;'>PIN:</span> 
                                        <code style='background: #f3f4f6; padding: 2px 6px; border-radius: 4px;'>{$pin}</code>
                                    </div>
                                </div>
                            ";
                    })
                    // 🟢 HIỆN ICON: Chỉ hiện nếu là Gift Card
                    ->icon(fn($record) => $record->asset_type === 'gift_card' ? 'heroicon-m-clipboard-document' : null)
                    // 🟢 MÀU ICON: Chỉ icon màu vàng, chữ vẫn giữ màu mặc định
                    ->iconColor('warning')
                    // 🟢 ĐƯA ICON SANG BÊN PHẢI
                    ->iconPosition('after')
                    // 🟢 DESCRIPTION: Chỉ hiện dòng hướng dẫn cho Gift Card
                    //->description(fn($record) => $record->asset_type === 'gift_card' ? 'Click to copy full info' : null)
                    ->searchable('gc_brand'),

                Tables\Columns\TextColumn::make('transaction_type')
                    ->label('Transaction Type')
                    ->alignment(Alignment::Center)
                    ->formatStateUsing(fn(string $state) => match ($state) {
                        'withdrawal' => 'Withdrawal (To Wallet)',       // Bản chất là: withdrawal
                        'hold' => 'Hold (Keep Code)', // Bản chất là: withdrawal
                        'liquidation' => 'Currency Exchange',   // Bản chất là: liquidation
                        default => ucfirst($state),
                    })
                    ->html()
                    // --- Description: Luôn hiện chữ Sell to VND cho dòng Withdrawal ---
                    ->description(function ($record): ?\Illuminate\Support\HtmlString {
                        // 🟢 FIX N+1: Dùng children_count thay vì children()->count()
                        if (in_array($record->transaction_type, ['withdrawal', 'hold']) && $record->children_count === 0) {
                            return new \Illuminate\Support\HtmlString(
                                '<span style="color: #FF9F40; font-weight: bold; cursor: pointer; display: block; margin-top: 4px;">$ Exchange to VND</span>'
                            );
                        }

                        // Nếu đã bán rồi, có thể hiện nhãn "Đã thanh khoản" màu xám nhẹ cho chuyên nghiệp
                        // 🟢 FIX N+1
                        if ($record->children_count > 0) {
                            return new \Illuminate\Support\HtmlString(
                                '<span style="color: #94a3b8; font-size: 11px;">(Exchanged!)</span>'
                            );
                        }
                        return null;
                    })
                    ->extraAttributes(function ($record) {
                        // 🟢 FIX N+1: Chặn click nếu đã bán rồi
                        if (in_array($record->transaction_type, ['withdrawal', 'hold']) && $record->children_count === 0) {
                            return [
                                'class' => 'cursor-pointer transition hover:opacity-70',
                                'wire:click.stop' => "mountTableAction('currency_exchange', '{$record->id}')",
                            ];
                        }
                        return [];
                    }),
                Tables\Columns\TextColumn::make('net_amount_usd')
                    ->label('Net USD')
                    ->money('usd')
                    ->numeric(2, '.', ',')
                    ->prefix('$')
                    ->color('warning')
                    ->weight(\Filament\Support\Enums\FontWeight::Bold)
                    ->alignment(Alignment::Right)
                    ->summarize(
                        Tables\Columns\Summarizers\Sum::make()
                            ->label('')
                            ->prefix('$')
                            ->numeric(2, '.', ',')
                            // 🟢 CHỈ CỘNG DÒNG GỐC (Tránh x2 số tiền)
                            ->query(fn($query) => $query->whereNull('parent_id'))
                    ),
                Tables\Columns\TextColumn::make('total_vnd')
                    ->label('Total VND')
                    ->placeholder('N/A')
                    ->numeric(0, ',', '.')
                    ->prefix('₫')
                    ->alignment(Alignment::Right)
                    // 🟢 TỔNG KẾT VND
                    ->summarize(
                        Tables\Columns\Summarizers\Sum::make()
                            ->label('')
                            ->prefix('₫')
                            ->numeric(0, ',', '.')
                            ->query(fn($query) => $query->where('transaction_type', 'liquidation'))
                    ),
                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->alignment(Alignment::Center)
                    ->color(fn(string $state): string => match ($state) {
                        'pending' => 'warning',
                        'completed' => 'success',
                        'rejected' => 'danger',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn(string $state): string => ucfirst($state)),
            ])
            ->filters([
                // Only shows platforms that currently have records in PayoutLog
                Tables\Filters\SelectFilter::make('platform')
                    ->label('Platform')
                    ->options(function () {
                        $platforms = \App\Models\Account::query()
                            ->whereIn('id', \App\Models\PayoutLog::distinct()->pluck('account_id'))
                            ->pluck('platform', 'platform')
                            ->mapWithKeys(fn($state) => [
                                $state => self::$platform[$state] ?? ucwords(
                                    // 1. Insert space before capital letters (JoinHoney -> Join Honey)
                                    // 2. Replace underscores/hyphens with spaces (join_honey -> join honey)
                                    preg_replace('/(?<!^)[A-Z]/', ' $0', str_replace(['_', '-'], ' ', $state))
                                )
                            ])
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
                    ->query(function (Builder $query, array $data): Builder {
                        return $query->when(
                            $data['value'],
                            fn(Builder $query, $value) => $query->whereHas('account', fn($q) => $q->where('platform', $value))
                        );
                    }),

                // DYNAMIC USER (MANAGER) FILTER
                // Only shows Users who are actually linked to the logs in the list
                Tables\Filters\SelectFilter::make('user_id')
                    ->label('User')
                    ->visible(fn() => auth()->user()?->isAdmin()) // 🟢 ẨN KHỎI NHÂN VIÊN
                    ->options(
                        fn() => \App\Models\User::query()
                            ->whereIn('id', \App\Models\PayoutLog::distinct()->pluck('user_id'))
                            ->pluck('name', 'id')
                            ->toArray()
                    )
                    ->searchable(),

                // 1. LỌC THEO TRẠNG THÁI (STATUS)
                Tables\Filters\SelectFilter::make('status')
                    ->label('Filter by Status')
                    ->options([
                        'pending' => 'Pending',
                        'completed' => 'Completed',
                        'rejected' => 'Rejected',
                    ]),

                // 2. LỌC THEO THỜI GIAN (TỪ NGÀY - ĐẾN NGÀY)
                Tables\Filters\Filter::make('created_at')
                    ->form([
                        Forms\Components\TextInput::make('created_from')
                            ->label('From Date')
                            ->placeholder('dd/mm/yyyy')
                            //->displayFormat('d/m/Y') // Định dạng hiển thị khi nhập
                            //->format('Y-m-d') // Định dạng chuẩn để lưu vào MySQL
                            //->native(false) // Dùng giao diện hiện đại của Filament
                            ->nullable() // Cho phép để trống
                            ->default(null) // Đảm bảo không tự động lấy ngày hiện tại
                            ->mask('99/99/9999') // Tạo khuôn dd/mm/yyyy khi gõ
                            ->rules(['date_format:d/m/Y'])
                            //->dehydrated(true), // Đảm bảo trường này được gửi về backend
                            //->live(),  // Đồng bộ dữ liệu ngay lập tức
                            ->dehydrateStateUsing(function ($state) {
                                if (blank($state)) return null;
                                try {
                                    // Dịch từ chuẩn VN (d/m/Y) sang chuẩn Quốc tế (Y-m-d) để MySQL hiểu
                                    return \Carbon\Carbon::createFromFormat('d/m/Y', $state)->format('Y-m-d');
                                } catch (\Exception $e) {
                                    return null;
                                }
                            }),

                        Forms\Components\TextInput::make('created_until')
                            ->label('To Date')
                            ->placeholder('dd/mm/yyyy')
                            //->displayFormat('d/m/Y') // Định dạng hiển thị khi nhập
                            //->format('Y-m-d') // Định dạng chuẩn để lưu vào MySQL
                            //->native(false) // Dùng giao diện hiện đại của Filament
                            ->nullable() // Cho phép để trống
                            ->default(null) // Đảm bảo không tự động lấy ngày hiện tại
                            ->mask('99/99/9999') // Tạo khuôn dd/mm/yyyy khi gõ
                            ->rules(['date_format:d/m/Y'])
                            //->dehydrated(true), // Đảm bảo trường này được gửi về backend
                            //->live(),  // Đồng bộ dữ liệu ngay lập tức
                            ->dehydrateStateUsing(function ($state) {
                                if (blank($state)) return null;
                                try {
                                    // Dịch từ chuẩn VN (d/m/Y) sang chuẩn Quốc tế (Y-m-d) để MySQL hiểu
                                    return \Carbon\Carbon::createFromFormat('d/m/Y', $state)->format('Y-m-d');
                                } catch (\Exception $e) {
                                    return null;
                                }
                            }),
                    ])
                    ->columns(2)     // QUAN TRỌNG: Dàn ngang 2 ô Date bên trong
                    ->columnSpan(2)  // QUAN TRỌNG: Khối thời gian này chiếm 2 cột của Layout tổng
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when(
                                $data['created_from'],
                                fn(Builder $query, $date): Builder => $query->whereDate('created_at', '>=', $date),
                            )
                            ->when(
                                $data['created_until'],
                                fn(Builder $query, $date): Builder => $query->whereDate('created_at', '<=', $date),
                            );
                    }),

                Tables\Filters\TrashedFilter::make(), // 🟢 BẬT TÍNH NĂNG THÙNG RÁC
            ])
            // THÊM DÒNG NÀY ĐỂ ĐƯA FILTER RA NGOÀI:
            ->filtersLayout(\Filament\Tables\Enums\FiltersLayout::AboveContent)
            // 🟢 THAY ĐỔI DÒNG NÀY: Admin hiện 3 cột, Staff hiện 5 cột
            ->filtersFormColumns(auth()->user()?->isAdmin() ? 3 : 5) // QUAN TRỌNG: Tổng Layout là 3 cột (Status [1] + Date [2] = 3)
            ->actions([
                //Nút Exchange to VND ở cột Transaction Type
                Tables\Actions\Action::make('currency_exchange')
                    ->label('Currency Exchange (Sell to VND)')
                    ->extraAttributes([
                        'style' => 'display: none !important;',
                    ])
                    ->icon('heroicon-m-banknotes')
                    ->color('success')
                    ->tooltip('Exchange to VND')
                    ->modalHeading('Currency Exchange (Sell to VND)')
                    ->modalWidth('md')
                    ->mountUsing(fn(Forms\ComponentContainer $form, $record) => $form->fill([
                        'net_amount_usd' => $record->net_amount_usd, // Lấy sẵn số tiền từ dòng gốc
                        'exchange_rate' => 20000,
                        'total_vnd' => $record->net_amount_usd * 20000,
                    ]))

                    // 🟢 Bỏ check diffInDays, chỉ cần là withdrawal, hold là cho hiện
                    ->visible(fn($record) => in_array($record->transaction_type, ['withdrawal', 'hold']))
                    ->form([
                        Forms\Components\TextInput::make('net_amount_usd')
                            ->label('Net USD')
                            ->numeric()
                            ->prefix('$')
                            ->default(fn($record) => $record->amount_usd)
                            ->required()
                            ->live()
                            // Khi thay đổi USD, tính lại VND
                            ->afterStateUpdated(fn($set, $get) => self::calculateVnd($set, $get)),

                        Forms\Components\TextInput::make('exchange_rate')
                            ->label('Exchange rate')
                            ->prefix('1$ =')
                            ->placeholder('Eg: 20000')
                            ->numeric()
                            //->mask('99.999') // Dấu chấm ở đây chỉ là hiển thị
                            ->suffix('VNĐ/$')
                            ->required()
                            ->live() // Bỏ onBlur để tính toán ngay khi đang gõ
                            ->afterStateUpdated(fn($set, $get) => self::calculateVnd($set, $get))
                            // Hiện gợi ý bên dưới để check lại số nghìn/triệu
                            ->helperText(fn($state) => $state ? 'Typing: ' . number_format((float)$state, 0, ',', '.') . ' VNĐ' : null),

                        Forms\Components\TextInput::make('total_vnd')
                            ->label('Total VND')
                            ->prefix('₫')
                            ->readOnly()
                            // 🟢 TUYỆT CHIÊU: Tự động thêm dấu chấm khi hiển thị
                            ->formatStateUsing(fn($state) => $state ? number_format((float)$state, 0, ',', '.') : '0')
                            ->extraInputAttributes(['class' => 'font-bold text-success-600', 'style' => 'font-size: 1.2rem;'])
                            // 🟢 QUAN TRỌNG: Trước khi lưu vào DB, xóa hết dấu chấm để thành số thuần túy
                            ->dehydrateStateUsing(fn($state) => (float)str_replace('.', '', $state ?? '0')),
                    ])
                    ->action(function ($record, array $data) {
                        // 🟢 LÀM SẠCH DỮ LIỆU TRƯỚC KHI LƯU
                        $cleanRate = (float) str_replace(['.', ','], '', $data['exchange_rate']);
                        $cleanVnd = (float) str_replace(['.', ','], '', $data['total_vnd']);
                        $usdAmount = (float) $data['net_amount_usd'];

                        \App\Models\PayoutLog::create([
                            'parent_id' => $record->id,
                            'user_id' => auth()->id(),
                            'account_id' => $record->account_id,
                            'payout_method_id' => $record->payout_method_id,
                            'transaction_type' => 'liquidation',
                            'asset_type' => $record->asset_type,

                            // 🟢 THÊM: Copy thông tin Gift Card sang dòng con
                            'gc_brand' => $record->gc_brand,
                            'gc_code' => $record->gc_code,
                            'gc_pin' => $record->gc_pin,

                            // 🟢 ĐIỀN CẢ 2 ĐỂ TRÁNH LỖI SQL (Mặc định Gross = Net khi bán)
                            'amount_usd' => $usdAmount,
                            'net_amount_usd' => $usdAmount,

                            'exchange_rate' => $cleanRate,
                            'total_vnd' => $cleanVnd,
                            'status' => 'completed',
                            'note' => "Liquidity from ID #" . $record->id,
                        ]);

                        \Filament\Notifications\Notification::make()
                            ->title('Exchange successful!')
                            ->success()
                            ->send();
                    }),

                // Nút Xem chi tiết (Hình con mắt) hiện ra bên ngoài
                Tables\Actions\ViewAction::make()
                    ->label('') // Để trống nhãn để chỉ hiện icon cho gọn
                    ->modalHeading('Payout Log Details') // TIÊU ĐỀ CỦA MODAL
                    ->tooltip('Detail') // Hiện ghi chú khi di chuột vào
                    ->icon('heroicon-o-eye')
                    ->color('gray'), // Màu xám nhẹ nhàng, không lấn át nút cam,
                Tables\Actions\ActionGroup::make([
                    Tables\Actions\RestoreAction::make(), // 🟢 Nút khôi phục dòng bị xóa
                    Tables\Actions\EditAction::make(),
                    Tables\Actions\DeleteAction::make(),
                ]),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    // 🟢 NÚT EXPORT PAYOUT LOGS
                    Tables\Actions\BulkAction::make('export_payout_logs_to_sheet')
                        ->label('Export to Google Sheet')
                        ->icon('heroicon-o-table-cells')
                        ->color('success')
                        ->action(function (\Illuminate\Database\Eloquent\Collection $records) {
                            try {
                                $sheetService = app(\App\Services\GoogleSheetService::class);
                                $targetTab = 'Payout_Logs'; // Tên Tab riêng cho Logs

                                // 1. Đảm bảo Tab tồn tại
                                $sheetService->createSheetIfNotExist($targetTab);

                                // 2. Chuẩn bị dữ liệu
                                $rows = $records->map(fn($record) => static::formatPayoutLogForSheet($record))->toArray();

                                // 3. Upsert lên Sheet (Sử dụng logic Batch Update đã tối ưu)
                                $sheetService->upsertRows($rows, $targetTab, static::$payoutLogHeaders);

                                // 4. Định dạng cột (Cột số tiền và tỷ giá thường dài nên cần Clip)
                                $sheetService->formatColumnsAsClip($targetTab, 2, 3);  // Cột Date
                                $sheetService->formatColumnsAsClip($targetTab, 16, 17); // Cột Note

                                \Filament\Notifications\Notification::make()
                                    ->title('Sync Logs Success!')
                                    ->body('Synced ' . count($records) . ' transaction(s) to Google Sheets.')
                                    ->success()
                                    ->send();
                            } catch (\Exception $e) {
                                \Filament\Notifications\Notification::make()
                                    ->title('Sync Error!')
                                    ->body($e->getMessage())
                                    ->danger()
                                    ->send();
                            }
                        })
                        ->deselectRecordsAfterCompletion(),
                    Tables\Actions\BulkAction::make('mark_as_completed')
                        ->label('Mark as Completed')
                        ->icon('heroicon-o-check-circle')
                        ->color('success')
                        ->requiresConfirmation()
                        ->action(function (\Illuminate\Database\Eloquent\Collection $records) {
                            $records->each->update(['status' => 'completed']);

                            // Gợi ý: Gọi Job để sync tất cả lên Sheet sau khi update xong
                            foreach ($records as $record) {
                                \App\Jobs\SyncGoogleSheetJob::dispatch($record->id, get_class($record));
                            }
                        }),

                    Tables\Actions\RestoreBulkAction::make(),     // 🟢 Khôi phục nhiều dòng
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);;
    }

    // 🟢 HÀM HELPER: Tính số dư khả dụng duy nhất tại đây (DRY)
    public static function getAvailableBalance($accountId): float
    {
        if (!$accountId) return 0.0;

        $confirmed = \App\Models\RebateTracker::where('account_id', $accountId)
            ->whereIn('status', ['confirmed'])
            ->sum('rebate_amount') ?? 0;

        $paid = \App\Models\PayoutLog::where('account_id', $accountId)
            ->whereIn('transaction_type', ['withdrawal', 'hold'])
            ->where('status', 'completed')
            ->sum('amount_usd') ?? 0;

        return max(0, $confirmed - $paid);
    }

    // Cập nhật hàm tính toán USD
    public static function calculateNet($set, $get)
    {
        $amount = (float) ($get('amount_usd') ?? 0);
        $fee = (float) ($get('fee_usd') ?? 0);
        $boost = (float) ($get('boost_percentage') ?? 0);

        // Net = (Gốc - Phí) + (Gốc * %Boost)
        $net = ($amount - $fee) + ($amount * ($boost / 100));

        // Làm tròn 2 chữ số thập phân chuẩn USD
        $set('net_amount_usd', round($net, 2));

        // Gọi hàm tính VND để đồng bộ con số ngay lập tức
        self::calculateVnd($set, $get);
    }

    // Cập nhật hàm tính toán VND
    public static function calculateVnd($set, $get)
    {
        $net = (float) ($get('net_amount_usd') ?? 0);
        $rawRate = $get('exchange_rate') ?? '0';
        $rate = (float) str_replace(['.', ','], '', $rawRate);

        $total = floor($net * $rate);
        $set('total_vnd', $total); // Chỉ set số thuần, format để TextColumn lo
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
            'index' => Pages\ListPayoutLogs::route('/'),
            'create' => Pages\CreatePayoutLog::route('/create'),
            'edit' => Pages\EditPayoutLog::route('/{record}/edit'),
        ];
    }
}
