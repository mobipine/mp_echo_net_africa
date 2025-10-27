<?php

namespace App\Filament\Resources\SaccoProductResource\Pages;

use App\Filament\Resources\SaccoProductResource;
use Filament\Actions;
use Filament\Resources\Pages\ViewRecord;

class ViewSaccoProduct extends ViewRecord
{
    protected static string $resource = SaccoProductResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\EditAction::make(),
        ];
    }
}

