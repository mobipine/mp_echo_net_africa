<?php

namespace App\Filament\Clusters\Settings\Resources\DocsMetaResource\Pages;

use App\Filament\Clusters\Settings\Resources\DocsMetaResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListDocsMetas extends ListRecords
{
    protected static string $resource = DocsMetaResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
