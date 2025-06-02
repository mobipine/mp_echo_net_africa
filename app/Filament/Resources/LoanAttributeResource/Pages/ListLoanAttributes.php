<?php

namespace App\Filament\Resources\LoanAttributeResource\Pages;

use App\Filament\Resources\LoanAttributeResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListLoanAttributes extends ListRecords
{
    protected static string $resource = LoanAttributeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
