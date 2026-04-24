<?php

namespace App\Filament\Resources;

// tool test

use App\Filament\Resources\AccountResource\Pages;
use App\Filament\Resources\AccountResource\RelationManagers;
use App\Models\Account;
use App\Models\RebateTracker;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Support\Enums\Alignment;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\SelectColumn;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Filament\Tables\Actions\ExportBulkAction;
use App\Filament\Exports\AccountExporter;
use Filament\Support\Enums\Platform;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Enums\FiltersLayout;
use Illuminate\Support\Facades\DB;
use Filament\Forms\Components\Select;
use Filament\Tables\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Enums\FontWeight;
use Filament\Tables\Actions\BulkAction;
use Illuminate\Database\Eloquent\Collection;
use Filament\Navigation\NavigationItem;
use App\Filament\Resources\Traits\HasAccountSchema; // <-- Nhúng Trait
use App\Filament\Resources\AccountResource\RelationManagers\ActivitiesRelationManager;


class AccountResource extends Resource
{
    use HasAccountSchema; // <-- Dòng ma thuật: Gọi toàn bộ Form, Table, Infolist vào đây!

    protected static ?string $model = Account::class;

    // Đổi sang icon chứng minh thư/tài khoản
    protected static ?string $navigationIcon = 'heroicon-o-identification';

    public static function getNavigationGroup(): ?string
    {
        return 'resource_hub';
    }
    protected static ?string $navigationLabel = '';
    public static function getNavigationLabel(): string
    {
        return __('system.all_platforms');
    }

    public static function getModelLabel(): string
    {
        return __('system.all_platforms');
    }

    public static function getPluralModelLabel(): string
    {
        return __('system.all_platforms_list');
    }

    // Thêm dòng này để Account nằm dưới Email
    protected static ?int $navigationSort = 2;

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();

        // Admin & Finance được xem toàn bộ
        if (auth()->user()?->isAdmin() || auth()->user()?->isFinance()) {
            return $query;
        }

        // Staff chỉ xem được account của chính họ hoặc account chưa gán cho ai
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
            'index' => Pages\ListAccounts::route('/'),
            'create' => Pages\CreateAccount::route('/create'),
            'edit' => Pages\EditAccount::route('/{record}/edit'),
        ];
    }
}
