<?php

namespace App\Filament\Resources\PartnerWithdrawalResource\Pages;

use App\Filament\Resources\PartnerWithdrawalResource;
use Filament\Resources\Pages\CreateRecord;

class CreatePartnerWithdrawal extends CreateRecord
{
    protected static string $resource = PartnerWithdrawalResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        // Ensure partner_id is always set for partner users
        if (auth()->user()?->isPartner()) {
            $data['partner_id'] = auth()->id();
        }

        return $data;
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
