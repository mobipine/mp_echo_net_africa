<?php

namespace App\Exports;

use App\Models\SurveyProgress;
use App\Models\SurveyResponse;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithTitle;
use Illuminate\Support\Collection;

class SurveyReportSheetAll implements FromCollection, WithHeadings, ShouldAutoSize, WithTitle
{
    protected int $surveyId;
    protected array $englishQuestions;
    protected array $headings;

    public function __construct(int $surveyId, array $englishQuestions, array $headings)
    {
        $this->surveyId = $surveyId;
        $this->englishQuestions = $englishQuestions;
        $this->headings = $headings;
    }

    public function title(): string
    {
        return 'All';
    }

    public function headings(): array
    {
        return $this->headings;
    }

    public function collection()
    {
        // Get ALL members who started this survey
        $allProgresses = SurveyProgress::where('survey_id', $this->surveyId)
            ->with(['member.group', 'member.county'])
            ->get();

        return $this->buildData($allProgresses);
    }

    protected function buildData($progresses)
    {
        // Get all responses for this survey
        $allResponses = SurveyResponse::where('survey_id', $this->surveyId)
            ->get();

        // Group responses by normalized phone number for reliable matching
        $responsesByPhone = collect();
        foreach ($allResponses as $response) {
            $normalizedPhone = normalizePhoneNumber($response->msisdn);
            if (!$responsesByPhone->has($normalizedPhone)) {
                $responsesByPhone[$normalizedPhone] = collect();
            }
            $responsesByPhone[$normalizedPhone]->push($response);
        }

        $data = collect();

        foreach ($progresses as $progress) {
            $member = $progress->member;
            if (!$member) {
                continue;
            }
            
            $msisdn = $member->phone;
            $normalizedMemberPhone = normalizePhoneNumber($msisdn);
            
            $memberResponses = $responsesByPhone->get($normalizedMemberPhone, collect());
            // Group by question_id and get the latest response for each question
            $responseMap = $memberResponses
                ->sortByDesc('created_at')
                ->groupBy('question_id')
                ->map(function ($responses) {
                    return $responses->first(); // Get the latest (already sorted by created_at desc)
                });

            // Build row with member details
            $row = [
                $member->group->name ?? '',
                $member->name ?? '',
                $member->email ?? '',
                $msisdn ?? '',
                $member->national_id ?? '',
                $member->gender ?? '',
                $member->dob ? $member->dob->format('Y-m-d') : '',
                $member->marital_status ?? '',
                $member->county->name ?? '',
            ];

            // Add answers for each English question (check both English and Kiswahili)
            foreach ($this->englishQuestions as $question) {
                $englishQuestionId = $question['id'];
                $swahiliQuestionId = $question['swahili_question_id'] ?? null;
                
                // Check English answer first, then Kiswahili
                $englishResponse = $responseMap->get($englishQuestionId);
                $swahiliResponse = $swahiliQuestionId ? $responseMap->get($swahiliQuestionId) : null;
                
                $answer = $englishResponse 
                    ? $englishResponse->survey_response 
                    : ($swahiliResponse ? $swahiliResponse->survey_response : '');
                
                $row[] = $answer;
            }

            $data->push($row);
        }

        return $data;
    }
}
