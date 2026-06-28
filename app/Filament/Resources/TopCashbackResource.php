<?php

namespace App\Filament\Resources;

use App\Filament\Resources\TopCashbackResource\Pages;
use App\Filament\Resources\TopCashbackResource\RelationManagers;
use App\Models\Account;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use App\Filament\Resources\Traits\HasAccountSchema; // <-- Nhúng Trait
use App\Filament\Resources\AccountResource\RelationManagers\ActivitiesRelationManager;

class TopCashbackResource extends Resource
{
    use HasAccountSchema; // <-- Dòng ma thuật: Gọi toàn bộ Form, Table, Infolist vào đây!
    
    protected static ?string $model = Account::class;

    protected static ?string $navigationIcon = 'heroicon-o-rectangle-stack';
    protected static ?int $navigationSort = 5;

    public static function getNavigationLabel(): string
    {
        return 'TopCashback';
    }

    public static function getNavigationGroup(): ?string
    {
        return 'resource_hub';
    }

    public static function getNavigationParentItem(): ?string
    {
        return __('system.all_platforms');
    }

    public static function shouldRegisterNavigation(): bool
    {
        return self::isPlatformActive(['TopCashback', 'topcashback']);
    }

    // Thêm dòng này để thu gọn menu bên trái, nhường chỗ cho bảng
    protected static bool $isScopedToTenant = false;
    // THÊM DÒNG NÀY: Đổi đường dẫn URL thành /topcashback
    protected static ?string $slug = 'topcashback';

    // HÀM LỌC DỮ LIỆU: Chỉ lấy tài khoản của RetailMeNot
    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery()->whereIn('platform', ['TopCashback', 'topcashback']);

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
            'index' => Pages\ListTopCashbacks::route('/'),
            'create' => Pages\CreateTopCashback::route('/create'),
            'edit' => Pages\EditTopCashback::route('/{record}/edit'),
        ];
    }
}
