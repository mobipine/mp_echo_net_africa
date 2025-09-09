<?php

namespace App\Filament\Clusters\Settings\Resources\ChartofAccountsResource\Pages;

use App\Filament\Clusters\Settings\Resources\ChartofAccountsResource;
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
