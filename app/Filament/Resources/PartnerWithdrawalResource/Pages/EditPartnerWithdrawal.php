<?php

namespace App\Filament\Resources\PartnerWithdrawalResource\Pages;

use App\Filament\Resources\PartnerWithdrawalResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditPartnerWithdrawal extends EditRecord
{
    protected static string $resource = PartnerWithdrawalResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make()
                ->hidden(fn () => ! auth()->user()?->isAdmin()),
        ];
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
