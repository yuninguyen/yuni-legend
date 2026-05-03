<?php

namespace App\Filament\Resources\JoinHoneyTrackerResource\Pages;

use App\Filament\Resources\JoinHoneyTrackerResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;
use App\Services\GoogleSyncService;

class ListJoinHoneyTrackers extends ListRecords
{
    protected static string $resource = JoinHoneyTrackerResource::class;
    use \App\Filament\Traits\HasSyncToSheetAction;
    use \App\Filament\Traits\HasTrackerTabs;

    protected function getHeaderActions(): array
    {
        return [
            $this->getSyncToSheetAction('syncTrackers', __('system.trackers.join_honey')),
            $this->getImportTrackersFromSheetAction(),
            Actions\CreateAction::make()
                ->label(__('system.trackers.create', ['tracker' => __('system.trackers.join_honey')])),
        ];
    }
}
