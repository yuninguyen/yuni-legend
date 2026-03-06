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

class UserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static ?string $navigationIcon = 'heroicon-o-rectangle-stack';
    protected static ?string $navigationGroup = 'Settings';
    protected static ?int $navigationSort = 1; // Hiện trên Activity Log
    protected static ?string $navigationLabel = 'Users';

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
                Forms\Components\Section::make('User Information')
                    ->description('Manage login information and Holder identification.')
                    ->schema([
                        Forms\Components\TextInput::make('name')
                            ->label('Name')
                            ->required()
                            ->maxLength(255),
                        Forms\Components\TextInput::make('username')
                            ->label('Username')
                            ->placeholder('Eg: user01')
                            ->required()
                            ->unique(ignoreRecord: true) // Không báo lỗi trùng khi sửa chính user đó
                            ->maxLength(255)
                            ->prefix('@'), // Thêm icon @ cho chuyên nghiệp
                        Forms\Components\TextInput::make('email')
                            ->label('Email Address')
                            ->email()
                            ->required()
                            ->unique(ignoreRecord: true), // Tránh trùng email
                        Forms\Components\Select::make('role')
                            ->label('Role')
                            ->options([
                                'admin' => 'Admin',
                                'staff' => 'Staff',
                            ])
                            ->default('holder')
                            ->required()
                            ->native(false),
                        Forms\Components\TextInput::make('password')
                            ->label('Password')
                            ->placeholder('Leave it blank if you don\'t want to change your password.')
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
                    ->label('Name')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('username')
                    ->label('Username')
                    ->searchable(),
                Tables\Columns\TextColumn::make('email')
                    ->label('Email Address')
                    ->copyable() // Cho phép click để copy nhanh email
                    ->searchable(),
                Tables\Columns\TextColumn::make('role')
                    ->label('Role')
                    ->formatStateUsing(fn(string $state): string => match ($state) {
                        'admin' => 'Administrator',
                        'staff' => 'Staff',
                        default => $state,
                    })
                    ->sortable(),
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
