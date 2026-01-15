<?php

namespace App\Filament\Resources\SurveyQuestionResource\Pages;

use App\Filament\Resources\SurveyQuestionResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditSurveyQuestion extends EditRecord
{
    protected static string $resource = SurveyQuestionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        // Handle "No Alternative" option - if swahili_question_id is 0 or equals the question's own ID
        if (isset($data['swahili_question_id']) && $this->record) {
            $questionId = $this->record->id;
            
            // If the value is 0 (which might be sent from frontend) or equals the question's own ID,
            // set it to the question's own ID (No Alternative sentinel)
            if ($data['swahili_question_id'] == 0 || $data['swahili_question_id'] == $questionId) {
                $data['swahili_question_id'] = $questionId;
            }
            // If it's null or empty string, set to null (Kiswahili question or no selection)
            elseif (empty($data['swahili_question_id'])) {
                $data['swahili_question_id'] = null;
            }
        }
        
        return $data;
    }
}
