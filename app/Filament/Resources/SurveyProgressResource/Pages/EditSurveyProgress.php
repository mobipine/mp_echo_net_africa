<?php

namespace App\Filament\Resources\SurveyProgressResource\Pages;

use App\Filament\Resources\SurveyProgressResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditSurveyProgress extends EditRecord
{
    protected static string $resource = SurveyProgressResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
