<?php

namespace App\Filament\Clusters\Settings\Resources\SettingResource\Pages;

use App\Filament\Clusters\Settings\Resources\SettingResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListSettings extends ListRecords
{
    protected static string $resource = SettingResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
