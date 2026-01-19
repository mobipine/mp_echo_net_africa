<?php

namespace App\Filament\Clusters\Settings\Resources\SettingResource\Pages;

use App\Filament\Clusters\Settings\Resources\SettingResource;
use Filament\Actions;
use Filament\Resources\Pages\CreateRecord;

class CreateSetting extends CreateRecord
{
    protected static string $resource = SettingResource::class;
}
