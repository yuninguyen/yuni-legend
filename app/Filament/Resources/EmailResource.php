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

class EmailResource extends Resource
{
    use \App\Filament\Resources\Traits\HasEmailSchema;

    protected static ?string $model = Email::class;

    // Đổi sang icon phong bì cho Email
    protected static ?string $navigationIcon = 'heroicon-o-envelope';

    // Thêm dòng này để gom vào nhóm
    protected static ?string $navigationGroup = 'RESOURCE HUB';

    // Thêm dòng này để Email luôn nằm trên Account
    protected static ?int $navigationSort = 1;

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
                Forms\Components\Section::make('Chi tiết Email gốc')
                    ->schema([
                        Forms\Components\TextInput::make('email')
                            ->label('Email Address')
                            ->email()
                            ->required()
                            ->unique(ignoreRecord: true) // Kiểm tra trùng lặp và báo lỗi thân thiện
                            ->validationMessages([
                                'unique' => 'This email already exists in the system.',
                            ]),

                        Forms\Components\TextInput::make('email_password')
                            ->label('Email Password')
                            ->required(),

                        Forms\Components\TextInput::make('recovery_email')
                            ->label('Recovery Email')
                            ->email(),

                        Forms\Components\TextInput::make('two_factor_code')
                            ->label('2FA Code')
                            ->placeholder('Nhập mã 2FA hoặc Recovery code...'),

                        Forms\Components\select::make('status')
                            ->label('Status')
                            ->formatStateUsing(fn(string $state): string => match ($state) {
                                'active' => 'Live',
                                'disabled' => 'Disabled',
                                'locked' => 'Locked',
                            })
                            ->default('active')
                            ->required()
                            ->native(false),

                        Forms\Components\DatePicker::make('email_created_at')
                            ->label('Date Created')
                            ->placeholder('Chọn ngày tạo account dd/mm/yyyy')
                            ->displayFormat('d/m/Y') // Định dạng hiển thị khi nhập
                            ->format('Y-m-d') // Định dạng chuẩn để lưu vào MySQL
                            ->native(true) // Dùng giao diện hiện đại của Filament
                            ->dehydrated(true) // Đảm bảo trường này được gửi về backend
                            ->default(null) // Đảm bảo không tự động lấy ngày hôm nay làm mặc định
                            ->live(),  // Đồng bộ dữ liệu ngay lập tức,

                        // ĐÂY LÀ CỘT NOTE BẠN VỪA YÊU CẦU
                        Forms\Components\Textarea::make('note')
                            ->label('Ghi chú Email')
                            ->placeholder('Lưu ý riêng cho email này...')
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
                    ->label('ID')
                    ->width('50px')
                    ->sortable() // Cho phép bấm vào tiêu đề để sắp xếp tăng/giảm
                    ->searchable() // Cho phép tìm kiếm theo ID
                    ->toggleable() // Mặc định ẩn, khi nào cần thì bật lên cho đỡ chật bảng (isToggledHiddenByDefault: true)
                    ->color('gray'),

                Tables\Columns\TextColumn::make('status')
                    ->label('Status')
                    ->alignment(Alignment::Center)
                    ->copyable()
                    ->searchable()
                    ->toggleable() // Cho phép ẩn/hiện cột này
                    ->formatStateUsing(fn(string $state): string => match ($state) {
                        'active' => 'Live',
                        'disabled' => 'Disabled',
                        'locked' => 'Locked',
                        default => 'N/A', // Hiện N/A nếu chưa check mail
                    })
                    ->color(fn(string $state): string => match ($state) {
                        'active' => 'success',
                        'disabled' => 'warning',
                        'locked' => 'danger',
                        default => 'gray',
                    })
                    ->width('50px'), // Cố định độ rộng cho cột Status

                Tables\Columns\TextColumn::make('email')
                    ->label('Email Address')
                    ->alignment(Alignment::Center)
                    ->searchable()
                    ->wrap()
                    ->width('200px')
                    ->copyable()
                    ->copyMessage('Copied email to clipboard!')
                    ->html()
                    ->formatStateUsing(function (Email $record): string {
                        $email = $record->email;
                        $twoFA = $record->two_factor_code ?? 'N/A'; // Nếu không có mã 2FA nào, hiển thị "N/A"
                        $note = $record->note ?? 'N/A'; // Đồng bộ đúng trường 'note'
                        $dateCreate = $record->email_created_at instanceof \Carbon\Carbon
                            ? $record->email_created_at->format('d/m/Y')
                            : ($record->email_created_at ? \Carbon\Carbon::parse($record->email_created_at)->format('d/m/Y') : 'N/A');

                        return "
                            <div style='text-align: left; font-size: 13px; line-height: 1.6; min-width: 250px; white-space: normal; word-break: break-all;'>
                                <div style='margin-bottom: 2px; font-size: 14px;'>
                                    <span style='color: #1e293b; font-weight: 700;'>{$email}</span>
                                </div>

                                <div style='margin-top: 2px; overflow: hidden; text-overflow: ellipsis; white-space: wrap;'>
                                    <span style='color: #64748b;'>2FA/Code: </span> 
                                    <span style='color: #1e293b;'>{$twoFA}</span>
                                </div>
                            
                                <div style='margin-top: 8px; padding-top: 4px; border-top: 1px solid #f1f5f9; line-height: 1.8;'>
                                    <div style='margin-top: 2px;'>
                                        <span style='color: #64748b;'>Date Create:</span> 
                                        <span style='color: #1e293b;'>{$dateCreate}</span>
                                    </div>

                                    <div style='margin-top: 2px; overflow: hidden; text-overflow: ellipsis; white-space: wrap;'>
                                        <span style='color: #64748b;'>Note: </span> 
                                        <span style='color: #1e293b;'>{$note}</span>
                                    </div>
                                </div>
                            </div>
                        ";
                    }),

                Tables\Columns\TextColumn::make('email_password')
                    ->label('Email Password')
                    ->alignment(Alignment::Center)
                    ->copyable()
                    ->searchable(),

                Tables\Columns\TextColumn::make('recovery_email')
                    ->label('Recovery Email')
                    ->alignment(Alignment::Center)
                    ->copyable()
                    ->toggleable(), // Cho phép ẩn/hiện cột này

                //Hiển thị nhà cung cấp email nếu có (ví dụ: Gmail, Outlook)
                Tables\Columns\TextColumn::make('provider')
                    ->label('Provider')
                    ->alignment(Alignment::Center)
                    ->copyable()
                    ->searchable()
                    ->toggleable()
                    // Tự động viết hoa chữ cái đầu (outlook -> Outlook)
                    ->formatStateUsing(fn(string $state): string => ucfirst($state)),


                // Cột thông minh: Hiển thị số lượng tài khoản đang dùng Email này
                Tables\Columns\TextColumn::make('accounts_count')
                    ->label('Usage')
                    ->counts('accounts')
                    ->alignment(Alignment::Center)
                    ->formatStateUsing(fn($state) => $state > 0 ? "{$state} Account(s)" : 'N/A')
                    ->color(fn($state) => $state > 0 ? 'success' : 'secondary')
                    ->wrap()
                    ->copyable()
                    ->searchable()
                    ->toggleable(), // Cho phép ẩn/hiện cột này

                // Hiển thị các tài khoản đang dùng email này, nếu có
                Tables\Columns\TextColumn::make('accounts.platform')
                    ->label('Platforms')
                    ->placeholder('N/A') // Nếu không có tài khoản nào đang dùng email này
                    ->alignment(Alignment::Center)
                    ->formatStateUsing(fn($state) => is_array($state) ? implode(', ', $state) : $state) // Nếu có nhiều platform sẽ nối bằng dấu phẩy
                    ->copyable()
                    ->searchable()
                    ->toggleable(), // Cho phép ẩn/hiện cột này 
            ])

            ->persistFiltersInSession() // Ghi nhớ bộ lọc trong phiên làm việc
            ->filters([
                // 1. Bộ lọc theo Provider (Gmail, Outlook, Yahoo...)
                SelectFilter::make('provider')
                    ->label('Provider')
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
                    ->searchable() // Cho phép tìm nhanh nếu danh sách provider dài
                    ->placeholder('All Provider'),

                SelectFilter::make('email_created_at')
                    ->label('Year') // Sử dụng tên tiếng Anh chuyên nghiệp
                    ->options(function () {
                        return \App\Models\Email::query()
                            ->whereNotNull('email_created_at')
                            ->selectRaw('YEAR(email_created_at) as year')
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
                    })
                    ->placeholder('All Years'), // Đổi placeholder cho đồng bộ

                // 2. Bộ lọc theo Trạng thái (Live, Locked, Disabled)
                SelectFilter::make('status')
                    ->label('Status')
                    ->placeholder('All Status')
                    ->options([
                        'active' => 'Live',
                        'disabled' => 'Disabled',
                        'locked' => 'Locked',
                    ]),
            ])

            // Giới hạn số cột hiển thị trên 1 hàng (ví dụ 4 cột cho 4 bộ lọc)
            ->filtersFormColumns(3)

            // HIỂN THỊ DÀN HÀNG NGANG TRÊN ĐẦU BẢNG
            ->filtersLayout(FiltersLayout::AboveContent)

            ->actions([
                Tables\Actions\ActionGroup::make([
                    Tables\Actions\EditAction::make()
                        ->label('Edit'),

                    // Hiển thị trực tiếp nút Copy
                    Tables\Actions\Action::make('copy_full_info')
                        ->label('Copy')
                        ->icon('heroicon-m-clipboard-document-check')
                        ->action(function ($record, $livewire) {

                            //  Khai báo Header
                            $header = " | ID | Status | Year Create | Email Address | Email Password | Recovery Email | 2FA Code | Email Note | Provider | Usage | Platforms | ";
                            $id = $record->id;
                            $emailStatus = $record->status ?? 'N/A'; // Lấy Status từ bảng Email
                            $emailstatusLabels = [
                                'active' => 'Live',
                                'disabled' => 'Disabled',
                                'locked'   => 'Locked',
                            ];
                            // Chuyển mảng status (nếu có nhiều status) thành chuỗi nhãn đẹp
                            $currentemailStatuses = is_array($record->status) ? $record->status : explode(',', (string)$record->status);

                            $emailStatus = collect($currentemailStatuses)
                                ->map(function ($s) use ($emailstatusLabels) {
                                    $key = trim($s); // Loại bỏ khoảng trắng thừa nếu có
                                    return $emailstatusLabels[$key] ?? ucfirst($key);
                                })
                                ->join(', '); // Kết quả: "Active, Locked" hoặc "Disabled"

                            // Lấy năm từ email_created_at của Email, nếu không có thì lấy N/A
                            $yearCreated = $record->email_created_at ? $record->email_created_at->format('Y') : 'N/A';
                            $email = $record->email ?? 'N/A';
                            $emailPass = $record->email_password ?? 'N/A';
                            $recovery = $record->recovery_email ?? 'N/A';
                            $twoFA = $record->two_factor_code ?? 'N/A';
                            $emailNote = $record->note ?? 'N/A'; // Khớp với trường 'note' trong DB
                            $provider = $record->provider ? ucfirst($record->provider) : 'Other'; // Hiển thị nhà cung cấp email nếu có, nếu không thì là 'Other'
                            $usage = $record->accounts_count > 0 ? "{$record->accounts_count} Account(s)" : 'N/A';
                            $platforms = $record->accounts->pluck('platform')->implode(', ') ?: 'N/A';

                            $singleLine = " | {$id} | {$emailStatus} | {$yearCreated} | {$email} | {$emailPass} | {$recovery} | {$twoFA} | {$emailNote} | {$provider} | {$usage} | {$platforms} | ";
                            $finalSingleLine = $header . "\n" . $singleLine; // Kết hợp header và data

                            $multiLine = "ID: {$id}\nStatus: {$emailStatus}\nYear Create: {$yearCreated}\nEmail Address: {$email}\nEmail Password: {$emailPass}\nRecovery Email: {$recovery}\n2FA: {$twoFA}\nEmail Note: {$emailNote}\nProvider: {$provider}\nUsage: {$usage}\nPlatforms: {$platforms}\n";

                            // Gộp cả 2 định dạng vào 1 lần copy
                            $info = $finalSingleLine . "\n\n" . $multiLine;

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
                    // 🟢 NÚT EXPORT EMAIL ĐƯỢC CHỌN
                    Tables\Actions\BulkAction::make('export_emails_to_sheet')
                        ->label('Export to Google Sheet')
                        ->icon('heroicon-o-table-cells')
                        ->color('success')
                        ->action(function (\Illuminate\Database\Eloquent\Collection $records) {
                            try {
                                $sheetService = app(\App\Services\GoogleSheetService::class);
                                $targetTab = 'Emails';

                                // 1. Đảm bảo Tab tồn tại
                                $sheetService->createSheetIfNotExist($targetTab);

                                // 2. Chuẩn bị dữ liệu (Sử dụng hàm format từ Trait HasEmailSchema)
                                $rows = $records->map(fn($record) => static::formatEmailForSheet($record))->toArray();

                                // 3. Upsert lên Sheet (Tự động nhận diện Header từ Trait)
                                $sheetService->upsertRows($rows, $targetTab, static::$emailHeaders);

                                // 4. ĐỊNH DẠNG SAU KHI SYNC
                                // Tìm cột Status để bôi màu
                                $statusIdx = array_search('Status', static::$emailHeaders);
                                $sheetService->applyFormattingWithRules($targetTab, $statusIdx, [
                                    'Live'     => ['red' => 0.85, 'green' => 0.95, 'blue' => 0.85],
                                    'Disabled' => ['red' => 1.0,  'green' => 0.8,  'blue' => 0.8],
                                    'Locked'   => ['red' => 0.9,  'green' => 0.4,  'blue' => 0.4],
                                ]);

                                // Clip các cột dài (Email, Pass, Platforms, Note)
                                $sheetService->formatColumnsAsClip($targetTab, 2, 3);
                                $sheetService->formatColumnsAsClip($targetTab, 9, 10);

                                \Filament\Notifications\Notification::make()
                                    ->title('Export Success!')
                                    ->body('Synced ' . count($records) . ' email(s) to Google Sheet.')
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
                        ->deselectRecordsAfterCompletion(), // Tự động bỏ tích sau khi xong
                    // Reset Date Create Selected
                    Tables\Actions\BulkAction::make('clear_date_create')
                        ->label('Clear Date Create Selected')
                        ->icon('heroicon-o-trash')
                        ->color('danger')
                        ->requiresConfirmation() // Hỏi lại cho chắc chắn
                        ->action(fn(\Illuminate\Database\Eloquent\Collection $records) => $records->each->update(['email_created_at' => null])),

                    // Exported Seclected
                    \Filament\Tables\Actions\ExportBulkAction::make()
                        ->exporter(\App\Filament\Exports\EmailExporter::class)
                        ->label('Export Selected')
                        ->icon('heroicon-m-arrow-down-tray')
                        ->color('success')
                        ->deselectRecordsAfterCompletion(),

                    // Copy Selected
                    Tables\Actions\BulkAction::make('copy_email_selected')
                        ->label('Copy Selected')
                        ->icon('heroicon-m-clipboard-document-list')
                        ->color('warning')
                        ->action(function (\Illuminate\Database\Eloquent\Collection $records, $livewire) {
                            // 1. Tạo hàng tiêu đề (Header)
                            $header = " | ID | Status | Year Create | Email Address | Email Password | Recovery Email | 2FA Code | Email Note | Provider | Usage | Platforms | ";
                            $output = $header . "\n";

                            foreach ($records as $index => $record) {
                                // Lấy dữ liệu từng dòng
                                $id = $record->id;
                                $emailStatus = $record->status ?? 'N/A'; // Lấy Status từ bảng Email
                                $emailstatusLabels = [
                                    'active' => 'Live',
                                    'disabled' => 'Disabled',
                                    'locked'   => 'Locked',
                                ];
                                // Chuyển mảng status (nếu có nhiều status) thành chuỗi nhãn đẹp
                                $currentStatuses = is_array($record->status) ? $record->status : explode(',', (string)$record->status);

                                $emailStatus = collect($currentStatuses)
                                    ->map(function ($s) use ($emailstatusLabels) {
                                        $key = trim($s); // Loại bỏ khoảng trắng thừa nếu có
                                        return $emailstatusLabels[$key] ?? ucfirst($key);
                                    })
                                    ->join(', '); // Kết quả: "Active, Locked" hoặc "Disabled"

                                // Lấy năm từ email_created_at của Email, nếu không có thì lấy N/A
                                $yearCreated = $record->email_created_at ? $record->email_created_at->format('Y') : 'N/A';
                                $email = $record->email ?? 'N/A';
                                $pass = $record->email_password ?? 'N/A';
                                $recovery = $record->recovery_email ?? 'N/A';
                                $twoFA = $record->two_factor_code ?? 'N/A';
                                $note = $record->note ?? 'N/A';
                                $provider = $record->provider ? ucfirst($record->provider) : 'Other';
                                $usage = $record->accounts_count > 0 ? "{$record->accounts_count} Account(s)" : 'N/A';
                                $platforms = $record->accounts->pluck('platform')->implode(', ') ?: 'N/A'; // Lấy danh sách platform đang dùng email này

                                // Định dạng thông tin cho từng email
                                $output .= " | {$id} | {$emailStatus} | {$yearCreated} | {$email} | {$pass} | {$recovery} | {$twoFA} | {$note} | {$provider} | {$usage} | {$platforms} | \n";
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

                    // Delete Selected    
                    Tables\Actions\DeleteBulkAction::make(),
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
}
