<?php

namespace App\Exports;

use App\Models\Survey;
use App\Models\SurveyResponse;
use App\Models\Member;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Illuminate\Support\Collection;

class ExportSurveyReport implements FromCollection, WithHeadings, ShouldAutoSize
{
    protected int $surveyId;
    protected ?Survey $survey = null;
    protected array $questions = [];
    protected array $headings = [];

    public function __construct(int $surveyId)
    {
        $this->surveyId = $surveyId;
        $this->survey = Survey::with('questions')->findOrFail($surveyId);

        // Get questions ordered by position
        $this->questions = $this->survey->questions()
            ->orderBy('pivot_position')
            ->get()
            ->toArray();

        // Build headings: Phone Number + Member Name + Question texts
        $this->headings = ['Phone Number', 'Member Name'];
        foreach ($this->questions as $question) {
            $this->headings[] = $question['question'];
        }
    }

    public function collection()
    {
        // Get all unique members (msisdn) who responded to this survey
        $uniqueMembers = SurveyResponse::where('survey_id', $this->surveyId)
            ->select('msisdn')
            ->distinct()
            ->pluck('msisdn');

        // Get all responses for this survey, grouped by msisdn
        $allResponses = SurveyResponse::where('survey_id', $this->surveyId)
            ->whereIn('msisdn', $uniqueMembers)
            ->orderBy('created_at', 'desc') // Get latest responses first
            ->get()
            ->groupBy('msisdn');

        // Get member names by phone number for quick lookup
        $memberNames = Member::whereIn('phone', $uniqueMembers)
            ->pluck('name', 'phone')
            ->toArray();

        // Build the collection
        $data = collect();

        foreach ($uniqueMembers as $msisdn) {
            $memberResponses = $allResponses->get($msisdn, collect());

            // Create a map of question_id => latest response for quick lookup
            // If multiple responses exist for same question, use the latest one
            $responseMap = $memberResponses->groupBy('question_id')->map(function ($responses) {
                return $responses->first(); // Get the latest (already ordered by created_at desc)
            });

            // Get member name (or empty string if not found)
            $memberName = $memberNames[$msisdn] ?? '';

            // Build row: [phone_number, member_name, answer_to_q1, answer_to_q2, ...]
            $row = [$msisdn, $memberName];

            foreach ($this->questions as $question) {
                $questionId = $question['id'];
                $response = $responseMap->get($questionId);
                $row[] = $response ? $response->survey_response : '';
            }

            $data->push($row);
        }

        return $data;
    }

    public function headings(): array
    {
        return $this->headings;
    }
}
