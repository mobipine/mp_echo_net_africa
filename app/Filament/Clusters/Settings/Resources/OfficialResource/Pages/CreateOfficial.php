<?php

namespace App\Filament\Clusters\Settings\Resources\OfficialResource\Pages;

use App\Filament\Clusters\Settings\Resources\OfficialResource;
use App\Models\Official;
use Filament\Actions;
use Filament\Resources\Pages\CreateRecord;

class CreateOfficial extends CreateRecord
{
    protected static string $resource = OfficialResource::class;
}
