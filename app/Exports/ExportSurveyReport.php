<?php

namespace App\Exports;

use App\Models\Survey;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;

class ExportSurveyReport implements WithMultipleSheets
{
    protected int $surveyId;
    protected ?Survey $survey = null;
    protected array $englishQuestions = [];
    protected array $headings = [];

    public function __construct(int $surveyId)
    {
        $this->surveyId = $surveyId;
        $this->survey = Survey::with('questions')->findOrFail($surveyId);

        // Get only English questions (those with swahili_question_id) ordered by position
        $this->englishQuestions = $this->survey->questions()
            ->whereNotNull('swahili_question_id') // Only English questions
            ->orderBy('pivot_position')
            ->get()
            ->toArray();

        // Build headings: Member details + English question texts
        $this->headings = [
            'Group Name',
            'Name',
            'Email',
            'Phone Number',
            'National ID',
            'Gender',
            'Date of Birth',
            'Marital Status',
            'County Name'
        ];

        foreach ($this->englishQuestions as $question) {
            $this->headings[] = $question['question'];
        }
    }

    public function sheets(): array
    {
        return [
            new SurveyReportSheetAll($this->surveyId, $this->englishQuestions, $this->headings),
            new SurveyReportSheetCompleted($this->surveyId, $this->englishQuestions, $this->headings),
            new SurveyReportSheetIncomplete($this->surveyId, $this->englishQuestions, $this->headings),
        ];
    }
}
