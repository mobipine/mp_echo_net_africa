<?php

namespace App\Exports;

use App\Models\SurveyResponse;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;

class ExportResponseRecords implements FromCollection, WithHeadings
{
    protected ?int $surveyId;
    protected ?int $questionId;
    protected ?string $answer;

    public function __construct(?int $surveyId = null, ?int $questionId = null, ?string $answer = null)
    {
        $this->surveyId = $surveyId;
        $this->questionId = $questionId;
        $this->answer = $answer;
    }

    public function collection()
    {
        // Eager load relationships to avoid N+1 problem
        $query = SurveyResponse::with(['survey', 'question', 'member', 'session', 'inbox']);

        if ($this->surveyId) {
            $query->where('survey_id', $this->surveyId);
        }

        if ($this->questionId) {
            $query->where('question_id', $this->questionId);
        }

        if ($this->answer) {
            $query->where('survey_response', $this->answer);
        }

        return $query->get()->map(function ($response) {
            return [
                'Survey'          => $response->survey?->title ?? 'N/A',
                'Question'        => $response->question?->question ?? 'N/A',
                'Respondent'      => $response->member?->name ?? $response->msisdn,
                'Phone Number'   => $response->msisdn,
                'Group'          =>  $response->member?->group->name,
                'Response'        => $response->survey_response,
                'Responded At'    => $response->created_at?->format('Y-m-d H:i:s') ?? 'N/A',
            ];
        });
    }

    public function headings(): array
    {
        return [
            'Survey',
            'Question',
            'Respondent',
            'Phone Number',
            'Group',
            'Response',
            'Responded At',
        ];
    }
}
