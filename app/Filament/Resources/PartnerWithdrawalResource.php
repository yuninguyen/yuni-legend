<?php

namespace App\Filament\Resources;

use App\Filament\Resources\PartnerWithdrawalResource\Pages;
use App\Models\PartnerWithdrawal;
use App\Models\PayoutLog;
use App\Models\PayoutMethod;
use App\Models\User;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Infolists\Components\Grid;
use Filament\Infolists\Components\Tabs;
use Filament\Infolists\Components\Tabs\Tab;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Infolist;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Support\Enums\Alignment;
use Filament\Tables;
use Filament\Tables\Actions\ActionGroup;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\HtmlString;

class PartnerWithdrawalResource extends Resource
{
    protected static ?string $model = PartnerWithdrawal::class;

    protected static ?string $navigationIcon = 'heroicon-o-arrow-up-tray';

    protected static ?int $navigationSort = 10;

    public static function getNavigationGroup(): ?string
    {
        return 'wallet_payout';
    }

    public static function getNavigationLabel(): string
    {
        return __('system.partner_withdrawals.nav_label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('system.partner_withdrawals.plural_model_label');
    }

    public static function getModelLabel(): string
    {
        return __('system.partner_withdrawals.model_label');
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();

        if (auth()->user()?->isPartner()) {
            $query->where('partner_id', auth()->id());
        }

        return $query;
    }

    public static function shouldRegisterNavigation(): bool
    {
        $user = auth()->user();

        return $user && in_array($user->role, ['admin', 'finance', 'partner']);
    }

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user && in_array($user->role, ['admin', 'finance', 'partner']);
    }

    public static function canCreate(): bool
    {
        $user = auth()->user();

        return $user && in_array($user->role, ['admin', 'finance', 'partner']);
    }

    public static function canEdit(Model $record): bool
    {
        $user = auth()->user();
        if (! $user) {
            return false;
        }
        if ($user->isPartner()) {
            return $record->partner_id === $user->id && $record->status === 'pending';
        }

        return in_array($user->role, ['admin', 'finance']);
    }

    public static function canDelete(Model $record): bool
    {
        return auth()->user()?->isAdmin() ?? false;
    }

    // =========================================================================
    // FORM
    // =========================================================================
    public static function form(Form $form): Form
    {
        $isPartner = auth()->user()?->isPartner();

        return $form->schema([

            Forms\Components\Section::make(__('system.partner_withdrawals.section_partner'))
                ->schema([
                    Forms\Components\Select::make('partner_id')
                        ->label(__('system.partner_withdrawals.partner'))
                        ->relationship('partner', 'name')
                        ->searchable()
                        ->preload()
                        ->required()
                        ->default(fn () => auth()->id())
                        ->disabled($isPartner)
                        ->dehydrated(),

                    Forms\Components\Select::make('assigned_to')
                        ->label(__('system.partner_withdrawals.assigned_to'))
                        ->options(fn () => User::where('role', 'finance')->pluck('name', 'id'))
                        ->searchable()
                        ->nullable()
                        ->native(false)
                        ->hidden($isPartner),
                ])
                ->columns(2),

            Forms\Components\Section::make(__('system.partner_withdrawals.section_platform'))
                ->schema([
                    Forms\Components\TextInput::make('platform')
                        ->label(__('system.partner_withdrawals.platform'))
                        ->placeholder(__('system.partner_withdrawals.platform_placeholder'))
                        ->required()
                        ->maxLength(255),

                    Forms\Components\TextInput::make('platform_password')
                        ->label(__('system.partner_withdrawals.platform_password'))
                        ->password()
                        ->revealable()
                        ->maxLength(500),
                ])
                ->columns(2),

            Forms\Components\Section::make(__('system.partner_withdrawals.section_email'))
                ->schema([
                    Forms\Components\TextInput::make('email')
                        ->label(__('system.partner_withdrawals.email'))
                        ->email()
                        ->required()
                        ->maxLength(255),

                    Forms\Components\TextInput::make('email_password')
                        ->label(__('system.partner_withdrawals.email_password'))
                        ->password()
                        ->revealable()
                        ->maxLength(500),

                    Forms\Components\TextInput::make('recovery_email')
                        ->label(__('system.partner_withdrawals.recovery_email'))
                        ->email()
                        ->nullable()
                        ->maxLength(255),

                    Forms\Components\TextInput::make('two_fa')
                        ->label(__('system.partner_withdrawals.two_fa'))
                        ->password()
                        ->revealable()
                        ->nullable()
                        ->maxLength(500),
                ])
                ->columns(2),

            Forms\Components\Section::make(__('system.partner_withdrawals.section_financial'))
                ->schema([
                    Forms\Components\TextInput::make('amount_usd')
                        ->label(__('system.partner_withdrawals.amount_usd'))
                        ->numeric()
                        ->prefix('$')
                        ->required()
                        ->minValue(0),

                    Forms\Components\Select::make('status')
                        ->label(__('system.partner_withdrawals.status'))
                        ->options(function () {
                            $user = auth()->user();
                            if ($user?->isPartner()) {
                                return ['new' => __('system.partner_withdrawals.status_new')];
                            }

                            return [
                                'new' => __('system.partner_withdrawals.status_new'),
                                'pending' => __('system.partner_withdrawals.status_pending'),
                                'processing' => __('system.partner_withdrawals.status_processing'),
                                'completed' => __('system.partner_withdrawals.status_completed'),
                                'wrong_pass' => __('system.partner_withdrawals.status_wrong_pass'),
                                'banned' => __('system.partner_withdrawals.status_banned'),
                            ];
                        })
                        ->default('new')
                        ->required()
                        ->native(false)
                        ->disabled($isPartner),

                    Forms\Components\Textarea::make('note')
                        ->label(__('system.partner_withdrawals.note'))
                        ->nullable()
                        ->columnSpanFull(),
                ])
                ->columns(2),
        ]);
    }

    // =========================================================================
    // TABLE
    // =========================================================================
    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist
            ->schema([
                Tabs::make('Tabs')
                    ->tabs([
                        Tab::make(__('system.partner_withdrawals.section_partner'))
                            ->icon('heroicon-m-user-circle')
                            ->schema([
                                Grid::make(3)->schema([
                                    TextEntry::make('partner.name')
                                        ->label(__('system.partner_withdrawals.partner')),
                                    TextEntry::make('platform')
                                        ->label(__('system.partner_withdrawals.platform')),
                                    TextEntry::make('email')
                                        ->label(__('system.partner_withdrawals.email'))
                                        ->copyable(),
                                ]),
                                Grid::make(2)->schema([
                                    TextEntry::make('platform_password')
                                        ->label(__('system.partner_withdrawals.platform_password'))
                                        ->placeholder('N/A')
                                        ->copyable(),
                                    TextEntry::make('email_password')
                                        ->label(__('system.partner_withdrawals.email_password'))
                                        ->placeholder('N/A')
                                        ->copyable(),
                                    TextEntry::make('two_fa')
                                        ->label(__('system.partner_withdrawals.two_factor_auth'))
                                        ->placeholder('N/A')
                                        ->copyable(),
                                ]),
                            ]),

                        Tab::make(__('system.partner_withdrawals.section_request'))
                            ->icon('heroicon-m-banknotes')
                            ->schema([
                                Grid::make(3)->schema([
                                    TextEntry::make('amount_usd')
                                        ->label(__('system.partner_withdrawals.amount_usd'))
                                        ->money('USD'),
                                    TextEntry::make('status')
                                        ->label(__('system.partner_withdrawals.status'))
                                        ->badge()
                                        ->icon(fn (string $state): string => match ($state) {
                                            'new' => 'heroicon-m-sparkles',
                                            'pending' => 'heroicon-m-clock',
                                            'processing' => 'heroicon-m-arrow-path',
                                            'completed' => 'heroicon-m-check-badge',
                                            'wrong_pass' => 'heroicon-m-x-circle',
                                            'banned' => 'heroicon-m-no-symbol',
                                            default => 'heroicon-m-question-mark-circle',
                                        })
                                        ->color(fn (string $state): string => match ($state) {
                                            'new' => 'gray',
                                            'pending' => 'warning',
                                            'processing' => 'info',
                                            'completed' => 'success',
                                            'wrong_pass', 'banned' => 'danger',
                                            default => 'secondary',
                                        })
                                        ->formatStateUsing(fn (string $state): string => match ($state) {
                                            'new' => __('system.partner_withdrawals.status_new'),
                                            'pending' => __('system.partner_withdrawals.status_pending'),
                                            'processing' => __('system.partner_withdrawals.status_processing'),
                                            'completed' => __('system.partner_withdrawals.status_completed'),
                                            'wrong_pass' => __('system.partner_withdrawals.status_wrong_pass'),
                                            'banned' => __('system.partner_withdrawals.status_banned'),
                                            default => $state,
                                        }),
                                    TextEntry::make('created_at')
                                        ->label(__('system.partner_withdrawals.created_at'))
                                        ->dateTime('d/m/Y H:i'),
                                ]),
                            ]),

                        Tab::make(__('system.partner_withdrawals.section_processing'))
                            ->icon('heroicon-m-clipboard-document-check')
                            ->schema([
                                Grid::make(2)->schema([
                                    TextEntry::make('assignedTo.name')
                                        ->label(__('system.partner_withdrawals.assigned_to'))
                                        ->placeholder('—'),
                                    TextEntry::make('updated_at')
                                        ->label(__('system.labels.last_update'))
                                        ->dateTime('d/m/Y H:i'),
                                    TextEntry::make('note')
                                        ->label(__('system.labels.note'))
                                        ->columnSpanFull()
                                        ->placeholder('—'),
                                ]),
                            ]),
                    ])
                    ->columnSpanFull(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('partner.name')
                    ->label(__('system.partner_withdrawals.partner'))
                    ->alignment(Alignment::Center)
                    ->searchable()
                    ->hidden(auth()->user()?->isPartner()),

                Tables\Columns\TextColumn::make('platform')
                    ->label(__('system.partner_withdrawals.platform'))
                    ->alignment(Alignment::Center)
                    ->searchable(),

                Tables\Columns\TextColumn::make('email')
                    ->label(__('system.partner_withdrawals.email'))
                    ->alignment(Alignment::Center)
                    ->searchable()
                    ->copyable(),

                Tables\Columns\TextColumn::make('amount_usd')
                    ->label(__('system.partner_withdrawals.amount_usd'))
                    ->alignment(Alignment::Center)
                    ->money('USD'),

                Tables\Columns\TextColumn::make('status')
                    ->label(__('system.partner_withdrawals.status'))
                    ->alignment(Alignment::Center)
                    ->badge()
                    ->icon(fn (string $state): string => match ($state) {
                        'new' => 'heroicon-m-sparkles',
                        'pending' => 'heroicon-m-clock',
                        'processing' => 'heroicon-m-arrow-path',
                        'completed' => 'heroicon-m-check-badge',
                        'wrong_pass' => 'heroicon-m-x-circle',
                        'banned' => 'heroicon-m-no-symbol',
                        default => 'heroicon-m-question-mark-circle',
                    })
                    ->color(fn (string $state): string => match ($state) {
                        'new' => 'gray',
                        'pending' => 'warning',
                        'processing' => 'info',
                        'completed' => 'success',
                        'wrong_pass', 'banned' => 'danger',
                        default => 'secondary',
                    })
                    ->formatStateUsing(function (string $state) {
                        $label = match ($state) {
                            'new' => __('system.partner_withdrawals.status_new'),
                            'pending' => __('system.partner_withdrawals.status_pending'),
                            'processing' => __('system.partner_withdrawals.status_processing'),
                            'completed' => __('system.partner_withdrawals.status_completed'),
                            'wrong_pass' => __('system.partner_withdrawals.status_wrong_pass'),
                            'banned' => __('system.partner_withdrawals.status_banned'),
                            default => $state,
                        };

                        if (auth()->user()?->isPartner()) {
                            return new HtmlString('
                                <div class="flex items-center gap-1.5 justify-center text-[0.85rem] font-bold">
                                    <span>'.$label.'</span>
                                </div>
                            ');
                        }

                        return new HtmlString('
                            <div class="flex items-center gap-1.5 justify-center text-[0.85rem] font-bold">
                                <span>'.$label.'</span>
                                '.Blade::render('<x-heroicon-m-pencil-square class="w-4 h-4 text-gray-400" />').'
                             </div>
                        ');
                    })
                    ->action(
                        Tables\Actions\Action::make('quick_set_status')
                            ->visible(fn () => ! auth()->user()?->isPartner())
                            ->modalHeading(__('system.partner_withdrawals.status'))
                            ->modalSubmitActionLabel(__('system.actions.submit'))
                            ->modalCancelActionLabel(__('system.actions.cancel'))
                            ->form([
                                Forms\Components\Select::make('status')
                                    ->label(__('system.partner_withdrawals.status'))
                                    ->options([
                                        'new' => __('system.partner_withdrawals.status_new'),
                                        'pending' => __('system.partner_withdrawals.status_pending'),
                                        'processing' => __('system.partner_withdrawals.status_processing'),
                                        'completed' => __('system.partner_withdrawals.status_completed'),
                                        'wrong_pass' => __('system.partner_withdrawals.status_wrong_pass'),
                                        'banned' => __('system.partner_withdrawals.status_banned'),
                                    ])
                                    ->default(fn ($record) => $record->status)
                                    ->required(),
                            ])
                            ->action(function ($record, array $data) {
                                $record->update($data);

                                Notification::make()
                                    ->title(__('system.notifications.status_updated_sync'))
                                    ->success()
                                    ->send();
                            })
                    ),

                Tables\Columns\TextColumn::make('assignedTo.name')
                    ->label(__('system.partner_withdrawals.assigned_to'))
                    ->alignment(Alignment::Center)
                    ->default('—')
                    ->hidden(auth()->user()?->isPartner()),

                Tables\Columns\TextColumn::make('created_at')
                    ->label(__('system.partner_withdrawals.created_at'))
                    ->alignment(Alignment::Center)
                    ->dateTime('d/m/Y H:i'),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label(__('system.partner_withdrawals.status'))
                    ->options([
                        'new' => __('system.partner_withdrawals.status_new'),
                        'pending' => __('system.partner_withdrawals.status_pending'),
                        'processing' => __('system.partner_withdrawals.status_processing'),
                        'completed' => __('system.partner_withdrawals.status_completed'),
                        'wrong_pass' => __('system.partner_withdrawals.status_wrong_pass'),
                        'banned' => __('system.partner_withdrawals.status_banned'),
                    ]),
                Tables\Filters\SelectFilter::make('platform')
                    ->label(__('system.partner_withdrawals.platform'))
                    ->options(fn () => PartnerWithdrawal::distinct()->pluck('platform', 'platform')),
            ])
            ->actions([
                Tables\Actions\ViewAction::make()
                    ->label('')
                    ->tooltip(__('filament-actions::view.single.label')),
                ActionGroup::make([
                    Tables\Actions\EditAction::make(),
                    Tables\Actions\Action::make('generate_payout')
                        ->label(__('system.partner_withdrawals.action_generate_payout'))
                        ->icon('heroicon-o-banknotes')
                        ->color('success')
                        ->requiresConfirmation()
                        ->modalHeading(__('system.partner_withdrawals.action_generate_payout_modal'))
                        ->modalDescription(fn ($record) => __('system.partner_withdrawals.action_generate_payout_desc', [
                            'partner' => $record->partner->name,
                            'amount' => $record->amount_usd,
                        ]))
                        ->visible(fn ($record) => $record->status === 'completed' && auth()->user()?->isAdmin())
                        ->form([
                            Forms\Components\Select::make('payout_method_id')
                                ->label(__('system.labels.wallet'))
                                ->options(PayoutMethod::query()->get()->mapWithKeys(function ($method) {
                                    $typeLabel = match ($method->type) {
                                        'paypal_us' => 'PAYPAL US',
                                        'paypal_vn' => 'PAYPAL VN',
                                        default => strtoupper(str_replace('_', ' ', $method->type)),
                                    };

                                    return [$method->id => "{$typeLabel} - {$method->name}"];
                                }))
                                ->searchable()
                                ->required(),
                        ])
                        ->action(function ($record, array $data) {
                            $exists = PayoutLog::where('partner_withdrawal_id', $record->id)->exists();

                            if ($exists) {
                                Notification::make()
                                    ->title(__('system.partner_withdrawals.action_generate_payout_exist'))
                                    ->warning()
                                    ->send();

                                return;
                            }

                            PayoutLog::create([
                                'user_id' => $record->partner_id,
                                'partner_withdrawal_id' => $record->id,
                                'payout_method_id' => $data['payout_method_id'],
                                'transaction_type' => 'partner_withdrawal',
                                'asset_type' => 'currency',
                                'status' => 'pending',
                                'amount_usd' => $record->amount_usd,
                                'net_amount_usd' => $record->amount_usd,
                                'note' => 'Partner withdrawal #'.$record->id,
                            ]);

                            Notification::make()
                                ->title(__('system.partner_withdrawals.action_generate_payout_done'))
                                ->success()
                                ->send();
                        }),
                    Tables\Actions\DeleteAction::make()
                        ->hidden(fn () => ! auth()->user()?->isAdmin()),
                ])
                    ->icon('heroicon-m-ellipsis-vertical'),
            ])
            ->defaultSort('created_at', 'desc');
    }

    // =========================================================================
    // PAGES
    // =========================================================================
    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPartnerWithdrawals::route('/'),
            'create' => Pages\CreatePartnerWithdrawal::route('/create'),
            'edit' => Pages\EditPartnerWithdrawal::route('/{record}/edit'),
        ];
    }
}
