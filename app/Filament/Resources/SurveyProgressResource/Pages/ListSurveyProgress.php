<?php

namespace App\Filament\Resources\SurveyProgressResource\Pages;

use App\Filament\Resources\SurveyProgressResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListSurveyProgress extends ListRecords
{
    protected static string $resource = SurveyProgressResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
