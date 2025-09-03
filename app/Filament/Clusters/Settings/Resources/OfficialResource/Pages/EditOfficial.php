<?php

namespace App\Filament\Clusters\Settings\Resources\OfficialResource\Pages;

use App\Filament\Clusters\Settings\Resources\OfficialResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditOfficial extends EditRecord
{
    protected static string $resource = OfficialResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
