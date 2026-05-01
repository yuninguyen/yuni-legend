<?php

namespace App\Filament\Resources\TopCashbackTrackerResource\Pages;

use App\Filament\Resources\TopCashbackTrackerResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListTopCashbackTrackers extends ListRecords
{
    protected static string $resource = TopCashbackTrackerResource::class;
    use \App\Filament\Traits\HasSyncToSheetAction;

    protected function getHeaderActions(): array
    {
        return [
            $this->getSyncToSheetAction('syncTrackers', __('system.trackers.top_cashback')),
            $this->getImportTrackersFromSheetAction(),
            Actions\CreateAction::make()
                ->label(__('system.trackers.create', ['tracker' => __('system.trackers.top_cashback')])),
        ];
    }
}
