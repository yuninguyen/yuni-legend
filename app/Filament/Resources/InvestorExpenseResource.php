<?php

namespace App\Filament\Resources;

use App\Filament\Resources\InvestorExpenseResource\Pages;
use App\Models\InvestorExpense;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Infolists\Components\Grid;
use Filament\Infolists\Components\Section;
use Filament\Infolists\Components\Tabs;
use Filament\Infolists\Components\Tabs\Tab;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Support\Enums\Alignment;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class InvestorExpenseResource extends Resource
{
    protected static ?string $model = InvestorExpense::class;
    
    protected static ?string $navigationIcon = 'heroicon-o-receipt-refund';

    public static function canViewAny(): bool
    {
        return auth()->user()?->isAdmin() || auth()->user()?->isFinance();
    }

    public static function canCreate(): bool
    {
        return auth()->user()?->isAdmin() || auth()->user()?->isFinance();
    }

    public static function canEdit(Model $record): bool
    {
        return auth()->user()?->isAdmin() || auth()->user()?->isFinance();
    }

    public static function canDelete(Model $record): bool
    {
        return auth()->user()?->isAdmin(); // Only admin can delete expenses
    }

    public static function getNavigationGroup(): ?string
    {
        return 'wallet_payout';
    }

    public static function getNavigationLabel(): string
    {
        return __('system.labels.investor_expenses');
    }

    public static function getModelLabel(): string
    {
        return __('system.labels.investor_expense');
    }

    public static function getPluralModelLabel(): string
    {
        return __('system.labels.investor_expenses');
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make()
                    ->schema([
                        Forms\Components\Select::make('user_id')
                            ->label(__('system.labels.user'))
                            ->relationship('user', 'name')
                            ->searchable()
                            ->preload()
                            ->required(),
                        Forms\Components\Select::make('currency')
                            ->label(__('system.labels.type')) // Using existing label for "Type/Currency"
                            ->options([
                                'VND' => 'VND',
                                'USD' => 'USD (PayPal)',
                                'USDT' => 'USDT (Crypto)',
                            ])
                            ->default('VND')
                            ->required()
                            ->reactive(),
                        Forms\Components\TextInput::make('amount_vnd')
                            ->label(__('system.labels.amount_vnd'))
                            ->numeric()
                            ->prefix('₫')
                            ->required()
                            ->visible(fn (callable $get) => $get('currency') === 'VND'),
                        Forms\Components\TextInput::make('amount_usd')
                            ->label(__('system.labels.amount_usd'))
                            ->numeric()
                            ->prefix('$')
                            ->required()
                            ->visible(fn (callable $get) => $get('currency') === 'USD'),
                        Forms\Components\TextInput::make('amount_usdt')
                            ->label(__('system.labels.amount_usdt'))
                            ->numeric()
                            ->prefix('₮')
                            ->required()
                            ->visible(fn (callable $get) => $get('currency') === 'USDT'),
                        Forms\Components\Select::make('status')
                            ->label(__('system.labels.status'))
                            ->options([
                                'pending' => __('system.status.pending'),
                                'deducted' => __('system.status.deducted'),
                                'cancelled' => __('system.status.cancelled'),
                            ])
                            ->default('pending')
                            ->required(),
                        Forms\Components\Textarea::make('reason')
                            ->label(__('system.labels.reason'))
                            ->columnSpanFull(),
                    ])->columns(2),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('user.name')
                    ->label(__('system.labels.user'))
                    ->alignment(Alignment::Center)
                    ->searchable(),
                Tables\Columns\TextColumn::make('amount')
                    ->label(__('system.labels.amount'))
                    ->alignment(Alignment::Center)
                    ->state(function (InvestorExpense $record): string {
                        if ($record->currency === 'USD') {
                            return '$ '.number_format($record->amount_usd, 2);
                        }
                        if ($record->currency === 'USDT') {
                            return number_format($record->amount_usdt, 2).' USDT';
                        }

                        return number_format($record->amount_vnd).' ₫';
                    }),
                Tables\Columns\TextColumn::make('status')
                    ->label(__('system.labels.status'))
                    ->alignment(Alignment::Center)
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'pending' => 'warning',
                        'deducted' => 'success',
                        'cancelled' => 'danger',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn (string $state): string => __('system.status.'.$state)),
                Tables\Columns\TextColumn::make('reason')
                    ->label(__('system.labels.reason'))
                    ->limit(50)
                    ->alignment(Alignment::Center),
                Tables\Columns\TextColumn::make('userPayment.batch_id')
                    ->label(__('system.labels.batch_id'))
                    ->alignment(Alignment::Center)
                    ->placeholder(__('system.labels.n/a')),
                Tables\Columns\TextColumn::make('created_at')
                    ->label(__('system.labels.created_at'))
                    ->alignment(Alignment::Center)
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('user_id')
                    ->label(__('system.labels.user'))
                    ->relationship('user', 'name')
                    ->searchable()
                    ->preload(),
                Tables\Filters\SelectFilter::make('status')
                    ->label(__('system.labels.status'))
                    ->options([
                        'pending' => __('system.status.pending'),
                        'deducted' => __('system.status.deducted'),
                        'cancelled' => __('system.status.cancelled'),
                    ]),
            ])
            ->actions([
                Tables\Actions\ViewAction::make()
                    ->label('')
                    ->tooltip(__('system.labels.detail'))
                    ->icon('heroicon-o-eye')
                    ->color('gray'),
                Tables\Actions\ActionGroup::make([
                    Tables\Actions\EditAction::make(),
                    Tables\Actions\DeleteAction::make(),
                ]),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist
            ->schema([
                Tabs::make('Tabs')
                    ->tabs([
                        Tab::make(__('system.labels.transaction_details'))
                            ->icon('heroicon-m-banknotes')
                            ->schema([
                                Grid::make(3)
                                    ->schema([
                                        TextEntry::make('user.name')
                                            ->label(__('system.labels.user')),
                                        TextEntry::make('amount')
                                            ->label(__('system.labels.amount'))
                                            ->state(function (InvestorExpense $record): string {
                                                if ($record->currency === 'USD') {
                                                    return '$ '.number_format($record->amount_usd, 2);
                                                }
                                                if ($record->currency === 'USDT') {
                                                    return number_format($record->amount_usdt, 2).' USDT';
                                                }

                                                return number_format($record->amount_vnd).' ₫';
                                            })
                                            ->weight('bold')
                                            ->color('primary'),
                                        TextEntry::make('status')
                                            ->label(__('system.labels.status'))
                                            ->badge()
                                            ->color(fn (string $state): string => match ($state) {
                                                'pending' => 'warning',
                                                'deducted' => 'success',
                                                'cancelled' => 'danger',
                                                default => 'gray',
                                            })
                                            ->formatStateUsing(fn (string $state): string => __('system.status.'.$state)),
                                    ]),
                                Grid::make(3)
                                    ->schema([
                                        TextEntry::make('userPayment.batch_id')
                                            ->label(__('system.labels.batch_id'))
                                            ->placeholder(__('system.labels.n/a')),
                                        TextEntry::make('created_at')
                                            ->label(__('system.labels.created_at'))
                                            ->dateTime(),
                                    ]),
                                TextEntry::make('reason')
                                    ->label(__('system.labels.reason'))
                                    ->columnSpanFull()
                                    ->prose(),
                            ]),
                    ])
                    ->columnSpanFull(),
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
            'index' => Pages\ListInvestorExpenses::route('/'),
        ];
    }
}
