<?php

namespace App\Filament\Resources\LoanProductResource\Pages;

use App\Filament\Resources\LoanProductResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditLoanProduct extends EditRecord
{
    protected static string $resource = LoanProductResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
