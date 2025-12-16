<?php

namespace App\Filament\Resources\UssdFlowResource\Pages;

use App\Filament\Resources\UssdFlowResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListUssdFlows extends ListRecords
{
    protected static string $resource = UssdFlowResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}

