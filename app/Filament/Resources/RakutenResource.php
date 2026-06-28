<?php

namespace App\Filament\Resources;

use App\Filament\Resources\RakutenResource\Pages;
use App\Models\Account; // Use the Account model
use Filament\Forms\Form; // Correct Form import
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Tables\Table; // Correct Table import
use Filament\Tables;
use Filament\Support\Enums\Alignment; // Quan trọng: Phải import cái này
use Filament\Tables\Columns\TextColumn; // Fix for image_f54065
use Illuminate\Database\Eloquent\Builder;
use App\Filament\Resources\Traits\HasAccountSchema; // <-- Nhúng Trait
use App\Filament\Resources\AccountResource\RelationManagers\ActivitiesRelationManager;

class RakutenResource extends Resource
{
    use HasAccountSchema; // <-- Dòng ma thuật: Gọi toàn bộ Form, Table, Infolist vào đây!
        
    protected static ?string $model = Account::class; // SỬA DÒNG NÀY: Trỏ về Account model

    protected static ?string $navigationIcon = 'heroicon-o-rectangle-stack';
    protected static ?int $navigationSort = 1;

    public static function getNavigationLabel(): string
    {
        return 'Rakuten';
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
        return self::isPlatformActive(['Rakuten', 'rakuten']);
    }

    // Thêm dòng này để thu gọn menu bên trái, nhường chỗ cho bảng
    protected static bool $isScopedToTenant = false;
    // THÊM DÒNG NÀY: Đổi đường dẫn URL từ /rakutens thành /rakuten
    protected static ?string $slug = 'rakuten';

    // HÀM LỌC DỮ LIỆU: Chỉ lấy tài khoản của Rakuten
    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery()->whereIn('platform', ['Rakuten', 'rakuten']);

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
            'index' => Pages\ListRakutens::route('/'),
            'create' => Pages\CreateRakuten::route('/create'),
            'edit' => Pages\EditRakuten::route('/{record}/edit'),
        ];
    }
}
