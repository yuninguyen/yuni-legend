<?php

namespace App\Filament\Resources;

use App\Filament\Resources\UserPaymentResource\Pages;
use App\Filament\Resources\UserPaymentResource\RelationManagers;
use App\Models\UserPayment;
use App\Models\User;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Filament\Support\Enums\Alignment;
use Filament\Support\Enums\IconPosition;
use Illuminate\Database\Eloquent\Model;
use App\Filament\Resources\Traits\HasPlatform;
use Filament\Tables\Enums\FiltersLayout;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Facades\Blade;


class UserPaymentResource extends Resource
{
    protected static ?string $model = UserPayment::class;
    protected static ?string $slug = 'disbursement';

    protected static ?string $navigationIcon = 'heroicon-o-rectangle-stack';
    // protected static ?string $navigationGroup = 'Wallet & Payouts'; // Filament sẽ lấy text này so khớp với AdminPanelProvider đã localize
    // protected static ?int $navigationSort = 3;

    public static function getNavigationGroup(): ?string
    {
        return 'wallet_payout';
    }
    public static function getNavigationSort(): int
    {
        return 3;
    }

    public static function getNavigationLabel(): string
    {
        return __('system.labels.revenue_split');
    }

    public static function getModelLabel(): string
    {
        return __('system.labels.revenue_split');
    }

    public static function getPluralModelLabel(): string
    {
        return __('system.labels.revenue_split_list');
    }

    // 🟢 1. PHÂN QUYỀN DỮ LIỆU: Staff chỉ thấy lương của mình
    public static function getEloquentQuery(): Builder
    {
        // 🟢 FIX TRẬN ĐÁNH ID: Explicitly select user_payments.* để tránh bị bảng Users ghi đè ID
        $query = parent::getEloquentQuery()
            ->with(['payoutLogs.account.email'])
            ->leftJoin('users', 'user_payments.user_id', '=', 'users.id')
            ->select('user_payments.*')
            // 🟢 FIX SQL ALIAS: Dùng selectRaw để chỉ định rõ tên cột cho Grouping/Ordering hoạt động được
            ->selectRaw(match (\Illuminate\Support\Facades\DB::getDriverName()) {
                'sqlite' => "COALESCE(users.name, 'Unknown User') || ' | Batch: ' || COALESCE(user_payments.batch_id, 'N/A') as user_batch_label",
                default => "CONCAT(COALESCE(users.name, 'Unknown User'), ' | Batch: ', COALESCE(user_payments.batch_id, 'N/A')) as user_batch_label",
            })
            ->selectRaw("COALESCE(user_payments.batch_id, 'no_batch') as batch_label")
            ->reorder()
            ->orderByRaw('user_payments.status = "pending" DESC')
            ->orderBy('user_payments.batch_id', 'desc')
            ->orderBy('user_payments.created_at', 'desc');

        // 🟢 BẢO MẬT: Nếu không phải Admin/Finance thì chỉ thấy của mình
        if (!auth()->user()?->isAdmin() && !auth()->user()?->isFinance()) {
            $query->where('user_payments.user_id', auth()->id());
        }

        return $query;
    }

    // 🟢 2. KHÓA CHẶT QUYỀN: Staff không được sửa/xóa/tạo
    public static function canCreate(): bool
    {
        return false; // Không ai được tạo tay (Tạo tự động từ lúc chốt sổ rồi)
    }

    public static function canEdit(Model $record): bool
    {
        return auth()->user()?->isAdmin() || auth()->user()?->isFinance(); // Admin & Finance được sửa (để up bill)
    }

    public static function canDelete(Model $record): bool
    {
        return auth()->user()?->isAdmin() || auth()->user()?->isFinance();
    }

    // 🟢 3. FORM GIAO DIỆN (Dành cho Admin Up Bill)
    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make(__('system.labels.transaction_details'))
                    ->schema([
                        Forms\Components\Select::make('status')
                            ->label(__('system.labels.status'))
                            ->options([
                                'pending' => '⏳ ' . __('system.status.pending'),
                                'paid' => '✅ ' . __('system.status.completed'),
                            ])
                            ->required()
                            ->native(false),

                        Forms\Components\DatePicker::make('payment_date')
                            ->label(__('system.labels.payment_date'))
                            ->native(false),

                        Forms\Components\FileUpload::make('payment_proof')
                            ->label(__('system.user_payments.fields.payment_proof'))
                            ->image()
                            ->directory('payment-proofs')
                            ->openable()
                            ->downloadable(),

                        Forms\Components\Textarea::make('note')
                            ->label(__('system.labels.note'))
                            ->columnSpanFull(),
                    ])->columns(2),
            ]);
    }

    // 🟢 4. BẢNG HIỂN THỊ (Giao diện xem tiền)
    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('transaction_type')
                    ->label(__('system.labels.description'))
                    ->formatStateUsing(function ($record, $state) {
                        return str_replace(['(', ')'], ['- ', ''], $state);
                    })
                    ->description(function ($record) {
                        // 🟢 DÒNG 2: Danh sách email account
                        $emails = $record->payoutLogs->map(fn($log) => $log->account?->email?->email)->filter()->unique();
                        $emailList = $emails->isNotEmpty() ? e($emails->implode(', ')) : __('system.n/a');
                        $accLine = "📧 Account: {$emailList}";

                        // 🟢 DÒNG 3: Tỉ giá (Market rate & Payout rate)
                        $rateLine = '';
                        if (auth()->user()?->isAdmin() || auth()->user()?->isFinance()) {
                            $rateLine = "📈 " . __('system.payout_logs.fields.market_rate') . ': ' . number_format($record->exchange_rate) . ' | ' . __('system.payout_logs.fields.payout_rate') . ': ' . number_format($record->payout_rate) . ' (' . ($record->payout_percentage ?? 100) . '%)';
                        } else {
                            $rateLine = "📈 " . __('system.labels.exchange_rate') . ': ' . number_format($record->payout_rate);
                        }

                        // Trả về HTML với thẻ <br> để cưỡng chế xuống dòng (Dòng 2 & Dòng 3)
                        return new \Illuminate\Support\HtmlString("{$accLine}<br>{$rateLine}");
                    })
                    ->searchable()
                    ->alignment(Alignment::Center), // Chỉnh sát lề trái để dễ đọc hơn khi nhiều dòng

                Tables\Columns\TextColumn::make('total_usd')
                    ->label(__('system.labels.rebate_amount_usd'))
                    ->money('USD') // Tự format có chữ $
                    ->color('success')
                    ->alignment(Alignment::Center)
                    ->summarize(Tables\Columns\Summarizers\Sum::make()->label('Total')->money('USD')),

                Tables\Columns\TextColumn::make('total_vnd')
                    ->label(__('system.labels.total_vnd'))
                    ->money('VND', locale: 'vi_VN') // Tự format 25.000.000 ₫
                    ->color('primary')
                    ->weight('bold')
                    ->alignment(Alignment::Center)
                    ->summarize(
                        Tables\Columns\Summarizers\Sum::make()
                            ->label('Total')
                            ->money('VND', locale: 'vi_VN')
                    ),


                Tables\Columns\TextColumn::make('status')
                    ->label(__('system.labels.status'))
                    ->alignment(Alignment::Center)
                    ->badge()
                    ->color(fn(string $state): string => match ($state) {
                        'pending' => 'warning',
                        'paid' => 'success',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn(string $state) => new \Illuminate\Support\HtmlString('
                        <div class="flex items-center gap-1.5 justify-center">
                            <span>' . __('system.status.' . ($state === 'paid' ? 'completed' : $state)) . '</span>
                                ' . \Illuminate\Support\Facades\Blade::render('<x-heroicon-m-pencil-square class="w-4 h-4 text-gray-400" />') . '
                         </div>
                    '))
                    ->action(
                        Tables\Actions\Action::make('quick_set_status')
                            ->label(__('system.labels.quick_set_status'))
                            ->modalHeading(__('system.labels.quick_set_status'))
                            ->modalSubmitActionLabel('Gửi')
                            ->modalCancelActionLabel('Hủy bỏ')
                            ->form([
                                Forms\Components\Select::make('status')
                                    ->label(__('system.labels.status'))
                                    ->options([
                                        'pending' => __('system.status.pending'),
                                        'paid' => __('system.status.completed'),
                                    ])
                                    ->default(fn($record) => $record->status)
                                    ->required(),
                            ])
                            ->action(function ($record, array $data) {
                                $record->update($data);

                                \Filament\Notifications\Notification::make()
                                    ->title(__('system.notifications.status_updated_sync'))
                                    ->success()
                                    ->send();
                            })
                    ),

                Tables\Columns\TextColumn::make('payment_date')
                    ->label(__('system.labels.payment_date'))
                    ->state(fn($record) => $record->payment_date ? $record->payment_date : 'N/A')
                    ->formatStateUsing(function ($state) {
                        if ($state === 'N/A')
                            return __('system.n/a');
                        return \Carbon\Carbon::parse($state)->format('d/m/Y');
                    })
                    ->alignment(Alignment::Center)
                    ->icon('heroicon-m-pencil-square')
                    ->iconPosition(IconPosition::After)
                    ->iconColor('gray')
                    ->action(
                        Tables\Actions\Action::make('quick_set_payment_date')
                            ->label(__('system.labels.quick_set_date'))
                            ->modalHeading(__('system.labels.quick_set_date'))
                            ->modalSubmitActionLabel('Gửi')
                            ->modalCancelActionLabel('Hủy bỏ')
                            ->form([
                                Forms\Components\DatePicker::make('payment_date')
                                    ->label(__('system.labels.payment_date'))
                                    ->default(fn($record) => $record->payment_date ?? now())
                                    ->required(),
                            ])
                            ->action(function ($record, array $data) {
                                $record->update($data);

                                \Filament\Notifications\Notification::make()
                                    ->title(__('system.notifications.date_updated_sync'))
                                    ->success()
                                    ->send();
                            })
                    ),
            ])
            ->groups([
                Tables\Grouping\Group::make('user_batch_label')
                    ->label(__('system.labels.user_batch'))
                    ->collapsible()
                    ->getTitleFromRecordUsing(fn($record) => $record->user_batch_label)
                    ->getKeyFromRecordUsing(fn($record) => $record->user_id . '_' . ($record->batch_id ?? 'N/A'))
                    ->scopeQueryByKeyUsing(function ($query, $key) {
                        // 🟢 FIX DRILL-DOWN: Key is "UserID_BatchID"
                        if (str_contains($key, '_')) {
                            $parts = explode('_', $key, 2);
                            $userId = $parts[0] ?? null;
                            $batchId = $parts[1] ?? 'N/A';

                            return $query->where('user_payments.user_id', $userId)
                                ->when($batchId === 'N/A', fn($q) => $q->whereNull('user_payments.batch_id'), fn($q) => $q->where('user_payments.batch_id', $batchId));
                        }
                        return $query;
                    }),
            ])
            ->defaultGroup('user_batch_label')
            ->filters([
                // 1. Lọc theo Platform (Sàn) — CHỈ HIỆN SÀN CÓ DỮ LIỆU THỰC TẾ
                Tables\Filters\SelectFilter::make('platform')
                    ->label(__('system.labels.platform'))
                    ->options(fn() => UserPayment::query()->distinct()->whereNotNull('platform')->pluck('platform', 'platform'))
                    ->multiple(),

                // 2. Lọc theo User (Nhân viên) — CHỈ HIỆN USER CÓ DỮ LIỆU THỰC TẾ
                Tables\Filters\SelectFilter::make('user_id')
                    ->label(__('system.labels.user'))
                    ->options(fn() => User::whereIn('id', UserPayment::query()->distinct()->pluck('user_id'))->pluck('name', 'id'))
                    ->searchable()
                    ->preload()
                    ->visible(fn() => auth()->user()?->isAdmin() || auth()->user()?->isFinance()),

                // 3. Lọc theo Trạng thái
                Tables\Filters\SelectFilter::make('status')
                    ->label(__('system.labels.status'))
                    ->options([
                        'pending' => __('system.status.pending'),
                        'paid' => __('system.status.completed'),
                    ]),

                // 4. Lọc TỪ NGÀY
                Tables\Filters\Filter::make('created_from')
                    ->form([
                        Forms\Components\DatePicker::make('from')
                            ->label(__('system.labels.payment_date') . ' - ' . __('system.labels.from')),
                    ])
                    ->query(fn(Builder $query, array $data): Builder => $query->when($data['from'], fn(Builder $query, $date): Builder => $query->whereDate('created_at', '>=', $date)))
                    ->indicateUsing(fn(array $data): array => ($data['from'] ?? null) ? ['From ' . \Carbon\Carbon::parse($data['from'])->format('d/m/Y')] : []),

                // 5. Lọc ĐẾN NGÀY
                Tables\Filters\Filter::make('created_until')
                    ->form([
                        Forms\Components\DatePicker::make('until')
                            ->label(__('system.labels.payment_date') . ' - ' . __('system.labels.until')),
                    ])
                    ->query(fn(Builder $query, array $data): Builder => $query->when($data['until'], fn(Builder $query, $date): Builder => $query->whereDate('created_at', '<=', $date)))
                    ->indicateUsing(fn(array $data): array => ($data['until'] ?? null) ? ['Until ' . \Carbon\Carbon::parse($data['until'])->format('d/m/Y')] : []),
            ])
            ->filtersFormColumns(auth()->user()?->isAdmin() || auth()->user()?->isFinance() ? 5 : 4)
            ->filtersLayout(FiltersLayout::AboveContent)
            ->actions([
                Tables\Actions\EditAction::make()
                    ->label(fn() => (auth()->user()?->isAdmin() || auth()->user()?->isFinance()) ? __('system.user_payments.actions.up_bill') : __('system.user_payments.actions.view_bill')),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    // 🟢 1. GOM NHÓM (BATCHING)
                    Tables\Actions\BulkAction::make('batchSelected')
                        ->label(__('system.user_payments.actions.batch_selected'))
                        ->icon('heroicon-o-rectangle-group')
                        ->color('info')
                        ->form([
                            Forms\Components\TextInput::make('batch_id')
                                ->label(__('system.labels.batch_name'))
                                ->placeholder('e.g. Weekly Payout - Week 14')
                                ->required(),
                        ])
                        ->action(function (\Illuminate\Database\Eloquent\Collection $records, array $data): void {
                            \Illuminate\Support\Facades\DB::transaction(function () use ($records, $data) {
                                $records->each->update(['batch_id' => $data['batch_id']]);
                            });
                        })
                        ->deselectRecordsAfterCompletion()
                        ->visible(fn() => auth()->user()?->isAdmin() || auth()->user()?->isFinance()),

                    // 🟢 2. GỠ NHÓM (UNBATCH)
                    Tables\Actions\BulkAction::make('unbatchSelected')
                        ->label(__('system.user_payments.actions.unbatch_selected'))
                        ->icon('heroicon-o-x-circle')
                        ->color('warning')
                        ->requiresConfirmation()
                        ->action(function (\Illuminate\Database\Eloquent\Collection $records): void {
                            \Illuminate\Support\Facades\DB::transaction(function () use ($records) {
                                $records->each->update(['batch_id' => null]);
                            });
                        })
                        ->deselectRecordsAfterCompletion()
                        ->visible(fn() => auth()->user()?->isAdmin() || auth()->user()?->isFinance()),

                    // 🟢 3. THANH TOÁN CẢ LÔ (PAY BATCH)
                    Tables\Actions\BulkAction::make('payOutBatch')
                        ->label(__('system.user_payments.actions.pay_out_batch'))
                        ->icon('heroicon-o-check-circle')
                        ->color('success')
                        ->form([
                            Forms\Components\FileUpload::make('payment_proof')
                                ->label(__('system.user_payments.fields.payment_proof'))
                                ->image()
                                ->directory('payment-proofs')
                                ->openable()
                                ->downloadable(),
                            Forms\Components\Textarea::make('note')
                                ->label(__('system.labels.note_for_all')),
                        ])
                        ->action(function (\Illuminate\Database\Eloquent\Collection $records, array $data): void {
                            \Illuminate\Support\Facades\DB::transaction(function () use ($records, $data) {
                                $records->each->update([
                                    'status' => 'paid',
                                    'payment_proof' => $data['payment_proof'] ?? null,
                                    'note' => $data['note'] ?? null,
                                ]);
                            });
                        })
                        ->deselectRecordsAfterCompletion()
                        ->visible(fn() => auth()->user()?->isAdmin() || auth()->user()?->isFinance()),

                    Tables\Actions\DeleteBulkAction::make()
                        ->visible(fn() => auth()->user()?->isAdmin() || auth()->user()?->isFinance()),
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
            'index' => Pages\ListUserPayments::route('/'),
            //'create' => Pages\CreateUserPayment::route('/create'), // Bỏ trang Create đi vì mình không tạo bằng tay
            'edit' => Pages\EditUserPayment::route('/{record}/edit'),
        ];
    }
}
