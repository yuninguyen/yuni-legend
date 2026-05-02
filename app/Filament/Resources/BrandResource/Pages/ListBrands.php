<?php

namespace App\Filament\Resources\BrandResource\Pages;

use App\Filament\Resources\BrandResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListBrands extends ListRecords
{
    use \App\Filament\Traits\HasSyncToSheetAction;

    protected static string $resource = BrandResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // Nút Sync FROM Google Sheet
            $this->getImportBrandsFromSheetAction(),

            // Nút Create
            Actions\CreateAction::make(),
        ];
    }
}
