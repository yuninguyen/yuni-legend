<?php

namespace App\Filament\Resources;

use App\Filament\Resources\UserResource\Pages;
use App\Filament\Resources\UserResource\RelationManagers;
use App\Models\User;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Filament\Support\Enums\Alignment;

class UserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static ?string $navigationIcon = 'heroicon-o-rectangle-stack';
    protected static ?int $navigationSort = 1;
    public static function getNavigationGroup(): ?string
    {
        return 'Settings';
    }

    public static function getNavigationLabel(): string
    {
        return __('system.labels.user');
    }

    // 🟢 1. ẨN MENU BÊN TRÁI: Chỉ Admin mới thấy menu "Users"
    public static function shouldRegisterNavigation(): bool
    {
        return auth()->user()?->isAdmin() ?? false;
    }

    // 🟢 2. CHẶN TRUY CẬP TRỰC TIẾP TỪ URL (Bảo mật 2 lớp)
    // Đề phòng trường hợp nhân viên tự gõ đuôi "/users" lên thanh địa chỉ web
    public static function canAccess(): bool
    {
        return auth()->user()?->isAdmin() ?? false;
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make(__('system.account_claim.section_title'))
                    ->description(__('system.users.description'))
                    ->schema([
                        Forms\Components\TextInput::make('name')
                            ->label(__('system.labels.holder'))
                            ->required()
                            ->maxLength(255),
                        Forms\Components\TextInput::make('username')
                            ->label(__('system.labels.username'))
                            ->placeholder(__('system.placeholders.username_hint'))
                            ->required()
                            ->unique(ignoreRecord: true) // Không báo lỗi trùng khi sửa chính user đó
                            ->maxLength(255)
                            ->prefix('@'), // Thêm icon @ cho chuyên nghiệp
                        Forms\Components\TextInput::make('email')
                            ->label(__('system.labels.email_address'))
                            ->email()
                            ->required()
                            ->unique(ignoreRecord: true), // Tránh trùng email
                        Forms\Components\Select::make('role')
                            ->label(__('system.labels.role'))
                            ->options([
                                'admin' => __('system.roles.admin'),
                                'finance' => __('system.roles.finance'),
                                'operator' => __('system.roles.operator'),
                                'partner' => __('system.roles.partner'),
                            ])
                            ->default('operator')
                            ->required()
                            ->native(false),
                        Forms\Components\TextInput::make('password')
                            ->label(__('system.labels.password'))
                            ->placeholder(__('system.placeholders.password_hint'))
                            ->password()
                            ->revealable()
                            ->dehydrated(fn($state) => filled($state)) // Chỉ lưu nếu có nhập pass mới
                            ->required(fn(string $context): bool => $context === 'create'), // Bắt buộc khi tạo mới
                    ])
                    ->columns(2), // Chia làm 2 cột để form gọn gàng;
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label(__('system.labels.holder'))
                    ->searchable()
                    ->alignment(Alignment::Center),

                Tables\Columns\TextColumn::make('username')
                    ->label(__('system.labels.username'))
                    ->searchable()
                    ->alignment(Alignment::Center),
                Tables\Columns\TextColumn::make('email')
                    ->label(__('system.labels.email_address'))
                    ->copyable() // Cho phép click để copy nhanh email
                    ->searchable()
                    ->alignment(Alignment::Center),
                Tables\Columns\TextColumn::make('role')
                    ->label(__('system.labels.role'))
                    ->formatStateUsing(fn(string $state): string => match ($state) {
                        'admin' => __('system.roles.admin'),
                        'finance' => __('system.roles.finance'),
                        'operator' => __('system.roles.operator'),
                        'partner' => __('system.roles.partner'),
                        default => $state,
                    })
                    ->badge()
                    ->color(fn(string $state): string => match ($state) {
                        'admin' => 'danger',
                        'finance' => 'info',
                        'operator' => 'success',
                        default => 'gray',
                    })
                    ->alignment(Alignment::Center),
            ])
            ->filters([
                //
            ])
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
            'index' => Pages\ListUsers::route('/'),
            'create' => Pages\CreateUser::route('/create'),
            'edit' => Pages\EditUser::route('/{record}/edit'),
        ];
    }
}
