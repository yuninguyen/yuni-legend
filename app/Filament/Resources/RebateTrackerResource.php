<?php

namespace App\Filament\Resources;

use App\Filament\Resources\RebateTrackerResource\Pages;
use App\Filament\Resources\RebateTrackerResource\RelationManagers;
use App\Models\RebateTracker;
use Filament\Support\Enums\Alignment;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Tables\Grouping\Group;
use Illuminate\Support\HtmlString;
use Filament\Support\Enums\FontWeight;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Filament\Tables\Columns\SelectColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\Summarizers\Sum;
use Filament\Tables\Columns\Summarizers\Count;
use Filament\Infolists\Components\Actions\Action;
use Filament\Notifications\Notification;
use App\Filament\Resources\Traits\HasTrackerSchema;
use App\Filament\Resources\RebateTrackerResource\RelationManagers\ActivitiesRelationManager;

class RebateTrackerResource extends Resource
{
    use HasTrackerSchema; // $usStates + Form/Table/Infolist đều ở đây

    protected static ?string $model = RebateTracker::class;

    protected static ?string $navigationLabel = 'All Rebate Tracker';
    protected static ?string $pluralLabel = 'All Rebate Tracker';
    protected static ?string $navigationGroup = 'WORKING SPACE';
    protected static ?string $navigationIcon = 'heroicon-o-presentation-chart-line';

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();
        $user = auth()->user();

        // Nếu là Admin -> Cho xem tất cả mọi thứ
        if ($user && method_exists($user, 'isAdmin') && $user->isAdmin()) {
            return $query;
        }

        // 🟢 THÊM DÒNG CHỐT CHẶN NÀY ĐỂ FIX LỖI SẬP WEB:
        // Nhân viên bình thường -> Chỉ xem được đơn do chính họ tạo
        return $query->where('user_id', $user->id);
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
            'index' => Pages\ListRebateTrackers::route('/'),
            'create' => Pages\CreateRebateTracker::route('/create'),
            'edit' => Pages\EditRebateTracker::route('/{record}/edit'),
        ];
    }
}
