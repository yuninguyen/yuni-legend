<?php

namespace App\Filament\Resources\PartnerWithdrawalResource\Pages;

use App\Filament\Resources\PartnerWithdrawalResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListPartnerWithdrawals extends ListRecords
{
    protected static string $resource = PartnerWithdrawalResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
