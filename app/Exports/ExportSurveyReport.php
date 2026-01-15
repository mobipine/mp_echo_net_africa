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

        // Get English questions (those with swahili_question_id set, including "no alternative" ones)
        // A question is English if:
        // 1. swahili_question_id is NOT NULL (has alternative OR marked as "no alternative")
        // 2. If swahili_question_id equals the question's own ID, it means "no alternative"
        $this->englishQuestions = $this->survey->questions()
            ->whereNotNull('swahili_question_id') // Only English questions (with or without alternative)
            ->orderBy('pivot_position')
            ->get()
            ->map(function ($question) {
                // If swahili_question_id equals the question's own ID, it means "no alternative"
                // Set it to null for easier handling in the export
                if ($question->swahili_question_id == $question->id) {
                    $question->swahili_question_id = null; // Mark as no alternative
                }
                return $question;
            })
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
