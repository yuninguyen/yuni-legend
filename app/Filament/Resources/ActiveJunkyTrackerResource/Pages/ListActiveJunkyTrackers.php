<?php

namespace App\Filament\Resources\ActiveJunkyTrackerResource\Pages;

use App\Filament\Resources\ActiveJunkyTrackerResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;
use App\Services\GoogleSyncService;

class ListActiveJunkyTrackers extends ListRecords
{
    protected static string $resource = ActiveJunkyTrackerResource::class;
    use \App\Filament\Traits\HasSyncToSheetAction;
    use \App\Filament\Traits\HasTrackerTabs;

    protected function getHeaderActions(): array
    {
        return [
            $this->getSyncToSheetAction('syncTrackers', __('system.trackers.active_junky')),
            $this->getImportTrackersFromSheetAction(),
            Actions\CreateAction::make()
                ->label(__('system.trackers.create', ['tracker' => __('system.trackers.active_junky')])),
        ];
    }
}
