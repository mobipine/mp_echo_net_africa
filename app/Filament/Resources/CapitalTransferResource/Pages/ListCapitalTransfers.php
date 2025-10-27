<?php

namespace App\Filament\Resources\CapitalTransferResource\Pages;

use App\Filament\Resources\CapitalTransferResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListCapitalTransfers extends ListRecords
{
    protected static string $resource = CapitalTransferResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}

