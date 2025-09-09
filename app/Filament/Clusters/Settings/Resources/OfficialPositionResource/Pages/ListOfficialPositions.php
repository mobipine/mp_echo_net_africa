<?php

namespace App\Filament\Clusters\Settings\Resources\OfficialPositionResource\Pages;

use App\Filament\Clusters\Settings\Resources\OfficialPositionResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListOfficialPositions extends ListRecords
{
    protected static string $resource = OfficialPositionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
