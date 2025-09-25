<?php

namespace App\Filament\Resources\MemberEditRequestResource\Pages;

use App\Filament\Resources\MemberEditRequestResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListMemberEditRequests extends ListRecords
{
    protected static string $resource = MemberEditRequestResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // Actions\CreateAction::make(),
        ];
    }
}
