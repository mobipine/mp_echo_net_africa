<?php

namespace App\Filament\Clusters\Settings\Resources\OfficialResource\Pages;

use App\Filament\Clusters\Settings\Resources\OfficialResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListOfficials extends ListRecords
{
    protected static string $resource = OfficialResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
