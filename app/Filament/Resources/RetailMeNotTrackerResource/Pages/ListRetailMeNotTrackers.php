<?php

namespace App\Filament\Resources\RetailMeNotTrackerResource\Pages;

use App\Filament\Resources\RetailMeNotTrackerResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListRetailMeNotTrackers extends ListRecords
{
    protected static string $resource = RetailMeNotTrackerResource::class;
    use \App\Filament\Traits\HasSyncToSheetAction;

    protected function getHeaderActions(): array
    {
        return [
            $this->getSyncToSheetAction('syncTrackers', __('system.trackers.retail_me_not')),
            $this->getImportTrackersFromSheetAction(),
            Actions\CreateAction::make()
                ->label(__('system.trackers.create', ['tracker' => strtolower(__('system.trackers.retail_me_not'))])),
        ];
    }
}
