<?php

namespace App\Filament\Resources\RebateTrackerResource\Pages;

use App\Filament\Resources\RebateTrackerResource;
use App\Filament\Traits\HasSyncToSheetAction;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListRebateTrackers extends ListRecords
{
    use HasSyncToSheetAction;

    protected static string $resource = RebateTrackerResource::class;

    protected function getHeaderActions(): array
    {
        return [
            $this->getSyncToSheetAction('syncTrackers', __('system.trackers.all_rebate')),
            $this->getImportTrackersFromSheetAction(),
            // 2. NÚT TẠO MỚI MẶC ĐỊNH CỦA FILAMENT (MÀU CAM)
            Actions\CreateAction::make()
                ->label(__('system.trackers.create', ['tracker' => __('system.trackers.all_rebate')])),
        ];
    }
}
