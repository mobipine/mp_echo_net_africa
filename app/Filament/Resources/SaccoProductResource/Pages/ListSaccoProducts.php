<?php

namespace App\Filament\Resources\SaccoProductResource\Pages;

use App\Filament\Resources\SaccoProductResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListSaccoProducts extends ListRecords
{
    protected static string $resource = SaccoProductResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}

