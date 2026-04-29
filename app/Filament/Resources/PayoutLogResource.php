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
use Filament\Forms\Get;
use Filament\Support\Enums\Alignment;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Enums\IconPosition;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use App\Filament\Resources\Shared\ActivitiesRelationManager;

class PayoutLogResource extends Resource
{
    use \App\Filament\Resources\Traits\HasPlatformCache;
    protected static ?string $model = PayoutLog::class;

    protected static ?string $navigationIcon = 'heroicon-o-rectangle-stack';

    public static function getNavigationGroup(): ?string
    {
        return 'wallet_payout';
    }

    public static function getNavigationLabel(): string
    {
        return __('system.labels.payout_log');
    }

    public static function getPluralModelLabel(): string
    {
        return __('system.labels.payout_log');
    }

    public static function getModelLabel(): string
    {
        return __('system.labels.payout_log_list');
    }
    protected static ?int $navigationSort = 2;

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery()
            ->select('payout_logs.*')
            ->selectRaw(match (DB::getDriverName()) {
                'sqlite' => "account_id || '_' || COALESCE(gc_brand, 'none') || '_' || COALESCE(parent_id, id) as group_key",
                default  => "CONCAT(account_id, '_', COALESCE(gc_brand, 'none'), '_', COALESCE(parent_id, id)) as group_key",
            })
            ->withCount('children')
            ->withSum(['children as children_sum' => fn($q) => $q->whereNull('deleted_at')], 'amount_usd')
            ->withSum(['children as settled_children_sum' => fn($q) => $q->whereNotNull('user_payment_id')], 'amount_usd')
            ->with(['user', 'payoutMethod', 'account.email']); // 🟢 TỐI ƯU: Tránh N+1

        // 2. Khóa chặt thứ tự sắp xếp Cha - Con (Đã fix lỗi SQL Sum cho Sếp)
        $query->orderByRaw('COALESCE(parent_id, id) DESC')->orderBy('id', 'ASC');

        $user = auth()->user();

        // 3. KIỂM TRA QUYỀN ADMIN Hoặc FINANCE
        if ($user?->isAdmin() || $user?->isFinance()) {
            return $query;
        }

        // 4. CHỐT CHẶN BẢO MẬT CHO STAFF/OPERATOR
        // Cụ thể: Ẩn các đơn của người khác khỏi Staff
        return $query->where('user_id', $user->id);
    }

    // 🟢 KHÓA QUYỀN SỬA ĐỐI VỚI NHÂN VIÊN KHI ĐƠN ĐÃ ĐƯỢC EXCHANGE (CÓ ĐƠN CON)
    public static function canEdit(Model $record): bool
    {
        if (auth()->user()?->isAdmin() || auth()->user()?->isFinance()) {
            return true;
        }

        // 🟢 KHÓA LUÔN: Nếu đã có đơn con (đã Exchange) thì không cho sửa
        // Dùng children_count (đã eager load từ getEloquentQuery) thay vì children()->count() để tránh N+1
        if ($record->children_count > 0) {
            return false;
        }

        return true;
    }

    // Khóa luôn quyền Xóa nếu đơn đã Exchange để tránh trường hợp xóa chui
    public static function canDelete(Model $record): bool
    {
        if (auth()->user()?->isAdmin() || auth()->user()?->isFinance()) {
            return true;
        }

        if ($record->children_count > 0) {
            return false;
        }

        return true;
    }

    /**
     * 🟢 QUY TẮC 1: TIÊU ĐỀ DUY NHẤT TẠI ĐÂY
     */

    /**
     * 🟢 QUY TẮC 3: CÁC HÀM SYNC GỌI LẠI QUY TẮC 1 & 2
     */


    public static function syncFromGoogleSheet(): void
    {
        try {
            $service = app(\App\Services\GoogleSheetService::class);
            $targetTab = 'Payout_Logs';
            $rows = $service->readSheet('A2:R', $targetTab);

            if (empty($rows))
                return;

            // 🟢 TỰ ĐỘNG TÌM VỊ TRÍ CỘT ĐỂ TRÁNH LỆCH KHI THÊM CỘT SAU NÀY
            $statusIdx = array_search('Status', \App\Services\GoogleSyncService::$payoutLogHeaders);
            $noteIdx = array_search('Note', \App\Services\GoogleSyncService::$payoutLogHeaders);

            $count = 0;
            foreach ($rows as $row) {
                if (isset($row[0]) && is_numeric($row[0])) {
                    // 🟢 FIX: Dùng find() để kích hoạt Observer cập nhật Balance ví
                    $log = PayoutLog::find($row[0]);
                    if ($log) {
                        $allowedStatuses = ['pending', 'completed', 'hold', 'rejected'];
                        $newStatus = strtolower(trim($row[$statusIdx] ?? ''));
                        if (!in_array($newStatus, $allowedStatuses)) {
                            continue;
                        }

                        // 🟢 GẮN CỜ ẢO TRƯỚC KHI UPDATE ĐỂ BÁO CHO OBSERVER BIẾT
                        $log->is_syncing_from_sheet = true;

                        $log->update([
                            'status' => $newStatus,
                            'note'   => trim($row[$noteIdx] ?? ''),
                        ]);
                        $count++;
                    }
                }
            }

            \Filament\Notifications\Notification::make()
                ->title(__('system.notifications.synced_successfully', ['count' => $count]))
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
                Infolists\Components\Section::make(__('system.labels.transaction_details'))
                    ->schema([
                        Infolists\Components\TextEntry::make('created_at')->label(__('system.labels.date'))
                            ->dateTime('d/m/Y H:i'),
                        Infolists\Components\TextEntry::make('status')
                            ->label(__('system.labels.status'))
                            ->badge()
                            ->color(fn(string $state): string => match ($state) {
                                'pending' => 'warning',
                                'completed' => 'success',
                                'rejected' => 'danger',
                                default => 'gray',
                            })
                            ->formatStateUsing(fn(string $state): string => __('system.status.' . $state)),
                        Infolists\Components\TextEntry::make('account.email.email')
                            ->label(__('system.labels.account_email')),
                        Infolists\Components\TextEntry::make('payment_status')
                            ->label(__('system.labels.disbursement_status'))
                            ->getStateUsing(fn($record) => $record->user_payment_id ? 'completed' : 'pending')
                            ->badge()
                            ->color(fn(string $state): string => match ($state) {
                                'completed' => 'success',
                                'pending' => 'danger',
                                default => 'secondary',
                            })
                            ->formatStateUsing(fn(string $state): string => __('system.payment_status.' . $state)),
                        Infolists\Components\TextEntry::make('payoutMethod.name')
                            ->label(__('system.labels.wallet')) // Đã đổi theo yêu cầu
                            // 🟢 CHỈ HIỆN NẾU LÀ PAYPAL
                            ->visible(fn($record) => $record->asset_type === 'paypal'),
                    ])->columns(2),

                Infolists\Components\Section::make(__('system.payout_logs.label.asset_giftcard_info'))
                    ->schema([
                        Infolists\Components\TextEntry::make('asset_type')
                            ->label(__('system.payout_logs.fields.asset_type'))
                            ->badge()
                            ->color('info')
                            ->formatStateUsing(fn(string $state): string => match ($state) {
                                'paypal' => __('system.payout_logs.asset_types.paypal'),
                                'gift_card' => __('system.payout_logs.asset_types.gift_card'),
                                default => strtoupper(str_replace('_', ' ', $state)),
                            }),

                        Infolists\Components\TextEntry::make('transaction_type')
                            ->label(__('system.labels.transaction_type'))
                            ->formatStateUsing(function ($record, $state) {
                                $options = match ($record->asset_type) {
                                    'paypal' => [
                                        'withdrawal' => __('system.payout_logs.transaction_types.withdrawal'),
                                        'liquidation' => __('system.payout_logs.transaction_types.liquidation'),
                                    ],
                                    'gift_card' => [
                                        'hold' => __('system.payout_logs.transaction_types.hold'),
                                        'liquidation' => __('system.payout_logs.transaction_types.liquidation'),
                                    ],
                                    default => [],
                                };

                                return $options[$state] ?? ucfirst($state);
                            }),
                    ])->columns(2),

                Infolists\Components\Section::make(__('system.payout_logs.label.gift_card_details'))
                    ->schema([
                        Infolists\Components\TextEntry::make('gc_brand')
                            ->label(__('system.labels.brand'))
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
                                'victorias-secret' => 'Victoria\'s Secret',
                                'apple' => 'Apple',
                                'ebay' => 'eBay',
                                'walgreens' => 'Walgreens',
                                default => ucwords(str_replace(['_', '-'], ' ', $state)),
                            }),
                        Infolists\Components\TextEntry::make('gc_code')
                            ->label(__('system.payout_logs.fields.card_number'))
                            ->copyable()
                            ->copyMessage(__('system.payout_logs.messages.copied'))
                            ->suffixAction(
                                Infolists\Components\Actions\Action::make('copyAll')
                                    ->icon('heroicon-m-clipboard-document')
                                    ->tooltip(__('system.actions.copy'))
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
                                            'victorias-secret' => 'Victoria\'s Secret',
                                            'apple' => 'Apple',
                                            'ebay' => 'eBay',
                                            'walgreens' => 'Walgreens',
                                            default => ucwords(str_replace(['_', '-'], ' ', $record->gc_brand)),
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
                            ->label(__('system.payout_logs.fields.pin'))
                            ->copyable(),
                    ])
                    ->columns(2)
                    ->visible(fn($record) => $record->asset_type === 'gift_card'),

                Infolists\Components\Section::make(__('system.payout_logs.label.cashback_summary'))
                    ->schema([
                        Infolists\Components\TextEntry::make('amount_usd')
                            ->label(__('system.labels.amount_usd'))
                            ->money('usd')
                            ->numeric(2),
                        Infolists\Components\TextEntry::make('fee_usd')
                            ->label(__('system.labels.fee_usd'))
                            ->money('usd')
                            ->numeric(2),
                        Infolists\Components\TextEntry::make('boost_percentage')
                            ->label(__('system.labels.boost_percentage'))
                            ->numeric(2)
                            ->visible(fn($record) => $record->asset_type === 'gift_card'),
                        Infolists\Components\TextEntry::make('net_amount_usd')
                            ->label(__('system.labels.net_amount_usd'))
                            ->money('usd')
                            ->weight(FontWeight::Bold)
                            ->color('warning'),
                        Infolists\Components\TextEntry::make('exchange_rate')
                            ->label(__('system.labels.exchange_rate'))
                            ->numeric(0, ',', '.')
                            ->visible(fn($record) => $record->transaction_type === 'liquidation'),

                        Infolists\Components\TextEntry::make('total_vnd')
                            ->label(__('system.labels.total_vnd'))
                            ->money('VND')
                            ->numeric(0, ',', '.')
                            ->weight(FontWeight::Bold)
                            ->color('success')
                            ->visible(fn($record) => $record->transaction_type === 'liquidation'),
                    ])->columns(2),

                Infolists\Components\Section::make(__('system.labels.note'))
                    ->schema([
                        Infolists\Components\TextEntry::make('note')
                            ->label(__('system.labels.note'))
                            ->placeholder(__('system.n/a'))
                            ->formatStateUsing(function ($state) {
                                if (str_contains($state, 'Liquidity from ID')) {
                                    return str_replace('Liquidity from ID', __('system.labels.liquidity_from_id'), $state);
                                }
                                return $state;
                            }),
                    ]),
            ]);
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make(__('system.account_claim.section_title'))
                    ->schema([
                        // CHỌN USER
                        Forms\Components\Select::make('user_id')
                            ->label(__('system.labels.user'))
                            ->placeholder(__('system.payout_logs.fields.select_user_first'))
                            ->options(\App\Models\User::whereHas('rebateTrackers')->pluck('name', 'id'))
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
                            ->label(__('system.payout_logs.fields.source_account'))
                            ->options(function (Forms\Get $get, ?\App\Models\PayoutLog $record) {
                                $userId = $get('user_id');
                                if (!$userId)
                                    return [];

                                $query = Account::query()->where('user_id', $userId);

                                return $query->with('email')
                                    ->get()
                                    // 🟢 CỐT LÕI NẰM Ở ĐÂY: Lọc bỏ tài khoản $0
                                    ->filter(function ($acc) use ($record) {
                                        // Giữ lại account nếu đang sửa đơn cũ
                                        if ($record && $record->account_id === $acc->id)
                                            return true;

                                        // Chỉ cho phép hiển thị các account có số dư > 0
                                        return self::getAvailableBalance($acc->id) > 0;
                                    })
                                    ->mapWithKeys(function ($acc) {
                                        $email = (string) ($acc->email?->email ?? __('system.no_email'));
                                        $platform = (string) (\App\Models\Platform::where('slug', $acc->platform)->value('name') ?? ucwords($acc->platform ?? __('system.n/a')));

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
                            ->placeholder(fn(Forms\Get $get) => !$get('user_id') ? __('system.payout_logs.fields.select_user_first') : __('system.payout_logs.fields.select_account'))
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
                        Forms\Components\Select::make('asset_type')
                            ->label(__('system.payout_logs.fields.asset_type'))
                            ->options([
                                'paypal' => __('system.payout_logs.asset_types.paypal'),
                                'gift_card' => __('system.payout_logs.asset_types.gift_card'),
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
                            ->label(__('system.labels.wallet')) // Đã đổi theo yêu cầu
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
                            ->label(__('system.labels.brand'))
                            ->placeholder(__('system.payout_logs.fields.select_brand'))
                            ->searchable()
                            ->visible(fn($get) => $get('asset_type') === 'gift_card')
                            ->required(fn($get) => $get('asset_type') === 'gift_card')
                            ->dehydrateStateUsing(fn($state) => str_replace('\\', '', $state))
                            // 1. HIỂN THỊ NHÃN ĐẦY ĐỦ THÔNG TIN
                            ->options(function (Forms\Get $get) {
                                $accId = $get('account_id');
                                if (!$accId)
                                    return [];

                                $account = Account::find($accId);
                                if (!$account)
                                    return [];

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
                                if (!$accId)
                                    return false;

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
                                            ->label(__('system.labels.brand_name'))
                                            ->required()
                                            ->lazy()
                                            ->afterStateUpdated(fn($state, $set) => $set('slug', \Str::slug($state))),

                                        Forms\Components\TextInput::make('slug')
                                            ->required()
                                            ->unique('brands', 'slug'),

                                        Forms\Components\TextInput::make('boost_percentage')
                                            ->label(__('system.labels.boost_percentage'))
                                            ->numeric()
                                            ->default(0)
                                            ->suffix('%'),

                                        Forms\Components\TextInput::make('maximum_limit')
                                            ->label(__('system.brands.fields.maximum_limit'))
                                            ->numeric()
                                            ->prefix('$')
                                            ->placeholder('0 = ' . __('system.payout_logs.fields.no_limit')),
                                    ]),
                            ])
                            ->createOptionUsing(function (array $data, Forms\Get $get) {
                                $account = Account::find($get('account_id'));
                                if (!$account)
                                    throw new \Exception("Please select an account first.");

                                $data['platform'] = $account->platform;
                                $brand = \App\Models\Brand::create($data);
                                return $brand->slug;
                            })
                            ->afterStateUpdated(function ($state, $set, $get) {
                                if (!$state)
                                    return;
                                $brand = \App\Models\Brand::where('slug', $state)->first();
                                if ($brand) {
                                    $set('boost_percentage', $brand->boost_percentage ?? 0);
                                    static::calculateNet($set, $get);
                                }
                            })
                            ->rules([
                                fn(Forms\Get $get): \Closure => function (string $attribute, $value, \Closure $fail) use ($get) {
                                    $brand = \App\Models\Brand::where('slug', $value)->first();
                                    if (!$brand || $brand->maximum_limit <= 0)
                                        return;

                                    // 🟢 FIX DRY
                                    $balance = self::getAvailableBalance($get('account_id'));

                                    if ($balance > $brand->maximum_limit) {
                                        $fail("This Brand cannot be selected because the balance (\$ {$balance}) exceeds the allowed limit (\$ {$brand->maximum_limit}).");
                                    }
                                },
                            ]),

                        Forms\Components\TextInput::make('gc_code')
                            ->label(__('system.payout_logs.fields.card_number'))
                            ->placeholder('XXXX-XXXX-XXXX')
                            ->visible(fn($get) => $get('asset_type') === 'gift_card'),

                        Forms\Components\TextInput::make('gc_pin')
                            ->label(__('system.payout_logs.fields.pin'))
                            ->placeholder('1234')
                            ->visible(fn($get) => $get('asset_type') === 'gift_card'),

                        Forms\Components\Select::make('transaction_type')
                            ->label(__('system.labels.transaction_type'))
                            ->options(fn($get) => match ($get('asset_type')) {
                                'paypal' => [
                                    'withdrawal' => __('system.payout_logs.transaction_types.withdrawal'),
                                    'liquidation' => __('system.payout_logs.transaction_types.liquidation'),
                                ],
                                'gift_card' => [
                                    'hold' => __('system.payout_logs.transaction_types.hold'),
                                    'liquidation' => __('system.payout_logs.transaction_types.liquidation'),
                                ],
                                default => [],
                            })
                            // 🟢 Ẩn khỏi Staff khi chọn PayPal
                            ->hidden(fn(Get $get) => !auth()->user()?->isAdmin() && $get('asset_type') === 'paypal')
                            ->default('withdrawal') // Mặc định rút tiền để DB không bị lỗi null
                            ->dehydrated(true)
                            ->required(fn($get) => auth()->user()?->isAdmin() || $get('asset_type') !== 'paypal')
                            ->live(),

                        Forms\Components\Select::make('status')
                            ->options([
                                'pending' => __('system.status.pending'),
                                'completed' => __('system.status.completed'),
                                'rejected' => __('system.status.failed'),
                            ])
                            ->default('pending')
                            // 🟢 Tàng hình hoàn toàn với Staff, tự động lưu Pending
                            ->hidden(fn() => !auth()->user()?->isAdmin())
                            ->dehydrated(true)
                            ->required(),

                        // 🟢 THÊM Ô NOTE ĐỂ DÁN LINK CLAIM PAYPAL
                        Forms\Components\Textarea::make('note')
                            ->label(fn(Get $get) => $get('asset_type') === 'paypal' ? __('system.payout_logs.fields.link_claim') : __('system.labels.note'))
                            ->helperText(fn(Get $get) => $get('asset_type') === 'paypal' ? __('system.payout_logs.fields.link_claim_helper') : '')
                            // Sếp gắn chính xác dòng của Sếp vào đây:
                            ->visible(fn(Get $get) => $get('asset_type') === 'paypal')
                            ->columnSpanFull(),

                    ])->columns(2),

                Forms\Components\Section::make(__('system.payout_logs.label.financials'))
                    ->schema([
                        Forms\Components\TextInput::make('amount_usd')
                            ->label(__('system.labels.order_value')) // Tạm dùng 'Amount (USD)'
                            ->numeric()
                            ->prefix('$')
                            ->required()
                            ->lazy() // Thay cho live(onBlur: true) để ổn định khi nhập số
                            // Hiển thị số dư khả dụng dưới dạng chữ nhỏ màu xanh
                            ->hint(function ($get) {
                                $accountId = $get('account_id');
                                if (!$accountId)
                                    return null;

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
                            ->lazy() // Thay cho live(onBlur: true)
                            ->afterStateUpdated(fn($set, $get) => self::calculateNet($set, $get)),

                        // Boost chỉ hiện khi là Gift Card
                        Forms\Components\TextInput::make('boost_percentage')
                            ->label('Boost (%)')
                            ->numeric()
                            ->default(0)
                            ->visible(fn($get) => $get('asset_type') === 'gift_card')
                            ->lazy() // Thay cho live(onBlur: true)
                            ->afterStateUpdated(fn($set, $get) => self::calculateNet($set, $get)),

                        Forms\Components\TextInput::make('net_amount_usd')
                            ->label(__('system.labels.net_amount_usd'))
                            ->numeric()
                            ->prefix('$')
                            ->readOnly()
                            ->helperText(__('system.payout_logs.fields.final_amount_helper'))
                            ->extraInputAttributes(['class' => 'font-bold text-success-600']),

                        // --- KHU VỰC LIQUIDATION (ĐỔI TIỀN) ---
                        Forms\Components\TextInput::make('exchange_rate')
                            ->label(__('system.labels.exchange_rate'))
                            ->numeric()
                            ->prefix('1$ =')
                            ->placeholder('Eg: 20000')
                            ->suffix('VNĐ/$')
                            // CHỈ HIỆN KHI LÀ LIQUIDATION
                            ->visible(fn($get) => $get('transaction_type') === 'liquidation')
                            ->required(fn($get) => $get('transaction_type') === 'liquidation')
                            ->lazy() // Thay cho live(onBlur: true)
                            ->afterStateUpdated(fn($set, $get) => self::calculateVnd($set, $get)),

                        Forms\Components\TextInput::make('total_vnd')
                            ->label(__('system.labels.total_vnd'))
                            ->prefix('₫')
                            ->readOnly()
                            // CHỈ HIỆN KHI LÀ LIQUIDATION
                            ->visible(fn($get) => $get('transaction_type') === 'liquidation')
                            ->formatStateUsing(fn($state) => $state ? number_format((float) $state, 0, ',', '.') : null)
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
            // 🟢 TỔNG KẾT THEO TÀI KHOẢN & THƯƠNG HIỆU: Đã có Groups ở cuối File lo liệu

            // 🟢 BƯỚC 3: Hiệu ứng thụt lề và đổi màu cho dòng con (Liquidation)
            // Dòng con: Có vạch xanh, thụt lề nhẹ
            // Dòng cha: Trắng tinh, chữ đậm
            ->recordClasses(fn($record) => $record->parent_id ? 'bg-gray-50/50 border-l-4 border-primary-500 ml-4' : 'bg-white font-medium')
            ->columns([
                Tables\Columns\TextColumn::make('id')
                    ->label('ID')
                    ->searchable()
                    ->sortable()
                    ->alignment(Alignment::Center),

                // Date - Platform
                Tables\Columns\TextColumn::make('created_at')
                    ->label(__('system.labels.date'))
                    ->dateTime('d/m/Y')
                    ->alignment(Alignment::Center),

                // Account Email - Platform
                Tables\Columns\TextColumn::make('account_id')
                    ->label(__('system.labels.account_email'))
                    ->alignment(Alignment::Center)
                    //->extraHeaderAttributes(['style' => 'width: 200px; min-width: 200px'])
                    //->extraAttributes(['style' => 'width: 200px; min-width: 200px'])
                    ->copyable()
                    ->copyMessage(__('system.payout_logs.messages.copied'))
                    ->wrap()
                    ->width('200px')
                    ->html() // Cho phép xuống dòng bằng thẻ <br>
                    ->formatStateUsing(function ($record) {
                        $account = $record->account;
                        if (!$account)
                            return __('system.n/a');

                        // Lấy email từ bảng Email liên kết với Account
                        $email = $account->email?->email ?? __('system.n/a');

                        // Lấy platform trực tiếp từ bảng Account (Cột platform có sẵn trong bảng accounts)
                        $platform_name = \App\Models\Platform::where('slug', $account->platform)->value('name');
                        $platform = $platform_name ?? ucwords(str_replace(['_', '-'], ' ', $account->platform ?? __('system.n/a')));

                        $userName = $record->user?->name ?? __('system.n/a');

                        $safeEmail    = e($email);
                        $safeUserName = e($userName);
                        return "
                            <div style='line-height: 1.6; padding: 4px 0;'>
                                <div style='font-weight: 600; color: #111827; margin-bottom: 4px;'>$safeEmail</div>
                                <div style='font-size: 12px; color: #6b7280; display: flex; align-items: center; gap: 4px;'>
                                    <span style='color: #9ca3af;'>" . __('system.labels.user') . ":</span>
                                    <span style='font-weight: 500; color: #4b5563;'>$safeUserName</span>
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
                    ->label(__('system.labels.asset_info'))
                    ->alignment(Alignment::Start)
                    ->copyable()
                    ->copyMessage('Copied to clipboard!')
                    ->wrap()
                    ->icon(fn($record): ?string => $record->asset_type === 'gift_card' ? 'heroicon-m-clipboard-document' : null)
                    ->iconColor('warning')
                    ->iconPosition(IconPosition::After)
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
                        if (!$data)
                            return '';
                        $prettyBrand = ucwords(str_replace('_', ' ', $data->gc_brand ?? 'N/A'));
                        $amount = number_format($record->net_amount_usd, 2);
                        // Trả về text thuần, không xuống dòng để copy chuẩn nhất
                        return "Brand: {$prettyBrand} | Amount: \${$amount} | Card number: {$data->gc_code} | PIN: {$data->gc_pin}";
                    })
                    ->state(function ($record) {
                        if ($record->asset_type === 'paypal') {
                            $walletName = e($record->payoutMethod?->name ?? 'N/A');

                            return "<div style='line-height: 1.7;'>
                                        <div style='margin-bottom: 4px;'>
                                            <span style='color: #6b7280; display: inline-block;'>PayPal Withdrawal:</span> 
                                            <strong style='color: #111827;'>$walletName</strong>
                                        </div>
                                    </div>";
                        }

                        // Định dạng cho Gift Card theo ý bạn
                        $assetType = $record->asset_type ? __('system.payout_logs.asset_types.' . $record->asset_type) : __('system.n/a');

                        // 🟢 FIX: Handle both '-' and '_' in brand name formatting
                        $brand = $record->gc_brand;
                        $brand = match ($brand) {
                            'victoria\'s_secret', 'victorias-secret' => 'Victoria\'s Secret',
                            'visa' => 'Visa/Mastercard',
                            default => e(ucwords(str_replace(['_', '-'], ' ', $brand ?? __('system.n/a'))))
                        };

                        $code = e($record->gc_code ?? '---');
                        $pin = e($record->gc_pin ?? '---');
                        return "
                                <div style='line-height: 1.7;'>
                                <div style='margin-bottom: 4px;'>
                                        <span style='color: #6b7280; display: inline-block;'>" . __('system.payout_logs.fields.asset_type') . ":</span> 
                                        <strong style='color: #111827;'>{$assetType}</strong>
                                    </div>
                                    <div style='margin-bottom: 4px;'>
                                        <span style='color: #6b7280; display: inline-block;'>" . __('system.labels.brand') . ":</span> 
                                        <span style='color: #111827;'>{$brand}</span>
                                    </div>
                                    <div style='margin-bottom: 4px; white-space: nowrap;'>
                                        <span style='color: #6b7280; display: inline-block;'>" . __('system.payout_logs.fields.card_number') . ":</span> 
                                        <code style='background: #f3f4f6; padding: 2px 6px; border-radius: 4px;'>{$code}</code>
                                    </div>
                                    <div>
                                        <span style='color: #6b7280; display: inline-block;'>" . __('system.payout_logs.fields.pin') . ":</span> 
                                        <code style='background: #f3f4f6; padding: 2px 6px; border-radius: 4px;'>{$pin}</code>
                                    </div>
                                </div>
                            ";
                    }),

                Tables\Columns\TextColumn::make('transaction_type')
                    ->label(__('system.labels.transaction_type'))
                    ->alignment(Alignment::Center)
                    ->formatStateUsing(fn(string $state): string => __('system.payout_logs.transaction_types.' . $state))
                    ->description(function ($record): ?\Illuminate\Support\HtmlString {
                        if ($record->transaction_type === 'liquidation')
                            return null;

                        // 🔴 NUCLEAR FIX: Truy vấn trực tiếp từ quan hệ để đảm bảo luôn có ngày
                        $exDate = $record->children()->where('status', 'completed')->latest()->value('created_at')
                            ?? $record->updated_at;

                        $dateSuffix = '';
                        if ($exDate) {
                            try {
                                $dateSuffix = ' ' . \Carbon\Carbon::parse($exDate)->format('d/m/Y');
                            } catch (\Exception $e) {
                                // Bỏ qua nếu lỗi format
                            }
                        }

                        // 🟢 LOGIC MỚI: Kiểm tra xem đơn này đã thanh khoản hết sạch chưa (Dựa trên con ĐÃ TẠO, chưa nhất thiết phải chốt)
                        $totalExchanged = floatval($record->children_sum ?? 0);
                        $netAmount = floatval($record->net_amount_usd ?? 0);
                        $isExchanged = $totalExchanged >= $netAmount && $record->children_count > 0;

                        if ($isExchanged) {
                            return new \Illuminate\Support\HtmlString(
                                '<div style="color: #6b7280; font-size: 11px; font-weight: bold; margin-top: 2px;">(Exchanged!' . ($dateSuffix ?: '') . ')</div>'
                            );
                        }

                        // Nếu là PayPal, kiểm tra thêm số dư ví (Optional)
                        if ($record->asset_type === 'paypal') {
                            $method = $record->payoutMethod;
                            $balance = $method ? $method->current_balance : 0;

                            if ($balance <= 0) {
                                return new \Illuminate\Support\HtmlString(
                                    '<div style="color: #6b7280; font-size: 11px; font-weight: bold; margin-top: 2px;">(No Liquidity' . ($dateSuffix ?: '') . ')</div>'
                                );
                            }
                        }

                        // Nếu chưa quy đổi hết: Hiện nút bấm (Chỉ Admin & Finance)
                        if (auth()->user()?->isAdmin() || auth()->user()?->isFinance()) {
                            return new \Illuminate\Support\HtmlString(
                                '<span style="color: #FF9F40; font-weight: bold; cursor: pointer; display: block; margin-top: 4px;">' . __('system.payout_logs.actions.exchange_to_vnd') . '</span>'
                            );
                        }

                        return null;
                    })
                    ->extraAttributes(function ($record) {
                        $isPrivileged = auth()->user()?->isAdmin() || auth()->user()?->isFinance();
                        if (!$isPrivileged || !in_array($record->transaction_type, ['withdrawal', 'hold'])) {
                            return [];
                        }

                        // Kiểm tra xem có cần thanh khoản nữa không
                        $canExchange = false;
                        if ($record->asset_type === 'paypal') {
                            $method = $record->payoutMethod;
                            $canExchange = $method && $method->current_balance > 0;
                        } else {
                            // Gift Card: Cho đổi nếu chưa đổi hết
                            $liquidated = $record->children()->where('status', 'completed')->sum('amount_usd') ?? 0;
                            $canExchange = ($record->net_amount_usd - $liquidated) > 0.01;
                        }

                        if ($canExchange) {
                            return [
                                'class' => 'cursor-pointer transition hover:opacity-70',
                                'wire:click.stop' => "mountTableAction('currency_exchange', '{$record->id}')",
                            ];
                        }
                        return [];
                    }),
                Tables\Columns\TextColumn::make('net_amount_usd')
                    ->label(__('system.labels.net_amount_usd'))
                    ->money('usd')
                    ->numeric(2, '.', ',')
                    ->prefix('$')
                    ->color('warning')
                    ->weight(FontWeight::Bold)
                    ->alignment(Alignment::Center),
                Tables\Columns\TextColumn::make('total_vnd')
                    ->label(__('system.labels.total_vnd'))
                    ->placeholder('')
                    ->visible(fn() => auth()->user()?->isAdmin() || auth()->user()?->isFinance()) // 🟢 HIỆN CHO ADMIN & FINANCE
                    ->formatStateUsing(fn($record, $state) => $record->transaction_type === 'liquidation' ? '₫' . number_format($state, 0, ',', '.') : '')
                    ->alignment(Alignment::Center),
                Tables\Columns\TextColumn::make('status')
                    ->label(__('system.labels.status'))
                    ->badge()
                    ->alignment(Alignment::Center)
                    ->color(fn(string $state): string => match ($state) {
                        'pending' => 'warning',
                        'completed' => 'success',
                        'rejected' => 'danger',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn(string $state): string => __("system.status.{$state}")),

                Tables\Columns\TextColumn::make('userPayment.batch_id')
                    ->label(__('system.labels.batch_id'))
                    ->badge()
                    ->placeholder(__('system.n/a'))
                    ->color('info')
                    ->weight('bold')
                    ->searchable()
                    ->alignment(Alignment::Center),

            ])
            ->filters([
                // Only shows platforms that currently have records in PayoutLog
                Tables\Filters\SelectFilter::make('platform')
                    ->label(__('system.labels.platform'))
                    ->options(function () {
                        $platforms = Account::query()
                            ->whereIn('id', PayoutLog::distinct()->pluck('account_id'))
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
                    ->label(__('system.labels.user'))
                    ->visible(fn() => auth()->user()?->isAdmin() || auth()->user()?->isFinance()) // 🟢 HIỆN CHO ADMIN & FINANCE
                    ->options(
                        fn() => \App\Models\User::query()
                            ->whereIn('id', PayoutLog::distinct()->pluck('user_id'))
                            ->pluck('name', 'id')
                            ->toArray()
                    )
                    ->searchable(),

                // 1. LỌC THEO TRẠNG THÁI (STATUS)
                Tables\Filters\SelectFilter::make('status')
                    ->label(__('system.labels.status'))
                    ->options([
                        'pending' => __('system.status.pending'),
                        'completed' => __('system.status.completed'),
                        'rejected' => __('system.status.rejected'),
                    ]),


                // 2. LỌC THEO THỜI GIAN (TỪ NGÀY - ĐẾN NGÀY)
                Tables\Filters\Filter::make('created_at')
                    ->form([
                        Forms\Components\TextInput::make('created_from')
                            ->label(__('system.labels.from'))
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
                                if (blank($state))
                                    return null;
                                try {
                                    // Dịch từ chuẩn VN (d/m/Y) sang chuẩn Quốc tế (Y-m-d) để MySQL hiểu
                                    return \Carbon\Carbon::createFromFormat('d/m/Y', $state)->format('Y-m-d');
                                } catch (\Exception $e) {
                                    return null;
                                }
                            }),

                        Forms\Components\TextInput::make('created_until')
                            ->label(__('system.labels.until'))
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
                                if (blank($state))
                                    return null;
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
            // 🟢 THAY ĐỔI DÒNG NÀY: Admin & Finance hiện 4 cột, Staff hiện 3 cột
            ->filtersFormColumns(auth()->user()?->isAdmin() || auth()->user()?->isFinance() ? 6 : 5)
            ->actions([
                //Nút Exchange to VND ở cột Transaction Type
                Tables\Actions\Action::make('currency_exchange')
                    ->label(__('system.labels.transaction_type'))
                    ->extraAttributes([
                        'style' => 'display: none !important;',
                    ])
                    ->icon('heroicon-m-banknotes')
                    ->color('success')
                    ->tooltip('Exchange to VND')
                    ->modalHeading('Currency Exchange (Sell to VND)')
                    ->modalWidth('md')
                    ->mountUsing(function (Forms\ComponentContainer $form, $record) {
                        // MẶC ĐỊNH: Lấy số dư thực tế còn lại
                        $initialAmount = $record->net_amount_usd;

                        if ($record->asset_type === 'paypal') {
                            // PayPal: Lấy số dư tổng của Ví đó
                            $initialAmount = $record->payoutMethod?->current_balance ?? $record->net_amount_usd;
                        } else {
                            // Gift Card: Lấy số tiền chưa thanh khoản của chính đơn này
                            $liquidated = $record->children()->where('status', 'completed')->sum('amount_usd') ?? 0;
                            $initialAmount = max(0, $record->net_amount_usd - $liquidated);
                        }

                        return $form->fill([
                            'net_amount_usd' => round($initialAmount, 2),
                            'exchange_rate' => 20000, // Mặc định tỷ giá
                            'total_vnd' => $initialAmount * 20000,
                        ]);
                    })

                    // 🟢 Bỏ check diffInDays, chỉ cần là withdrawal, hold là cho hiện (Chỉ Admin)
                    ->visible(function ($record) {
                        if (!auth()->user()?->isAdmin() && !auth()->user()?->isFinance())
                            return false;
                        if (!in_array($record->transaction_type, ['withdrawal', 'hold']))
                            return false;

                        // 🟢 KHÓA CHẶT: Nếu đã chốt sổ 100% (Trực tiếp hoặc đã thanh khoản hết qua con) k cho tạo thêm Exchange
                        $totalExEx = floatval($record->children_sum ?? 0);
                        $netAmt = floatval($record->net_amount_usd ?? 0);
                        $isExchanged = $totalExEx >= $netAmt && $record->children_count > 0;

                        if ($record->user_payment_id !== null || $isExchanged) {
                            return false;
                        }

                        if ($record->asset_type === 'paypal') {
                            return ($record->payoutMethod?->current_balance ?? 0) > 0.01;
                        }

                        // Gift Card: Kiểm tra số tiền còn lại
                        $liquidated = $record->children()->where('status', 'completed')->sum('amount_usd') ?? 0;
                        return ($record->net_amount_usd - $liquidated) > 0.01;
                    })
                    ->form([
                        // 🚀 NEW: Phân loại giao dịch (Chỉ dành cho PayPal US)
                        Forms\Components\Select::make('transaction_category')
                            ->label('Transaction Category')
                            ->options([
                                'send' => 'Send money',
                                'payment_service' => 'Payment service',
                                'withdraw_to_bank' => 'Withdraw to Bank',
                            ])
                            // 🟢 HIỆN VỚI TẤT CẢ PAYPAL (Bao gồm US và VN)
                            ->visible(
                                fn($record) =>
                                $record->asset_type === 'paypal' &&
                                in_array($record->payoutMethod?->type, ['paypal_us', 'paypal_vn'])
                            )
                            ->required(
                                fn($record) =>
                                $record->asset_type === 'paypal' &&
                                in_array($record->payoutMethod?->type, ['paypal_us', 'paypal_vn'])
                            )
                            ->native(false)
                            ->live(),

                        Forms\Components\TextInput::make('recipient_email')
                            ->label(__('system.labels.recipient_email'))
                            ->email()
                            ->required()
                            ->visible(fn($get) => $get('transaction_category') === 'send')
                            ->columnSpanFull(),

                        Forms\Components\Textarea::make('payment_description')
                            ->label(__('system.labels.payment_description'))
                            ->required()
                            ->visible(fn($get) => $get('transaction_category') === 'payment_service')
                            ->columnSpanFull(),

                        // 🟢 Phí rút tiền VNĐ (Chỉ dành cho PayPal VN)
                        Forms\Components\TextInput::make('withdrawal_fee_vn')
                            ->label('Withdrawal Fee (VND)')
                            ->numeric()
                            ->prefix('₫')
                            ->default(0)
                            ->required()
                            ->visible(
                                fn($get, $record) =>
                                $get('transaction_category') === 'withdraw_to_bank' &&
                                $record->payoutMethod?->type === 'paypal_vn'
                            )
                            ->live(onBlur: true)
                            ->afterStateUpdated(fn($set, $get) => self::calculateVnd($set, $get)),

                        // 🟢 Phí rút tiền theo % (Chỉ dành cho PayPal US)
                        Forms\Components\TextInput::make('withdrawal_fee_us_rate')
                            ->label('Withdrawal Fee Rate (%)')
                            ->numeric()
                            ->suffix('%')
                            ->default(0)
                            ->required()
                            ->visible(
                                fn($get, $record) =>
                                $get('transaction_category') === 'withdraw_to_bank' &&
                                $record->payoutMethod?->type === 'paypal_us'
                            )
                            ->live(onBlur: true)
                            ->afterStateUpdated(fn($set, $get) => self::calculateVnd($set, $get)),

                        Forms\Components\TextInput::make('net_amount_usd')
                            ->label(__('system.labels.net_usd'))
                            ->numeric()
                            ->prefix('$')
                            ->default(fn($record) => $record->amount_usd)
                            ->required()
                            ->live()
                            // 🟢 HIỆN SỐ DƯ TỨC THÌ: Giúp sếp theo dõi ví khi đang gõ
                            ->helperText(function ($get, $record) {
                                if ($record->asset_type !== 'paypal')
                                    return null;

                                $currentWalletBalance = $record->payoutMethod?->current_balance ?? 0;
                                $inputAmount = (float) $get('net_amount_usd');
                                $remaining = $currentWalletBalance - $inputAmount;

                                $color = $remaining < 0 ? 'text-danger-600' : 'text-success-600';
                                $remainingStr = number_format($remaining, 2);
                                $walletStr = number_format($currentWalletBalance, 2);

                                return new \Illuminate\Support\HtmlString(
                                    "Wallet: <span class='font-bold'>\${$walletStr}</span> | " .
                                    "Remaining: <span class='font-bold {$color}'>\${$remainingStr}</span>"
                                );
                            })
                            // Khi thay đổi USD, tính lại VND và kiểm tra số dư
                            ->afterStateUpdated(function ($set, $get, $record, $state) {
                                if ($record->asset_type === 'paypal') {
                                    $balance = $record->payoutMethod?->current_balance ?? 0;
                                    if ((float) $state > $balance) {
                                        // 🟢 BÁO ĐỎ VỀ 0: Chặn số tiền vượt quá ví
                                        $set('net_amount_usd', 0);
                                        $set('total_vnd', 0);
                                        // Thêm thông báo nhẹ cho sếp
                                        \Filament\Notifications\Notification::make()
                                            ->title(__('system.payout_logs.messages.balance_exceeded', ['balance' => $balance, 'limit' => $balance]))
                                            ->danger()
                                            ->send();
                                        return;
                                    }
                                }
                                self::calculateVnd($set, $get);
                            }),

                        Forms\Components\TextInput::make('exchange_rate')
                            ->label(__('system.labels.exchange_rate'))
                            ->prefix('1$ =')
                            ->placeholder('Eg: 20000')
                            ->numeric()
                            //->mask('99.999') // Dấu chấm ở đây chỉ là hiển thị
                            ->suffix('VNĐ/$')
                            ->required()
                            ->live(onBlur: true) // FIX: Dùng onBlur để tránh bị xóa nhảy số khi đang gõ
                            ->afterStateUpdated(fn($set, $get) => self::calculateVnd($set, $get))
                            // Hiện gợi ý bên dưới để check lại số nghìn/triệu
                            ->helperText(fn($state) => $state ? 'Typing: ' . number_format((float) $state, 0, ',', '.') . ' VNĐ' : null),

                        Forms\Components\TextInput::make('total_vnd')
                            ->label('Total VND')
                            ->prefix('₫')
                            ->readOnly()
                            // 🟢 TUYỆT CHIÊU: Tự động thêm dấu chấm khi hiển thị
                            ->formatStateUsing(fn($state) => $state ? number_format((float) $state, 0, ',', '.') : '0')
                            ->extraInputAttributes(['class' => 'font-bold text-success-600', 'style' => 'font-size: 1.2rem;'])
                            // 🟢 QUAN TRỌNG: Trước khi lưu vào DB, xóa hết dấu chấm để thành số thuần túy
                            ->dehydrateStateUsing(fn($state) => (float) str_replace('.', '', $state ?? '0')),
                    ])
                    ->action(function ($record, array $data) {
                        $exchangeCreated = false;

                        DB::transaction(function () use ($record, $data, &$exchangeCreated) {
                        $record = PayoutLog::query()
                            ->lockForUpdate()
                            ->find($record->id);

                        if (!$record) {
                            return;
                        }

                        $existingLiquidation = PayoutLog::query()
                            ->where('parent_id', $record->id)
                            ->where('transaction_type', 'liquidation')
                            ->where('status', 'completed')
                            ->lockForUpdate()
                            ->first();

                        if ($record->user_payment_id !== null || $existingLiquidation !== null) {
                            \Filament\Notifications\Notification::make()
                                ->title('Exchange failed!')
                                ->body('This payout log has already been liquidated or settled.')
                                ->danger()
                                ->send();
                            return;
                        }

                        // 🟢 LÀM SẠCH DỮ LIỆU TRƯỚC KHI LƯU
                        $cleanRate = (float) str_replace(['.', ','], '', $data['exchange_rate']);
                        $cleanVnd = (float) str_replace(['.', ','], '', $data['total_vnd']);
                        $usdAmount = (float) $data['net_amount_usd'];

                        // 🚀 NEW: Tiền tố ghi chú theo loại giao dịch
                        $categoryPrefix = '';
                        $category = $data['transaction_category'] ?? null;
                        if (!empty($category)) {
                            $categoryPrefix = match ($category) {
                                'send' => '[SEND] ',
                                'payment_service' => '[PAYMENT_SERVICE] ',
                                'withdraw_to_bank' => '[WITHDRAW] ',
                                default => '',
                            };
                        }

                        // 🚀 Phụ bản ghi chú chi tiết phí
                        $feeNote = '';
                        if ($category === 'withdraw_to_bank') {
                            if ($record->payoutMethod?->type === 'paypal_vn') {
                                $fVnd = number_format((float) ($data['withdrawal_fee_vn'] ?? 0), 0, ',', '.');
                                $feeNote = "Fee: {$fVnd}đ - ";
                            } else if ($record->payoutMethod?->type === 'paypal_us') {
                                $fRate = (float) ($data['withdrawal_fee_us_rate'] ?? 0);
                                $feeNote = "Fee: {$fRate}% - ";
                            }
                        }

                        PayoutLog::create([
                            'parent_id' => $record->id,
                            'user_id' => $record->user_id,
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
                            'note' => $categoryPrefix .
                                $feeNote .
                                ($category === 'send' ? (($data['recipient_email'] ?? '') . ' - ') : '') .
                                ($category === 'payment_service' ? (($data['payment_description'] ?? '') . ' - ') : '') .
                                __('system.labels.liquidity_from_id') . $record->id,
                        ]);

                        $exchangeCreated = true;

                        });

                        if ($exchangeCreated) {
                            \Filament\Notifications\Notification::make()
                                ->title('Exchange successful!')
                                ->success()
                                ->send();
                        }
                    }),

                // Nút Xem chi tiết (Hình con mắt) hiện ra bên ngoài
                Tables\Actions\ViewAction::make()
                    ->label('') // Để trống nhãn để chỉ hiện icon cho gọn
                    ->modalHeading(__('system.payout_logs.label.payout_log_details')) // TIÊU ĐỀ CỦA MODAL
                    ->tooltip(__('system.labels.detail')) // Hiện ghi chú khi di chuột vào
                    ->icon('heroicon-o-eye')
                    ->color('gray'), // Màu xám nhẹ nhàng, không lấn át nút cam,
                Tables\Actions\ActionGroup::make([
                    Tables\Actions\RestoreAction::make(), // 🟢 Nút khôi phục dòng bị xóa
                    Tables\Actions\ForceDeleteAction::make()
                        ->visible(fn() => auth()->user()?->isAdmin())
                        ->hidden(function ($record) {
                            $settledSum = floatval($record->settled_children_sum ?? 0);
                            $netAmount = floatval($record->net_amount_usd ?? 0);
                            return $record->user_payment_id !== null || ($settledSum >= $netAmount && $record->children_count > 0);
                        }), // 🛑 KHÓA KHI ĐÃ CHỐT SỔ 100%
                    Tables\Actions\EditAction::make()
                        ->hidden(function ($record) {
                            $settledSum = floatval($record->settled_children_sum ?? 0);
                            $netAmount = floatval($record->net_amount_usd ?? 0);
                            return $record->user_payment_id !== null || ($settledSum >= $netAmount && $record->children_count > 0);
                        }), // 🛑 KHÓA KHI ĐÃ CHỐT SỔ 100%
                    Tables\Actions\DeleteAction::make()
                        ->hidden(function ($record) {
                            $settledSum = floatval($record->settled_children_sum ?? 0);
                            $netAmount = floatval($record->net_amount_usd ?? 0);
                            return $record->user_payment_id !== null || ($settledSum >= $netAmount && $record->children_count > 0);
                        }), // 🛑 KHÓA KHI ĐÃ CHỐT SỔ 100%
                ]),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    // 🟢 NÚT EXPORT PAYOUT LOGS
                    Tables\Actions\BulkAction::make('export_payout_logs_to_sheet')
                        ->label('Export to Google Sheet')
                        ->icon('heroicon-o-table-cells')
                        ->color('success')
                        ->visible(fn() => auth()->user()?->isAdmin() || auth()->user()?->isFinance())
                        ->requiresConfirmation()
                        ->action(function (\Illuminate\Database\Eloquent\Collection $records, \App\Services\GoogleSyncService $syncService) {
                            try {
                                $syncService->syncPayoutLogs($records);

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
                            $records->filter(fn($r) => $r->user_payment_id === null)->each->update(['status' => 'completed']);

                            // Gợi ý: Gọi Job để sync tất cả lên Sheet sau khi update xong
                            foreach ($records as $record) {
                                \App\Jobs\SyncGoogleSheetJob::dispatch($record->id, get_class($record));
                            }
                        }),

                    // 🚀 NÚT MỚI: CHỐT SỔ & TÍNH LƯƠNG TỰ ĐỘNG
                    Tables\Actions\BulkAction::make('generate_payment')
                        ->label('Settle & Generate Payment') // Chốt sổ & Tạo phiếu thanh toán
                        ->icon('heroicon-o-calculator')
                        ->color('warning')
                        ->visible(fn() => auth()->user()?->isAdmin() || auth()->user()?->isFinance()) // Admin & Finance
                        ->requiresConfirmation()
                        ->form([
                            Forms\Components\TextInput::make('manual_payout_rate')
                                ->label('Payout Exchange Rate')
                                ->placeholder('Eg: 20000')
                                ->numeric()
                                ->helperText('💡 Enter the rate YOU WANT TO PAY the user. If left blank, it will default to the Market Rate (0 profit).'),

                            Forms\Components\TextInput::make('payout_percentage')
                                ->label('Payout Percentage (%)')
                                ->placeholder('Eg: 35')
                                ->numeric()
                                ->default(100)
                                ->helperText('💡 Percentage of the total value to pay the user (e.g. 35% of USD * Rate).'),
                        ])
                        ->action(function (\Illuminate\Database\Eloquent\Collection $records, array $data) {
                            $paymentGenerated = false;

                            DB::transaction(function () use ($records, $data, &$paymentGenerated) {

                            // 1. Chỉ lọc đơn hợp lệ: Đã Completed và Chưa bị chốt sổ
                            // 🚀 SECURITY FIX: Dùng lockForUpdate() để tránh tranh chấp khi có 2 admin cùng bấm nút
                            $validSelected = PayoutLog::whereIn('id', $records->pluck('id'))
                                ->where('status', 'completed')
                                ->whereNull('user_payment_id')
                                ->lockForUpdate()
                                ->get();

                            if ($validSelected->isEmpty()) {
                                \Filament\Notifications\Notification::make()
                                    ->title('Settlement Failed!')
                                    ->body('No valid records found (Requires "Completed" status and not yet settled).')
                                    ->danger()
                                    ->send();
                                return;
                            }

                            // 🟢 FIX LỖI NHÂN ĐÔI (DOUBLE COUNTING)
                            $parentIds = $validSelected->map(fn($log) => $log->parent_id ?? $log->id)->unique();

                            // Lấy lại danh sách các đơn Gốc (Parent) sạch sẽ từ Database
                            // 🟢 FIX: Cho phép lấy Parent kể cả khi Parent ĐÃ bị settled, để giải quyết các Child tới sau
                            $parentLogs = PayoutLog::whereIn('id', $parentIds)
                                ->with(['account', 'payoutMethod'])
                                ->lockForUpdate()
                                ->get();

                            // 2. GOM NHÓM THÔNG MINH (Chỉ gom các đơn Gốc)
                            $groupedLogs = $parentLogs->groupBy(function ($log) {
                                $platform = $log->account?->platform ?? 'unknown';
                                return $log->user_id . '_' .
                                    $platform . '_' .
                                    $log->asset_type . '_' .
                                    ($log->gc_brand ?? 'null') . '_' .
                                    ($log->payout_method_id ?? 'null');
                            });

                            // 3. XỬ LÝ TỪNG NHÓM 
                            foreach ($groupedLogs as $groupKey => $logs) {
                                $firstLog = $logs->first();
                                $sourceName = '';
                                $platformRaw = $firstLog->account?->platform ?? 'unknown';
                                $platformName = static::getPlatformName($platformRaw);

                                if ($firstLog->asset_type === 'gift_card') {
                                    $brandRecord = \App\Models\Brand::where('slug', $firstLog->gc_brand)->first();
                                    $sourceName = $brandRecord ? $brandRecord->name : ucwords(str_replace(['_', '-'], ' ', $firstLog->gc_brand));
                                } else {
                                    $sourceName = $firstLog->payoutMethod?->name ?? 'Unknown Wallet';
                                }

                                $totalUsd = 0;
                                $totalVndMarket = 0;

                                $parentIdsToUpdate = [];
                                $childIdsToUpdate = [];

                                // 🟢 QUÉT TỪNG ĐƠN GỐC ĐỂ TÍNH TIỀN
                                foreach ($logs as $log) {
                                    /** @var \App\Models\PayoutLog $log */
                                    // 🟢 CHỈ LẤY CÁC CON THANH KHOẢN CHƯA BỊ CHỐT SỔ (user_payment_id IS NULL)
                                    $liquidationChildren = $log->children()
                                        ->where('transaction_type', 'liquidation')
                                        ->where('status', 'completed')
                                        ->whereNull('user_payment_id')
                                        ->lockForUpdate()
                                        ->get();

                                    $usd = 0;
                                    $vndMarket = 0;

                                    if ($liquidationChildren->isNotEmpty()) {
                                        $usd = (float) $liquidationChildren->sum('net_amount_usd');
                                        $vndMarket = (float) $liquidationChildren->sum('total_vnd');

                                        // Lưu lại hết IDs con để chốt sổ (không cho thanh khoản nữa)
                                        foreach ($liquidationChildren as $child) {
                                            $childIdsToUpdate[] = $child->id;
                                        }
                                    } else {
                                        // 🟢 CHỈ THANH TOÁN PARENT NẾU PARENT CHƯA BỊ CHỐT SỔ
                                        if (is_null($log->user_payment_id)) {
                                            $usd = (float) $log->net_amount_usd;
                                            $vndMarket = (float) $log->total_vnd;
                                            $parentIdsToUpdate[] = $log->id;
                                        }
                                    }

                                    $totalUsd += $usd;
                                    $totalVndMarket += $vndMarket;
                                }

                                // 🟢 Nếu cả Parent và Children đều đã chốt sổ hết => Skip nhóm này
                                if ($totalUsd <= 0) {
                                    continue;
                                }

                                // Tính tỷ giá thị trường trung bình
                                $averageMarketRate = $totalUsd > 0 ? round($totalVndMarket / $totalUsd, 2) : 0;

                                // Lấy tỷ giá trả user từ form (nếu không nhập thì lấy bằng tỷ giá thị trường)
                                $payoutRate = (float) ($data['manual_payout_rate'] ?? $averageMarketRate);
                                $payoutPercentage = (float) ($data['payout_percentage'] ?? 100);

                                // Tiền thực trả = (Số lượng USD * Tỷ giá chi trả) * (% chi trả / 100)
                                $totalVndPayout = floor(($totalUsd * $payoutRate) * ($payoutPercentage / 100));

                                // Profit của Gin = (Tỷ giá thanh khoản - Tỷ giá trả user) * (Số lượng USD * % chi trả)
                                // Công thức: (MarketRate - PayoutRate) * TotalUSD * (PayoutPercentage / 100)
                                $profitVnd = floor(($averageMarketRate - $payoutRate) * $totalUsd * ($payoutPercentage / 100));

                                // 4. TẠO PHIẾU LƯƠNG
                                $payment = \App\Models\UserPayment::create([
                                    'user_id' => $firstLog->user_id,
                                    'platform' => $platformName,
                                    'asset_group' => $firstLog->asset_type === 'gift_card' ? 'gift_card' : 'paypal',
                                    'transaction_type' => ($firstLog->asset_type === 'gift_card' ? 'Gift Card' : 'PayPal') . " ({$sourceName})",
                                    'total_usd' => $totalUsd,
                                    'exchange_rate' => $averageMarketRate, // Lưu Market Rate để đối soát
                                    'payout_rate' => $payoutRate,        // Lưu Payout Rate
                                    'payout_percentage' => $payoutPercentage, // Lưu tỷ lệ chi trả
                                    'total_vnd' => $totalVndPayout,      // Số tiền thực trả User
                                    'profit_vnd' => $profitVnd,          // Số tiền lãi
                                    'status' => 'pending',
                                ]);

                                $paymentGenerated = true;

                                // 5. CẬP NHẬT ID PHIẾU LƯƠNG ĐỂ KHÓA ĐƠN
                                PayoutLog::whereIn('id', $parentIdsToUpdate)->update(['user_payment_id' => $payment->id]);

                                if (!empty($childIdsToUpdate)) {
                                    PayoutLog::whereIn('id', $childIdsToUpdate)->update(['user_payment_id' => $payment->id]);
                                }
                            }

                            });

                            if ($paymentGenerated) {
                                \Filament\Notifications\Notification::make()
                                    ->title('Settlement Successful!')
                                    ->body('Payout rates and profits have been calculated correctly.')
                                    ->success()
                                    ->send();
                            }
                        })
                        ->deselectRecordsAfterCompletion(),

                    Tables\Actions\RestoreBulkAction::make(),     // 🟢 Khôi phục nhiều dòng
                    Tables\Actions\ForceDeleteBulkAction::make()
                        ->visible(fn() => auth()->user()?->isAdmin())
                        ->action(function (\Illuminate\Database\Eloquent\Collection $records) {
                            $unlockedRecords = $records->filter(function ($record) {
                                $settledSum = floatval($record->settled_children_sum ?? 0);
                                $netAmount = floatval($record->net_amount_usd ?? 0);
                                return $record->user_payment_id === null && !($settledSum >= $netAmount && $record->children_count > 0);
                            });
                            $lockedCount = $records->count() - $unlockedRecords->count();

                            if ($lockedCount > 0) {
                                \Filament\Notifications\Notification::make()
                                    ->title("Cannot force-delete $lockedCount settled records.")
                                    ->warning()
                                    ->send();
                            }

                            $unlockedRecords->each->forceDelete();
                        }),
                    Tables\Actions\DeleteBulkAction::make()
                        ->action(function (\Illuminate\Database\Eloquent\Collection $records) {
                            $unlockedRecords = $records->filter(function ($record) {
                                $settledSum = floatval($record->settled_children_sum ?? 0);
                                $netAmount = floatval($record->net_amount_usd ?? 0);
                                return $record->user_payment_id === null && !($settledSum >= $netAmount && $record->children_count > 0);
                            });
                            $lockedCount = $records->count() - $unlockedRecords->count();

                            if ($lockedCount > 0) {
                                \Filament\Notifications\Notification::make()
                                    ->title("Cannot delete $lockedCount settled records.")
                                    ->warning()
                                    ->send();
                            }

                            $unlockedRecords->each->delete();
                        }),
                ]),
            ])
            ->groups([
                Tables\Grouping\Group::make('group_key')
                    ->label(__('system.labels.account'))
                    ->collapsible()
                    ->getTitleFromRecordUsing(function ($record) {
                        $email = $record->account?->email?->email ?? __('system.n/a');
                        $platform = static::getPlatformName($record->account?->platform);
                        
                        // 🟢 TÍNH TỔNG CHO HEADER (Do phiên bản Filament này chưa hỗ trợ ->summary() trên Group)
                        $parts = explode('_', $record->group_key);
                        $accountId = $parts[0] ?? null;
                        $brand = $parts[1] ?? 'none';
                        $parentOrId = $parts[2] ?? null;

                        $baseQuery = PayoutLog::query()
                            ->where('account_id', $accountId)
                            ->where(function ($q) use ($parentOrId) {
                                $q->where('id', $parentOrId)->orWhere('parent_id', $parentOrId);
                            })
                            ->when($brand !== 'none', fn($q) => $q->where('gc_brand', $brand), fn($q) => $q->whereNull('gc_brand'));

                        $totalUsd = (clone $baseQuery)->whereNull('parent_id')->sum('net_amount_usd');
                        $totalVnd = (clone $baseQuery)->where('transaction_type', 'liquidation')->sum('total_vnd');

                        $usdStr = '$' . number_format($totalUsd, 2);
                        $vndStr = '₫' . number_format($totalVnd, 0, ',', '.');

                        return "{$email} | {$platform} | Total: {$usdStr} | {$vndStr}";
                    })
                    ->getKeyFromRecordUsing(fn($record) => $record->group_key)
                    ->scopeQueryByKeyUsing(function (Builder $query, $key) {
                        // 🟢 TÁCH KEY: [accountId]_[brand]_[withdrawalId]
                        $parts = explode('_', $key);
                        $accountId = $parts[0] ?? null;
                        $brand = $parts[1] ?? 'none';
                        $withdrawalId = $parts[2] ?? null;

                        return $query->where('payout_logs.account_id', $accountId)
                            ->when(
                                $brand !== 'none',
                                fn($sub) => $sub->where('payout_logs.gc_brand', $brand),
                                fn($sub) => $sub->whereNull('payout_logs.gc_brand')
                            )
                            ->where(function ($q) use ($withdrawalId) {
                                // 🟢 LỌC CHÍNH XÁC: Đơn cha HOẶC các đơn con của nó
                                $q->where('payout_logs.id', $withdrawalId)
                                    ->orWhere('payout_logs.parent_id', $withdrawalId);
                            });
                    }),
            ])
            ->defaultGroup('group_key');
    }

    // 🟢 HÀM HELPER: Tính số dư khả dụng duy nhất tại đây (DRY) + Cache per-request
    protected static array $balanceCache = [];

    public static function getAvailableBalance($accountId): float
    {
        if (!$accountId)
            return 0.0;

        // 🟢 Cache kết quả trong suốt 1 request để tránh query lặp lại
        if (isset(static::$balanceCache[$accountId])) {
            return static::$balanceCache[$accountId];
        }

        $confirmed = \App\Models\RebateTracker::where('account_id', $accountId)
            ->whereIn('status', ['confirmed'])
            ->sum('rebate_amount') ?? 0;

        $paid = PayoutLog::where('account_id', $accountId)
            ->whereIn('transaction_type', ['withdrawal', 'hold'])
            ->where('status', 'completed')
            ->sum('amount_usd') ?? 0;

        return static::$balanceCache[$accountId] = max(0, $confirmed - $paid);
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
        $rate = (float) ($get('exchange_rate') ?? 0);
        $category = $get('transaction_category');

        $baseVnd = $net * $rate;
        $totalVnd = $baseVnd;

        // 🟢 Trừ phí nếu chọn Withdraw to Bank
        if ($category === 'withdraw_to_bank') {
            $feeVnd = (float) ($get('withdrawal_fee_vn') ?? 0);
            $feeUsRate = (float) ($get('withdrawal_fee_us_rate') ?? 0);

            if ($feeVnd > 0) {
                $totalVnd -= $feeVnd;
            } elseif ($feeUsRate > 0) {
                $totalVnd = $baseVnd * (1 - ($feeUsRate / 100));
            }
        }

        $set('total_vnd', floor($totalVnd)); // Chỉ set số thuần, format để TextColumn lo
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
            'index' => Pages\ListPayoutLogs::route('/'),
            'create' => Pages\CreatePayoutLog::route('/create'),
            'edit' => Pages\EditPayoutLog::route('/{record}/edit'),
        ];
    }
}
