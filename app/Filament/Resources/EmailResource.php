<?php

namespace App\Filament\Resources;

use App\Filament\Resources\EmailResource\Pages;
use App\Filament\Resources\EmailResource\RelationManagers;
use App\Models\Email;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Support\Enums\Alignment;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use OpenSpout\Reader\Common\ColumnWidth;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Actions\Action;
use Filament\Forms\Components\Wizard;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Placeholder;
use Filament\Notifications\Notification;
use Carbon\Carbon;
use Illuminate\Support\Facades\Validator;

class EmailResource extends Resource
{
    use \App\Filament\Resources\Traits\HasPlatformCache;

    protected static ?string $model = Email::class;
    protected static ?string $title = "email";

    protected static ?string $icon = "";

    public static function getNavigationLabel(): string
    {
        return __('system.labels.email_address');
    }

    public static function getModelLabel(): string
    {
        return __('system.labels.email_address');
    }

    public static function getPluralModelLabel(): string
    {
        return __('system.labels.email_address_list');
    }
    // 🟢 DRY: Status labels dùng chung cho toàn bộ Resource
    public static function getStatusLabel(?string $state): string
    {
        if (!$state)
            return 'N/A';
        $state = strtolower($state);

        // Map 'live' to 'active' if needed, as per previous logic
        $key = ($state === 'live') ? 'active' : $state;

        return __("system.email_status.{$key}");
    }

    // Đổi sang icon phong bì cho Email
    protected static ?string $navigationIcon = 'heroicon-o-envelope';

    // Thêm dòng này để gom vào nhóm
    public static function getNavigationGroup(): ?string
    {
        return 'resource_hub';
    }

    // Thêm dòng này để Email luôn nằm trên Account
    protected static ?int $navigationSort = 1;

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();

        // Admin được xem toàn bộ hòm thư
        if (auth()->user()?->isAdmin() || auth()->user()?->isFinance()) {
            return $query;
        }

        // 🟢 TUYỆT CHIÊU: Staff chỉ xem được những Email có gắn với Account do chính họ quản lý
        return $query->whereHas('accounts', fn($q) => $q->where('user_id', auth()->id()));
    }

    // 🟢 KHÓA QUYỀN XÓA ĐỐI VỚI NHÂN VIÊN
    public static function canDelete(\Illuminate\Database\Eloquent\Model $record): bool
    {
        return (bool) auth()->user()?->isAdmin();
    }

    public static function canDeleteAny(): bool
    {
        return (bool) auth()->user()?->isAdmin();
    }

    // Thêm dòng này để hệ thống ghi nhớ bộ lọc vào Session
    public static function shouldPersistTableFiltersInSession(): bool
    {
        return true;
    }

    public static function form(Forms\Form $form): Forms\Form
    {
        return $form
            ->schema([
                // Gom nhóm vào Section để giao diện đồng bộ với bên Accounts
                Forms\Components\Section::make(__('system.email_detail_section'))
                    ->schema([
                        Forms\Components\TextInput::make('email')
                            ->label(__('system.labels.email_address'))
                            ->email()
                            ->required()
                            ->disabled(fn() => !auth()->user()?->isAdmin()) // 🟢 Khóa đối với nhân viên
                            ->unique(ignoreRecord: true) // Kiểm tra trùng lặp và báo lỗi thân thiện
                            ->validationMessages([
                                'unique' => __('validation.unique', ['attribute' => __('system.labels.email_address')]),
                            ]),

                        Forms\Components\TextInput::make('email_password')
                            ->label(__('system.labels.password'))
                            ->required(),

                        Forms\Components\TextInput::make('recovery_email')
                            ->label(__('system.labels.recovery_email'))
                            ->disabled(fn() => !auth()->user()?->isAdmin()) // 🟢 Khóa
                            ->email(),

                        Forms\Components\TextInput::make('two_factor_code')
                            ->label(__('system.labels.two_factor_code')) // Tạm giữ English vì là technical term
                            ->disabled(fn() => !auth()->user()?->isAdmin()), // 🟢 Khóa

                        Forms\Components\Select::make('status')
                            ->label(__('system.labels.status'))
                            ->options([
                                'active' => __('system.email_status.active'),
                                'disabled' => __('system.email_status.disabled'),
                                'locked' => __('system.email_status.locked'),
                            ])
                            ->default('active')
                            ->required()
                            ->disabled(fn() => !auth()->user()?->isAdmin()) // 🟢 Khóa
                            ->native(false),

                        Forms\Components\DatePicker::make('email_created_at')
                            ->label(__('system.labels.date_create')) // Tạm dùng hoặc thêm key riêng
                            ->placeholder(__('system.placeholders.date'))
                            ->displayFormat('d/m/Y') // Định dạng hiển thị khi nhập
                            ->format('Y-m-d') // Định dạng chuẩn để lưu vào MySQL
                            ->native(false) // Dùng giao diện hiện đại của Filament thay vì native
                            ->dehydrated(true) // Đảm bảo trường này được gửi về backend
                            ->disabled(fn() => !auth()->user()?->isAdmin()) // 🟢 Khóa
                            ->default(null) // Đảm bảo không tự động lấy ngày hôm nay làm mặc định
                            ->live(),   // Đồng bộ dữ liệu ngay lập tức

                        // ĐÂY LÀ CỘT NOTE BẠN VỪA YÊU CẦU
                        Forms\Components\Textarea::make('note')
                            ->label(__('system.labels.note'))
                            ->placeholder(__('system.placeholders.email_note_helper'))
                            ->columnSpanFull()

                    ])->columns(2), // Chia 2 cột cho cân đối
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table

            ->modifyQueryUsing(fn(Builder $query) => $query->with(['accounts'])) //Tối ưu hóa hiệu năng (Optimization)
            ->columns([
                Tables\Columns\TextColumn::make('id')
                    ->label(__('system.labels.id'))
                    ->alignment(Alignment::Center)
                    ->extraHeaderAttributes(['style' => 'width: 30px; min-width: 30px'])
                    ->extraAttributes(['style' => 'width: 30px; min-width: 30px'])
                    ->searchable() // Cho phép tìm kiếm theo ID
                    ->toggleable() // Mặc định ẩn, khi nào cần thì bật lên cho đỡ chật bảng (isToggledHiddenByDefault: true)
                    ->color('gray'),

                Tables\Columns\TextColumn::make('status')
                    ->label(__('system.labels.status'))
                    ->alignment(Alignment::Center)
                    //->extraHeaderAttributes(['style' => 'width: 50px; min-width: 50px'])
                    //->extraAttributes(['style' => 'width: 50px; min-width: 50px'])
                    ->searchable()
                    ->toggleable() // Cho phép ẩn/hiện cột này
                    ->formatStateUsing(fn(?string $state): string => static::getStatusLabel($state))
                    ->color(fn(?string $state): string => [
                        'active' => 'success',
                        'live' => 'success',
                        'disabled' => 'warning',
                        'locked' => 'danger',
                    ][strtolower($state ?? '')] ?? 'gray'),

                Tables\Columns\TextColumn::make('email')
                    ->label(__('system.labels.email_address'))
                    ->alignment(Alignment::Center)
                    ->searchable()
                    ->wrap()
                    ->extraHeaderAttributes(['style' => 'width: 230px; min-width: 230px'])
                    ->extraAttributes(['style' => 'width: 230px; min-width: 230px'])
                    ->copyable()
                    ->copyMessage(__('system.labels.email_copied'))
                    ->html()
                    ->formatStateUsing(function (Email $record): string {
                        $email = e($record->email);
                        $twoFA = e($record->two_factor_code ?? __('system.n/a'));
                        $note = e($record->note ?? __('system.n/a'));
                        $dateCreate = $record->email_created_at instanceof \Carbon\Carbon
                            ? $record->email_created_at->format('d/m/Y')
                            : ($record->email_created_at ? \Carbon\Carbon::parse($record->email_created_at)->format('d/m/Y') : __('system.n/a'));

                        return "
                            <div style='text-align: left; font-size: 13px; line-height: 1.6; min-width: 250px; white-space: normal; word-break: break-all;'>
                                <div style='margin-bottom: 2px; font-size: 14px;'>
                                    <span style='color: #1e293b; font-weight: 700;'>{$email}</span>
                                </div>

                                <div style='margin-top: 2px; overflow: hidden; text-overflow: ellipsis; white-space: wrap;'>
                                    <span style='color: #64748b'>" . __('system.labels.two_factor_code') . ": </span> 
                                    <span style='color: #1e293b;'>{$twoFA}</span>
                                </div>
                            
                                <div style='margin-top: 8px; padding-top: 4px; border-top: 1px solid #f1f5f9; line-height: 1.8;'>
                                    <div style='margin-top: 2px;'>
                                        <span style='color: #64748b'>" . __('system.labels.date_create') . ":</span> 
                                        <span style='color: #1e293b;'>{$dateCreate}</span>
                                    </div>

                                    <div style='margin-top: 2px; overflow: hidden; text-overflow: ellipsis; white-space: wrap;'>
                                        <span style='color: #64748b;'>" . __('system.labels.note') . ": </span> 
                                        <span style='color: #1e293b;'>{$note}</span>
                                    </div>
                                </div>
                            </div>
                        ";
                    }),

                Tables\Columns\TextColumn::make('email_password')
                    ->label(__('system.labels.password'))
                    ->alignment(Alignment::Center)
                    ->copyable()
                    ->searchable(),

                Tables\Columns\TextColumn::make('recovery_email')
                    ->label(__('system.labels.recovery_email'))
                    ->alignment(Alignment::Center)
                    ->extraHeaderAttributes(['style' => 'width: 240px; min-width: 240px'])
                    ->extraAttributes(['style' => 'width: 240px; min-width: 240px'])
                    ->copyable()
                    ->toggleable(), // Cho phép ẩn/hiện cột này

                //Hiển thị nhà cung cấp email nếu có (ví dụ: Gmail, Outlook)
                Tables\Columns\TextColumn::make('provider')
                    ->label(__('system.labels.provider'))
                    ->alignment(Alignment::Center)
                    ->toggleable()
                    // Tự động viết hoa chữ cái đầu (outlook -> Outlook)
                    ->formatStateUsing(fn(string $state): string => ucfirst($state)),


                // Cột thông minh: Hiển thị số lượng tài khoản đang dùng Email này
                Tables\Columns\TextColumn::make('accounts_count')
                    ->label(__('system.labels.usage'))
                    ->counts('accounts')
                    ->alignment(Alignment::Center)
                    ->formatStateUsing(fn($state) => $state > 0 ? "{$state}" : __('system.n/a'))
                    ->color(fn($state) => $state > 0 ? 'success' : 'secondary')
                    ->wrap()
                    ->toggleable(), // Cho phép ẩn/hiện cột này

                // Hiển thị các tài khoản đang dùng email này, nếu có
                Tables\Columns\TextColumn::make('accounts.platform')
                    ->label(__('system.labels.platform'))
                    ->placeholder(__('system.n/a')) // Nếu không có tài khoản nào đang dùng email này
                    ->alignment(Alignment::Center)
                    ->extraHeaderAttributes(['style' => 'width: 110px; min-width: 110px'])
                    ->extraAttributes(['style' => 'width: 110px; min-width: 110px'])
                    ->formatStateUsing(function ($state) {
                        if (!$state)
                            return 'N/A';
                        $platforms_map = \App\Models\Platform::pluck('name', 'slug')->toArray();
                        $items = is_array($state) ? $state : explode(', ', (string) $state);
                        return collect($items)->map(fn($s) => $platforms_map[$s] ?? $s)->implode(', ');
                    }) // Nếu có nhiều platform sẽ nối bằng dấu phẩy
                    ->toggleable(), // Cho phép ẩn/hiện cột này 
            ])

            ->persistFiltersInSession() // Ghi nhớ bộ lọc trong phiên làm việc
            ->filters([
                // 1. Bộ lọc theo Provider (Gmail, Outlook, Yahoo...)
                SelectFilter::make('provider')
                    ->label(__('system.labels.provider'))
                    ->options(function () {
                        // Lấy danh sách các provider duy nhất từ database
                        return \App\Models\Email::query()
                            ->whereNotNull('provider')
                            ->distinct()
                            ->pluck('provider') // Lấy mảng các giá trị provider
                            ->mapWithKeys(function ($item) {
                            // Viết hoa chữ cái đầu và giữ nguyên giá trị gốc làm key
                            return [$item => ucfirst($item)];
                        })
                            ->toArray();
                    })
                    ->searchable(), // Cho phép tìm nhanh nếu danh sách provider dài

                SelectFilter::make('email_created_at')
                    ->label(__('system.labels.year_created')) // Sử dụng tên tiếng Anh chuyên nghiệp
                    ->options(function () {
                        return \App\Models\Email::query()
                            ->whereNotNull('email_created_at')
                            ->selectRaw(match (\Illuminate\Support\Facades\DB::getDriverName()) {
                                'sqlite' => "strftime('%Y', email_created_at) as year",
                                default => "YEAR(email_created_at) as year",
                            })
                            ->distinct()
                            ->orderBy('year', 'desc')
                            ->pluck('year', 'year')
                            ->toArray();
                    })
                    ->query(function (Builder $query, array $data): Builder {
                        if (empty($data['value'])) {
                            return $query;
                        }
                        return $query->whereYear('email_created_at', $data['value']);
                    }),

                // 2. Bộ lọc theo Trạng thái (Live, Locked, Disabled)
                SelectFilter::make('status')
                    ->label(__('system.labels.status'))
                    ->options([
                        'active' => __('system.email_status.active'),
                        'disabled' => __('system.email_status.disabled'),
                        'locked' => __('system.email_status.locked'),
                    ]),
                Tables\Filters\TrashedFilter::make(), // 🟢 BẬT TÍNH NĂNG THÙNG RÁC
            ])

            // Giới hạn số cột hiển thị trên 1 hàng (ví dụ 4 cột cho 4 bộ lọc)
            ->filtersFormColumns(4)

            // HIỂN THỊ DÀN HÀNG NGANG TRÊN ĐẦU BẢNG
            ->filtersLayout(FiltersLayout::AboveContent)

            ->actions([
                Tables\Actions\ActionGroup::make([
                    // Hiển thị trực tiếp nút Copy
                    Tables\Actions\Action::make('copy_full_info')
                        ->label(__('system.actions.copy'))
                        ->icon('heroicon-m-clipboard-document-check')
                        ->action(function ($record, $livewire) {

                            //  Khai báo Header
                            $header = " | ID | Status | Year Create | Email Address | Email Password | Recovery Email | 2FA Code | Email Note | Provider | Usage | Platforms | ";
                            $id = $record->id;
                            $emailStatus = static::getStatusLabel($record->status);

                            // Lấy năm từ email_created_at của Email, nếu không có thì lấy N/A
                            $yearCreated = $record->email_created_at ? $record->email_created_at->format('Y') : __('system.n/a');
                            $email = $record->email ?? __('system.n/a');
                            $emailPass = $record->email_password ?? __('system.n/a');
                            $recovery = $record->recovery_email ?? __('system.n/a');
                            $twoFA = $record->two_factor_code ?? __('system.n/a');
                            $emailNote = $record->note ?? __('system.n/a'); // Khớp với trường 'note' trong DB
                            $provider = $record->provider ? ucfirst($record->provider) : 'Other'; // Hiển thị nhà cung cấp email nếu có, nếu không thì là 'Other'
                            $usage = $record->accounts_count > 0 ? "{$record->accounts_count}" : __('system.n/a');
                            $platforms_map = \App\Models\Platform::pluck('name', 'slug')->toArray();
                            $platforms = $record->accounts->pluck('platform')->map(fn($s) => $platforms_map[$s] ?? $s)->implode(', ') ?: __('system.n/a');

                            $singleLine = " | {$id} | {$emailStatus} | {$yearCreated} | {$email} | {$emailPass} | {$recovery} | {$twoFA} | {$emailNote} | {$provider} | {$usage} | {$platforms} | ";
                            $finalSingleLine = $header . "\n" . $singleLine; // Kết hợp header và data
                
                            $multiLine = "ID: {$id}\nStatus: {$emailStatus}\nYear Create: {$yearCreated}\nEmail Address: {$email}\nEmail Password: {$emailPass}\nRecovery Email: {$recovery}\n2FA: {$twoFA}\nEmail Note: {$emailNote}\nProvider: {$provider}\nUsage: {$usage}\nPlatforms: {$platforms}\n";

                            // Gộp cả 2 định dạng vào 1 lần copy
                            $info = $finalSingleLine . "\n\n" . $multiLine;

                            $livewire->dispatch('copy-to-clipboard', text: $info);

                            \Filament\Notifications\Notification::make()
                                ->title(__('system.actions.copied'))
                                ->success()
                                ->send();
                        }),
                    Tables\Actions\RestoreAction::make(), // 🟢 Nút khôi phục dòng bị xóa
                    Tables\Actions\EditAction::make(),
                    Tables\Actions\DeleteAction::make(),
                ])
            ])

            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    // 🟢 NÚT EXPORT EMAIL ĐƯỢC CHỌN
                    Tables\Actions\BulkAction::make('export_emails_to_sheet')
                        ->label(__('system.actions.export_to_sheet'))
                        ->icon('heroicon-o-table-cells')
                        ->color('success')
                        ->visible(fn() => auth()->user()?->isAdmin())
                        ->action(function (\Illuminate\Database\Eloquent\Collection $records, \App\Services\GoogleSyncService $syncService) {
                            try {
                                $result = $syncService->syncEmails($records);

                                \Filament\Notifications\Notification::make()
                                    ->title(__('system.notifications.sync_success'))
                                    ->body(__('system.notifications.sync_success_msg', ['count' => count($records)]))
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

                    // Reset Date Create Selected
                    Tables\Actions\BulkAction::make('clear_date_create')
                        ->label(__('system.actions.clear_date_create'))
                        ->icon('heroicon-o-trash')
                        ->color('danger')
                        ->visible(fn() => auth()->user()?->isAdmin()) // 🟢 KHÓA ĐỐI VỚI STAFF
                        ->requiresConfirmation() // Hỏi lại cho chắc chắn
                        ->action(fn(\Illuminate\Database\Eloquent\Collection $records) => $records->each->update(['email_created_at' => null])),

                    // Exported Seclected

                    // Copy Selected
                    Tables\Actions\BulkAction::make('copy_email_selected')
                        ->label(__('system.actions.copy_selected'))
                        ->icon('heroicon-m-clipboard-document-list')
                        ->color('warning')
                        ->action(function (\Illuminate\Database\Eloquent\Collection $records, $livewire) {
                            // 🟢 TỐI ƯU: Load Map 1 lần duy nhất ra bên ngoài Loop để tránh N+1 Query
                            $platforms_map = \App\Models\Platform::pluck('name', 'slug')->toArray();

                            // 1. Tạo hàng tiêu đề (Header)
                            $header = " | ID | Status | Year Create | Email Address | Email Password | Recovery Email | 2FA Code | Email Note | Provider | Usage | Platforms | ";
                            $output = $header . "\n";

                            foreach ($records as $index => $record) {
                                // Lấy dữ liệu từng dòng
                                $id = $record->id;
                                $emailStatus = static::getStatusLabel($record->status);

                                // Lấy năm từ email_created_at của Email, nếu không có thì lấy N/A
                                $yearCreated = $record->email_created_at ? $record->email_created_at->format('Y') : __('system.n/a');
                                $email = e($record->email); // Đảm bảo escape để tránh lỗi nếu email có ký tự đặc biệt
                                $pass = $record->email_password ?? __('system.n/a');
                                $recovery = $record->recovery_email ?? __('system.n/a');
                                $twoFA = e($record->two_factor_code ?? __('system.n/a')); // Escape 2FA code để tránh lỗi nếu có ký tự đặc biệt
                                $note = e($record->note ?? __('system.n/a')); // Đồng bộ đúng trường 'note'
                                $provider = $record->provider ? ucfirst($record->provider) : 'Other';
                                $usage = $record->accounts_count > 0 ? "{$record->accounts_count}" : __('system.n/a');

                                // Đã có map phía trên
                                $platforms = $record->accounts->pluck('platform')->map(fn($s) => $platforms_map[$s] ?? $s)->implode(', ') ?: __('system.n/a'); // Lấy danh sách platform đang dùng email này
                
                                // Định dạng thông tin cho từng email
                                $output .= " | {$id} | {$emailStatus} | {$yearCreated} | {$email} | {$pass} | {$recovery} | {$twoFA} | {$note} | {$provider} | {$usage} | {$platforms} | \n";
                            }

                            // Gửi lệnh copy tới trình duyệt
                            $livewire->dispatch('copy-to-clipboard', text: $output);

                            // Thông báo thành công
                            \Filament\Notifications\Notification::make()
                                ->title(__('system.actions.copied'))
                                ->success()
                                ->send();
                        })



                        ->deselectRecordsAfterCompletion(), // Tự động bỏ chọn sau khi copy xong
                    // 🟢 Khôi phục nhiều dòng
                    Tables\Actions\RestoreBulkAction::make()
                        ->visible(fn() => auth()->user()?->isAdmin()),
                    // Delete Selected    
                    Tables\Actions\DeleteBulkAction::make()
                        ->visible(fn() => auth()->user()?->isAdmin()),
                ])
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
            'index' => Pages\ListEmails::route('/'),
            'create' => Pages\CreateEmail::route('/create'),
            'edit' => Pages\EditEmail::route('/{record}/edit'),
        ];
    }

    // 🚀 EMAIL TXT IMPORT SPEC V1 LOGIC
    public static function getImportAction(): Tables\Actions\Action
    {
        return Tables\Actions\Action::make('import_txt')
            ->label('Import Email (TXT)')
            ->icon('heroicon-o-arrow-up-tray')
            ->color('info')
            ->visible(fn() => auth()->user()?->isAdmin())
            ->modalWidth('4xl')
            ->form([
                Wizard::make([
                    Wizard\Step::make('Data Input')
                        ->schema([
                            Textarea::make('raw_data')
                                ->label('Paste your TXT content here')
                                ->placeholder("email|password|recovery|2fa|date|note|status\nabc@gmail.com|pass123|recovery@gmail.com|ABCD EFGH|30-04-2026|gmail chính|live")
                                ->rows(15)
                                ->required()
                                ->helperText('Format: email|email_password|recovery_email|two_factor_code|create_date|note|status'),
                        ]),
                    Wizard\Step::make('Preview Summary')
                        ->schema([
                            Placeholder::make('preview_summary')
                                ->label('Summary')
                                ->content(function (Forms\Get $get) {
                                    $raw = $get('raw_data');
                                    if (!$raw) return 'No data provided.';

                                    $lines = explode("\n", str_replace("\r", "", trim($raw)));
                                    $summary = [
                                        'valid' => 0,
                                        'duplicate' => 0,
                                        'invalid_email' => 0,
                                        'invalid_date' => 0,
                                        'unknown_status' => 0,
                                        'too_many_cols' => 0,
                                        'no_pass' => 0,
                                    ];
                                    $errors = [];

                                    foreach ($lines as $i => $line) {
                                        $lineNo = $i + 1;
                                        if (empty(trim($line))) continue;

                                        $cols = explode('|', trim($line));
                                        if (count($cols) > 7) {
                                            $summary['too_many_cols']++;
                                            $errors[] = "Line {$lineNo}: too many columns, note cannot contain \"|\"";
                                            continue;
                                        }

                                        $email = strtolower(trim($cols[0] ?? ''));
                                        $pass = trim($cols[1] ?? '');
                                        $recovery = trim($cols[2] ?? '');
                                        $twoFactor = trim($cols[3] ?? '');
                                        $createDate = trim($cols[4] ?? '');
                                        $note = trim($cols[5] ?? '');
                                        $statusRaw = strtolower(trim($cols[6] ?? 'live'));

                                        // Validation rules
                                        if (empty($pass)) {
                                            $summary['no_pass']++;
                                            $errors[] = "Line {$lineNo}: email_password is required";
                                            continue;
                                        }

                                        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                                            $summary['invalid_email']++;
                                            $errors[] = "Line {$lineNo}: invalid email format";
                                            continue;
                                        }

                                        if (!empty($recovery) && !filter_var($recovery, FILTER_VALIDATE_EMAIL)) {
                                            $summary['invalid_email']++;
                                            $errors[] = "Line {$lineNo}: invalid recovery email";
                                            continue;
                                        }

                                        if (!empty($createDate)) {
                                            try {
                                                Carbon::createFromFormat('d-m-Y', $createDate);
                                            } catch (\Exception $e) {
                                                $summary['invalid_date']++;
                                                $errors[] = "Line {$lineNo}: create_date must be dd-mm-yyyy";
                                                continue;
                                            }
                                        }

                                        if (!in_array($statusRaw, ['live', 'disabled', 'locked'])) {
                                            $summary['unknown_status']++;
                                            $errors[] = "Line {$lineNo}: status \"{$statusRaw}\" is unknown";
                                            continue;
                                        }

                                        // Check duplicates
                                        if (Email::where('email', $email)->exists()) {
                                            $summary['duplicate']++;
                                        }

                                        $summary['valid']++;
                                    }

                                    $errorHtml = !empty($errors) ? "<div style='color: #ef4444; font-size: 13px; margin-top: 10px;'><strong>Errors:</strong><ul style='list-style: disc; margin-left: 20px;'>" . implode("", array_map(fn($e) => "<li>$e</li>", array_slice($errors, 0, 10))) . (count($errors) > 10 ? "<li>... and " . (count($errors) - 10) . " more errors</li>" : "") . "</ul></div>" : "";

                                    return new \Illuminate\Support\HtmlString("
                                        <div style='background: #f8fafc; padding: 15px; border-radius: 8px; border: 1px solid #e2e8f0;'>
                                            <div style='display: grid; grid-template-columns: repeat(3, 1fr); gap: 10px;'>
                                                <div style='color: #22c55e;'><strong>Valid:</strong> {$summary['valid']}</div>
                                                <div style='color: #f59e0b;'><strong>Duplicate:</strong> {$summary['duplicate']}</div>
                                                <div style='color: #ef4444;'><strong>Invalid Email:</strong> {$summary['invalid_email']}</div>
                                                <div style='color: #ef4444;'><strong>Invalid Date:</strong> {$summary['invalid_date']}</div>
                                                <div style='color: #ef4444;'><strong>Unknown Status:</strong> {$summary['unknown_status']}</div>
                                                <div style='color: #ef4444;'><strong>Column/Pass Errors:</strong> " . ($summary['too_many_cols'] + $summary['no_pass']) . "</div>
                                            </div>
                                            {$errorHtml}
                                        </div>
                                    ");
                                }),
                        ]),
                ])->submitAction(new \Illuminate\Support\HtmlString('<button type="submit" class="fi-btn fi-btn-size-md fi-btn-color-primary fi-ac-btn-action">Confirm Import</button>'))
            ])
            ->action(function (array $data) {
                $raw = $data['raw_data'];
                $lines = explode("\n", str_replace("\r", "", trim($raw)));

                $stats = [
                    'created' => 0,
                    'updated' => 0,
                    'skipped' => 0,
                    'failed' => 0,
                ];

                foreach ($lines as $line) {
                    if (empty(trim($line))) continue;

                    $cols = explode('|', trim($line));
                    if (count($cols) > 7) { $stats['failed']++; continue; }

                    $email = strtolower(trim($cols[0] ?? ''));
                    $pass = trim($cols[1] ?? '');
                    $recovery = trim($cols[2] ?? '');
                    $twoFactor = trim($cols[3] ?? '');
                    $createDateRaw = trim($cols[4] ?? '');
                    $note = trim($cols[5] ?? '');
                    $statusRaw = strtolower(trim($cols[6] ?? 'live'));

                    // Double validation
                    if (empty($pass) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                        $stats['failed']++;
                        continue;
                    }

                    // Mapping Status
                    $status = [
                        'live' => 'active',
                        'disabled' => 'disabled',
                        'locked' => 'locked',
                    ][$statusRaw] ?? 'active';

                    // Mapping Date
                    $emailCreatedAt = null;
                    if (!empty($createDateRaw)) {
                        try {
                            $emailCreatedAt = Carbon::createFromFormat('d-m-Y', $createDateRaw)->format('Y-m-d');
                        } catch (\Exception $e) {}
                    }

                    $existing = Email::where('email', $email)->first();

                    if ($existing) {
                        // Fill missing only mode
                        $updates = [];
                        if (empty($existing->email_password)) $updates['email_password'] = $pass;
                        if (empty($existing->recovery_email) && !empty($recovery)) $updates['recovery_email'] = $recovery;
                        if (empty($existing->two_factor_code) && !empty($twoFactor)) $updates['two_factor_code'] = $twoFactor;
                        if (empty($existing->email_created_at) && $emailCreatedAt) $updates['email_created_at'] = $emailCreatedAt;
                        if (empty($existing->note) && !empty($note)) $updates['note'] = $note;
                        // Status is rarely "empty" so we usually keep existing if it's already set to something else
                        // But if spec says "Fill missing only", and status defaults to active, we usually don't overwrite.
                        
                        if (!empty($updates)) {
                            $existing->update($updates);
                            $stats['updated']++;
                        } else {
                            $stats['skipped']++;
                        }
                    } else {
                        // Create new
                        Email::create([
                            'email' => $email,
                            'email_password' => $pass,
                            'recovery_email' => $recovery,
                            'two_factor_code' => $twoFactor,
                            'email_created_at' => $emailCreatedAt,
                            'note' => $note,
                            'status' => $status,
                        ]);
                        $stats['created']++;
                    }
                }

                Notification::make()
                    ->title('Import Completed')
                    ->body("Created: {$stats['created']}\nUpdated missing fields: {$stats['updated']}\nSkipped: {$stats['skipped']}\nFailed: {$stats['failed']}")
                    ->success()
                    ->persistent()
                    ->send();
            });
    }
}
