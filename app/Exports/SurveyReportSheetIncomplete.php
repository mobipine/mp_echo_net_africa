<?php

namespace App\Exports;

use App\Models\SurveyProgress;
use Maatwebsite\Excel\Concerns\WithTitle;

class SurveyReportSheetIncomplete extends SurveyReportSheetAll
{
    public function title(): string
    {
        return 'Incomplete';
    }

    public function collection()
    {
        $incompleteProgresses = SurveyProgress::where('survey_id', $this->surveyId)
            ->where(function($query) {
                $query->whereNull('completed_at')
                      ->orWhere('status', '!=', 'COMPLETED');
            })
            ->with(['member.group', 'member.county'])
            ->get();

        return $this->buildData($incompleteProgresses);
    }
}
