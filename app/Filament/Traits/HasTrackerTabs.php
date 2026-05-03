<?php

namespace App\Filament\Traits;

use App\Models\RebateTracker;
use Filament\Resources\Components\Tab;
use Illuminate\Database\Eloquent\Builder;

trait HasTrackerTabs
{
    /**
     * Status tabs shared by all Tracker List pages.
     * Each tab filters by status and shows a count badge.
     */
    public function getTabs(): array
    {
        // Base query: respect per-resource scoping (platform filter is applied by the Resource's getEloquentQuery)
        $baseQuery = static::getResource()::getEloquentQuery();

        return [
            'all' => Tab::make(__('system.trackers.tabs.all')),

            'clicked' => Tab::make(__('system.status.clicked'))
                ->icon('heroicon-m-cursor-arrow-rays')
                ->badge($baseQuery->clone()->where('status', 'clicked')->count())
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', 'clicked')),

            'pending' => Tab::make(__('system.status.pending'))
                ->icon('heroicon-m-clock')
                ->badge($baseQuery->clone()->where('status', 'pending')->count())
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', 'pending')),

            'confirmed' => Tab::make(__('system.status.confirmed'))
                ->icon('heroicon-m-check-badge')
                ->badge($baseQuery->clone()->where('status', 'confirmed')->count())
                ->badgeColor('success')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', 'confirmed')),

            'missing' => Tab::make(__('system.status.missing'))
                ->icon('heroicon-m-magnifying-glass')
                ->badge($baseQuery->clone()->where('status', 'missing')->count())
                ->badgeColor('warning')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', 'missing')),

            'ineligible' => Tab::make(__('system.status.ineligible'))
                ->icon('heroicon-m-x-circle')
                ->badge($baseQuery->clone()->where('status', 'ineligible')->count())
                ->badgeColor('danger')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', 'ineligible')),
        ];
    }
}
