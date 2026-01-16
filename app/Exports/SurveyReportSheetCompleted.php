<?php

namespace App\Exports;

use App\Models\SurveyProgress;
use App\Models\SurveyResponse;
use Maatwebsite\Excel\Concerns\WithTitle;

class SurveyReportSheetCompleted extends SurveyReportSheetAll
{
    public function title(): string
    {
        return 'Completed';
    }

    public function collection()
    {
        // Pre-load all responses once (usually smaller dataset than progresses)
        // Group by normalized phone for efficient lookup
        $allResponses = SurveyResponse::where('survey_id', $this->surveyId)
            ->select('msisdn', 'question_id', 'survey_response', 'created_at')
            ->orderBy('created_at', 'desc')
            ->get()
            ->groupBy(function ($response) {
                return normalizePhoneNumber($response->msisdn);
            })
            ->map(function ($responses) {
                // Group by question_id and get latest response
                return $responses->groupBy('question_id')->map(function ($qResponses) {
                    return $qResponses->first();
                });
            });

        // Use chunking to avoid memory issues with large datasets
        $data = collect();

        // Process completed progresses in chunks
        SurveyProgress::where('survey_id', $this->surveyId)
            ->whereNotNull('completed_at')
            ->where('status', 'COMPLETED')
            ->with(['member.group', 'member.county'])
            ->chunk(1000, function ($progresses) use (&$data, $allResponses) {
                $chunkData = $this->buildData($progresses, $allResponses);
                $data = $data->merge($chunkData);
            });

        return $data;
    }
}
