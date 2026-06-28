<?php

namespace App\Filament\Resources;

use App\Filament\Resources\PriceResource\Pages;
use App\Filament\Resources\PriceResource\RelationManagers;
use App\Models\Price;
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

class PriceResource extends Resource
{
    use HasAccountSchema; // <-- Dòng ma thuật: Gọi toàn bộ Form, Table, Infolist vào đây!
    
    protected static ?string $model = Account::class;

    protected static ?string $navigationIcon = 'heroicon-o-rectangle-stack';
    protected static ?int $navigationSort = 6;

    public static function getNavigationLabel(): string
    {
        return 'Price.com';
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
        return self::isPlatformActive(['Price', 'price', 'Price.com']);
    }

    // Thêm dòng này để thu gọn menu bên trái, nhường chỗ cho bảng
    protected static bool $isScopedToTenant = false;
    // THÊM DÒNG NÀY: Đổi đường dẫn URL từ /Price thành /price
    protected static ?string $slug = 'price';

    // HÀM LỌC DỮ LIỆU: Chỉ lấy tài khoản của Active Junky
    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery()->whereIn('platform', ['Price', 'price', 'Price.com', 'pricecom', 'price-com']);

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
            'index' => Pages\ListPrices::route('/'),
            'create' => Pages\CreatePrice::route('/create'),
            'edit' => Pages\EditPrice::route('/{record}/edit'),
        ];
    }
}
