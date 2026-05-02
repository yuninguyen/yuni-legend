<?php

namespace App\Filament\Resources\UserResource\Pages;

use App\Filament\Resources\UserResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListUsers extends ListRecords
{
    use \App\Filament\Traits\HasSyncToSheetAction;

    protected static string $resource = UserResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // Nút Sync FROM Google Sheet
            $this->getImportUsersFromSheetAction(),

            // Nút Create
            Actions\CreateAction::make(),
        ];
    }
}
