<?php

namespace App\Filament\Resources;

use App\Filament\Resources\JoinHoneyResource\Pages;
use App\Filament\Resources\JoinHoneyResource\RelationManagers;
use App\Models\Account;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Support\Enums\Alignment;
use Illuminate\Database\Eloquent\Builder;
use Filament\Tables\Columns\TextColumn;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use App\Filament\Resources\Traits\HasAccountSchema; // <-- Nhúng Trait
use App\Filament\Resources\AccountResource\RelationManagers\ActivitiesRelationManager;

class JoinHoneyResource extends Resource
{
    use HasAccountSchema; // <-- Dòng ma thuật: Gọi toàn bộ Form, Table, Infolist vào đây!
    
    protected static ?string $model = Account::class;

    protected static ?string $navigationIcon = 'heroicon-o-rectangle-stack';
    protected static ?int $navigationSort = 3;

    public static function getNavigationLabel(): string
    {
        return 'JoinHoney';
    }

    public static function getNavigationGroup(): ?string
    {
        return 'resource_hub';
    }

    public static function getNavigationParentItem(): ?string
    {
        return __('system.all_platforms');
    }

    // Thêm dòng này để thu gọn menu bên trái, nhường chỗ cho bảng
    protected static bool $isScopedToTenant = false;
    // THÊM DÒNG NÀY: Đổi đường dẫn URL từ /JoinHoney thành /JoinHoney
    protected static ?string $slug = 'join-honey';

    // HÀM LỌC DỮ LIỆU: Chỉ lấy tài khoản của Join Honey
    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery()->whereIn('platform', ['JoinHoney', 'joinhoney', 'Join Honey']);

        if (auth()->user()?->isAdmin()) {
            return $query;
        }

        return $query->where(function (Builder $q) {
            $q->where('user_id', auth()->id())
              ->orWhereNull('user_id');
        });
    }

    protected function getRedirectUrl(): string
    {
        // Quay về trang danh sách (List View)
        return $this->getResource()::getUrl('index');
    }

    public static function getRelations(): array
    {
        return [
            ActivitiesRelationManager::class, // <-- Gắn bảng Lịch sử vào đây
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListJoinHoneys::route('/'),
            'create' => Pages\CreateJoinHoney::route('/create'),
            'edit' => Pages\EditJoinHoney::route('/{record}/edit'),
        ];
    }
}
