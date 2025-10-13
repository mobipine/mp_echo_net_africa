<?php

namespace App\Filament\Resources\RedoSurveyResource\Pages;

use App\Filament\Resources\RedoSurveyResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListRedoSurveys extends ListRecords
{
    protected static string $resource = RedoSurveyResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
