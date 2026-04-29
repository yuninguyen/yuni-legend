<?php

namespace App\Filament\Resources;

use App\Filament\Resources\RetailMeNotTrackerResource\Pages;
use App\Filament\Resources\RetailMeNotTrackerResource\RelationManagers;
use App\Models\RetailMeNotTracker;
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

class RetailMeNotTrackerResource extends Resource
{
    use HasTrackerSchema; // <-- Dòng ma thuật: Gọi toàn bộ Form, Table, Infolist vào đây!
    
    protected static ?string $model = RebateTracker::class;
    
    protected static ?string $navigationIcon = 'heroicon-o-rectangle-stack';
    protected static ?int $navigationSort = 2;

    public static function getNavigationLabel(): string
    {
        return __('system.trackers.retail_me_not');
    }

    public static function getPluralLabel(): string
    {
        return __('system.trackers.retail_me_not');
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
    // THÊM DÒNG NÀY: Đổi đường dẫn URL từ /rakutens thành /rakuten
    protected static ?string $slug = 'retailmenot-tracker';

// HÀM LỌC DỮ LIỆU: Chỉ lấy tài khoản của RetailMeNot + Áp dụng phân quyền
    public static function getEloquentQuery(): Builder
    {
        // 1. Lớp lọc mặc định: LUÔN LUÔN chỉ lấy dữ liệu của RetailMeNot
        $query = parent::getEloquentQuery()->whereHas('account', function ($query) {
            $query->whereIn('platform', ['RetailMeNot', 'retailmenot']);
        });

        $user = auth()->user();

        // 2. Nếu là Admin -> Cho phép xem toàn bộ danh sách RetailMeNot
        if ($user && method_exists($user, 'isAdmin') && $user->isAdmin()) {
            return $query;
        }

        // 3. Nếu là Staff bình thường -> Chỉ xem RetailMeNot do chính họ tạo/quản lý
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
            'index' => Pages\ListRetailMeNotTrackers::route('/'),
            'create' => Pages\CreateRetailMeNotTracker::route('/create'),
            'edit' => Pages\EditRetailMeNotTracker::route('/{record}/edit'),
        ];
    }
}
