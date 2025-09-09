<?php

namespace App\Filament\Resources\SurveyResource\Pages;

use App\Filament\Pages\SurveyFlowBuilder;
use App\Filament\Resources\SurveyResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditSurvey extends EditRecord
{
    protected static string $resource = SurveyResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('build_flow')
                ->label('Build Survey Flow')
                ->url(fn () => SurveyFlowBuilder::getUrl(['survey' => $this->record->id])),
            Actions\ViewAction::make(),
            Actions\DeleteAction::make(),
        ];
    }
}
