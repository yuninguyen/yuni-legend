<?php

namespace App\Filament\Resources\InvestorExpenseResource\Pages;

use App\Filament\Resources\InvestorExpenseResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListInvestorExpenses extends ListRecords
{
    protected static string $resource = InvestorExpenseResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
