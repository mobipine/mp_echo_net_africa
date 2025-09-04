<?php

namespace App\Filament\Clusters\Settings\Resources\OfficialPositionResource\Pages;

use App\Filament\Clusters\Settings\Resources\OfficialPositionResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditOfficialPosition extends EditRecord
{
    protected static string $resource = OfficialPositionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
