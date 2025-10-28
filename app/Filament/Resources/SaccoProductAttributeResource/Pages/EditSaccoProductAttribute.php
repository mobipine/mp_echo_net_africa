<?php

namespace App\Filament\Resources\SaccoProductAttributeResource\Pages;

use App\Filament\Resources\SaccoProductAttributeResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditSaccoProductAttribute extends EditRecord
{
    protected static string $resource = SaccoProductAttributeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}

