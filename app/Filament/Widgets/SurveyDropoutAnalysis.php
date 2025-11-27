<?php

namespace App\Filament\Widgets;

use App\Models\SurveyProgress;
use App\Filament\Pages\SurveyReports;
use Filament\Widgets\ChartWidget;
use Illuminate\Support\Facades\DB;

class SurveyDropoutAnalysis extends ChartWidget
{
    public ?array $filters = [];

    protected static ?string $heading = 'Survey Dropout Analysis (Incomplete)';
    protected static ?string $page = SurveyReports::class;
    protected static ?int $sort = 2;

    public static function canView(): bool
    {
        // Returning false prevents the widget from being automatically displayed 
        // on the dashboard or resource pages.
        return false; 
    }
    
    protected function getData(): array
    {
        $groupIds = $this->filters['group_id'] ?? null;
        $surveyId = $this->filters['survey_id'] ?? null;
        $countyId = $this->filters['county_id'] ?? null;

        $query = SurveyProgress::query()
            ->select('current_question_id', DB::raw('COUNT(*) as total_stoppages'))
            ->whereNull('completed_at')
            ->whereIn('status', ['ACTIVE', 'PENDING', 'UPDATING_DETAILS']);

        // Filter by survey
        if (!empty($surveyId)) {
            $query->where('survey_id', $surveyId);
        }

        // Filter by group(s)
        if (!empty($groupIds)) {
            $groupIds = is_array($groupIds) ? $groupIds : [$groupIds];
            $query->whereHas('member', function ($q) use ($groupIds) {
                $q->whereIn('group_id', $groupIds);
            });
        }
        if (!empty($countyId)) {
            $query->whereHas('member', function ($q) use ($countyId) {
                $q->where('county_id', $countyId);
            });
        }

        $dropoutData = $query
            ->groupBy('current_question_id')
            ->orderByDesc('total_stoppages')
            ->get();

        $labels = [];
        $data = [];

        foreach ($dropoutData as $item) {
            $questionText = $item->currentQuestion?->question ?? 'Not Started / Error';
            $labels[] = substr($questionText, 0, 30) . '...';
            $data[] = $item->total_stoppages;
        }

        return [
            'datasets' => [
                [
                    'label' => 'Members Stopped',
                    'data' => $data,
                    'backgroundColor' => '#36A2EB',
                    'borderColor' => '#9BD0F5',
                ],
            ],
            'labels' => $labels,
        ];
    }

    protected function getType(): string
    {
        return 'bar';
    }
}
