<?php

namespace App\Filament\Resources\PlatformResource\Pages;

use App\Filament\Resources\PlatformResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListPlatforms extends ListRecords
{
    use \App\Filament\Traits\HasSyncToSheetAction;

    protected static string $resource = PlatformResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // Nút Sync FROM Google Sheet
            $this->getImportPlatformsFromSheetAction(),

            // Nút Create
            Actions\CreateAction::make(),
        ];
    }
}
