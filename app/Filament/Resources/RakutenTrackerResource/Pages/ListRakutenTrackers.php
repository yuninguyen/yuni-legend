<?php

namespace App\Filament\Resources\RakutenTrackerResource\Pages;

use App\Filament\Resources\RakutenTrackerResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;
use App\Services\GoogleSyncService;

class ListRakutenTrackers extends ListRecords
{
    protected static string $resource = RakutenTrackerResource::class;
    use \App\Filament\Traits\HasSyncToSheetAction;

    protected function getHeaderActions(): array
    {
        return [
            $this->getSyncToSheetAction('syncTrackers', __('system.trackers.rakuten')),
            $this->getImportTrackersFromSheetAction(),
            Actions\CreateAction::make()
                ->label(__('system.trackers.create', ['tracker' => strtolower(__('system.trackers.rakuten'))])),
        ];
    }
}
