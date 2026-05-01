<?php

namespace App\Filament\Resources\PriceTrackerResource\Pages;

use App\Filament\Resources\PriceTrackerResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListPriceTrackers extends ListRecords
{
    protected static string $resource = PriceTrackerResource::class;
    use \App\Filament\Traits\HasSyncToSheetAction;

    protected function getHeaderActions(): array
    {
        return [
            $this->getSyncToSheetAction('syncTrackers', __('system.trackers.price')),
            $this->getImportTrackersFromSheetAction(),
            Actions\CreateAction::make()
                ->label(__('system.trackers.create', ['tracker' => __('system.trackers.price')])),
        ];
    }
}
