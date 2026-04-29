<?php

namespace App\Filament\Resources;

use App\Filament\Resources\PriceTrackerResource\Pages;
use App\Filament\Resources\PriceTrackerResource\RelationManagers;
use App\Models\PriceTracker;
use App\Models\RebateTracker;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use App\Filament\Resources\Traits\HasTrackerSchema;
use App\Filament\Resources\RebateTrackerResource\RelationManagers\ActivitiesRelationManager;

class PriceTrackerResource extends Resource
{
    use HasTrackerSchema; // <-- Dòng ma thuật: Gọi toàn bộ Form, Table, Infolist vào đây!
        
    protected static ?string $model = RebateTracker::class;

    protected static ?string $navigationIcon = 'heroicon-o-rectangle-stack';
    protected static ?int $navigationSort = 6;

    public static function getNavigationLabel(): string
    {
        return __('system.trackers.price');
    }

    public static function getPluralLabel(): string
    {
        return __('system.trackers.price');
    }

    public static function getNavigationGroup(): ?string
    {
        return 'working_space';
    }

    public static function shouldRegisterNavigation(): bool
    {
        return !auth()->user()?->isFinance();
    }

    public static function canAccess(): bool
    {
        return !auth()->user()?->isFinance();
    }

    public static function getNavigationParentItem(): ?string
    {
        return __('system.trackers.all_rebate');
    }

    // Thêm dòng này để thu gọn menu bên trái, nhường chỗ cho bảng
    protected static bool $isScopedToTenant = false;
    // THÊM DÒNG NÀY: Đổi đường dẫn URL thành /price
    protected static ?string $slug = 'price-tracker';

// HÀM LỌC DỮ LIỆU: Chỉ lấy tài khoản của Price + Áp dụng phân quyền
    public static function getEloquentQuery(): Builder
    {
        // 1. Lớp lọc mặc định: LUÔN LUÔN chỉ lấy dữ liệu của Price
        $query = parent::getEloquentQuery()->whereHas('account', function ($query) {
            $query->whereIn('platform', ['Price', 'price', 'Price.com']);
        });

        $user = auth()->user();

        // 2. Nếu là Admin -> Cho phép xem toàn bộ danh sách Price
        if ($user && method_exists($user, 'isAdmin') && $user->isAdmin()) {
            return $query;
        }

        // 3. Nếu là Staff bình thường -> Chỉ xem Price do chính họ tạo/quản lý
        return $query->where('user_id', auth()->id());
    }

    public static function getRelations(): array
    {
        return [
            ActivitiesRelationManager::class, // <-- Thêm dòng này vào
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPriceTrackers::route('/'),
            'create' => Pages\CreatePriceTracker::route('/create'),
            'edit' => Pages\EditPriceTracker::route('/{record}/edit'),
        ];
    }
}
