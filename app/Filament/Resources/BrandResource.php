<?php

namespace App\Filament\Resources;

use App\Filament\Resources\BrandResource\Pages;
use App\Filament\Resources\BrandResource\RelationManagers;
use App\Models\Brand;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Filament\Support\Enums\Alignment;

class BrandResource extends Resource
{
    protected static ?string $model = Brand::class;

    protected static ?string $navigationIcon = 'heroicon-o-rectangle-stack';


    public static function getNavigationGroup(): ?string
    {
        return 'Settings';
    }

    public static function getNavigationLabel(): string
    {
        return __('system.brands.navigation_label');
    }

    public static function getLabel(): ?string
    {
        return __('system.brands.label');
    }

    public static function getPluralLabel(): ?string
    {
        return __('system.brands.plural_label');
    }

    protected static ?int $navigationSort = 3;

    // 🟢 CHỈ ADMIN MỚI THẤY VÀ TRUY CẬP ĐƯỢC MENU NÀY
    public static function canViewAny(): bool
    {
        $user = auth()->user();
        return $user && method_exists($user, 'isAdmin') && $user->isAdmin();
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('name')
                    ->required()
                    ->live(onBlur: true)
                    ->afterStateUpdated(fn($state, $set) => $set('slug', \Str::slug($state))),
                Forms\Components\Select::make('platform')
                    ->label(__('system.brands.fields.platform'))
                    ->options(\App\Filament\Resources\Traits\HasPlatform::getPlatforms())
                    ->required(),
                Forms\Components\TextInput::make('slug')
                    ->required()
                    ->unique(ignoreRecord: true),
                Forms\Components\TextInput::make('boost_percentage')
                    ->label(__('system.brands.fields.boost_percentage'))
                    ->numeric()
                    ->default(0)
                    ->suffix('%')
                    ->helperText(__('system.brands.fields.boost_helper')),
                Forms\Components\TextInput::make('maximum_limit')
                    ->label(__('system.brands.fields.maximum_limit'))
                    ->numeric()
                    ->prefix('$')
                    ->helperText(__('system.brands.fields.limit_helper')),
                Forms\Components\TextInput::make('gc_rate')
                    ->label(__('system.brands.fields.gc_rate'))
                    ->numeric()
                    ->prefix('₫')
                    ->default(20000)
                    ->helperText(__('system.brands.fields.gc_rate_helper')),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label(__('system.brands.columns.name'))
                    ->alignment(Alignment::Center)
                    ->searchable(),
                Tables\Columns\TextColumn::make('platform')
                    ->label(__('system.brands.columns.platform'))
                    ->alignment(Alignment::Center)
                    ->searchable()
                    ->formatStateUsing(fn(string $state): string => \App\Models\Platform::where('slug', $state)->value('name') ?? $state),
                Tables\Columns\TextColumn::make('boost_percentage')
                    ->label(__('system.brands.columns.boost'))
                    ->alignment(Alignment::Center)
                    ->suffix('%')
                    ->sortable()
                    ->searchable()
                    ->numeric()
                    ->color('success')
                    ->weight('bold'),
                Tables\Columns\TextColumn::make('maximum_limit')
                    ->label(__('system.brands.columns.limit'))
                    ->alignment(Alignment::Center)
                    ->money('USD')
                    ->searchable()
                    ->placeholder(__('system.brands.columns.no_limit'))
                    ->color('danger'),
                Tables\Columns\TextColumn::make('gc_rate')
                    ->label(__('system.brands.columns.rate'))
                    ->alignment(Alignment::Center)
                    ->money('VND', locale: 'vi_VN')
                    ->color('primary'),
                Tables\Columns\TextColumn::make('slug')
                    ->alignment(Alignment::Center)
                    ->toggleable(isToggledHiddenByDefault: true), // Ẩn bớt cho gọn, cần thì bật lên,

            ])
            ->filters([
                // 1. LỌC THEO PLATFORM (Quan trọng nhất)
                Tables\Filters\SelectFilter::make('platform')
                    ->label(__('system.brands.filters.platform'))
                    ->options(\App\Filament\Resources\Traits\HasPlatform::getPlatforms())
                    ->searchable(), // Cho phép gõ tìm platform nếu danh sách dài

                // 2. LỌC THEO TRẠNG THÁI BOOST (Thẻ có thưởng vs Thẻ không thưởng)
                Tables\Filters\TernaryFilter::make('has_boost')
                    ->label(__('system.brands.filters.boost'))
                    ->trueLabel(__('system.brands.filters.with_boost'))
                    ->falseLabel(__('system.brands.filters.no_boost'))
                    ->queries(
                        true: fn(Builder $query) => $query->where('boost_percentage', '>', 0),
                        false: fn(Builder $query) => $query->where(fn($q) => $q->where('boost_percentage', 0)->orWhereNull('boost_percentage')),
                    ),

                // 3. LỌC THEO GIỚI HẠN RÚT (Có Limit vs Không Limit)
                Tables\Filters\TernaryFilter::make('has_limit')
                    ->label(__('system.brands.filters.limit'))
                    ->trueLabel(__('system.brands.filters.limited'))
                    ->falseLabel(__('system.brands.filters.unlimited'))
                    ->queries(
                        true: fn(Builder $query) => $query->where('maximum_limit', '>', 0),
                        false: fn(Builder $query) => $query->where(fn($q) => $q->where('maximum_limit', 0)->orWhereNull('maximum_limit')),
                    ),
            ])
            ->filtersLayout(Tables\Enums\FiltersLayout::AboveContent)
            ->filtersFormColumns(3) // Chia làm 3 cột trên cùng một hàng cho đẹp
            ->actions([
                Tables\Actions\EditAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
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
            'index' => Pages\ListBrands::route('/'),
            'create' => Pages\CreateBrand::route('/create'),
            'edit' => Pages\EditBrand::route('/{record}/edit'),
        ];
    }
}
