<?php

namespace App\Filament\Resources\RedoSurveyResource\Pages;

use App\Filament\Resources\RedoSurveyResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditRedoSurvey extends EditRecord
{
    protected static string $resource = RedoSurveyResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
