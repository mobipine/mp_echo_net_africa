<?php

namespace App\Filament\Resources\ChartofAccountsResource\Pages;

use App\Filament\Resources\ChartofAccountsResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditChartofAccounts extends EditRecord
{
    protected static string $resource = ChartofAccountsResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
