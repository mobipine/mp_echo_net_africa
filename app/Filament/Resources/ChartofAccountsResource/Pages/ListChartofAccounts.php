<?php

namespace App\Filament\Resources\ChartofAccountsResource\Pages;

use App\Filament\Resources\ChartofAccountsResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListChartofAccounts extends ListRecords
{
    protected static string $resource = ChartofAccountsResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
