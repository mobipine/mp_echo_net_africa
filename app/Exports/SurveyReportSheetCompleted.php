<?php

namespace App\Exports;

use App\Models\SurveyProgress;
use Maatwebsite\Excel\Concerns\WithTitle;

class SurveyReportSheetCompleted extends SurveyReportSheetAll
{
    public function title(): string
    {
        return 'Completed';
    }

    public function collection()
    {
        $completedProgresses = SurveyProgress::where('survey_id', $this->surveyId)
            ->whereNotNull('completed_at')
            ->where('status', 'COMPLETED')
            ->with(['member.group', 'member.county'])
            ->get();

        return $this->buildData($completedProgresses);
    }
}
