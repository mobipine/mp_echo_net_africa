<?php

namespace App\Exports;

use App\Models\SurveyProgress;
use App\Models\Question;
use App\Models\SurveyQuestion;
use Illuminate\Support\Collection;
use Illuminate\Contracts\Support\Responsable;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
class SurveyDropoutExport implements FromCollection, WithHeadings, ShouldAutoSize, Responsable, WithStyles
{
    use \Maatwebsite\Excel\Concerns\Exportable;

    protected array $filters;
    protected ?array $selectedIds;
    protected string $fileName;

    /**
     * @param array $filters     Filters from widget (survey_id, group_id, county_id)
     * @param array|null $selectedIds Selected SurveyProgress IDs (optional)
     * @param string|null $filename  Custom filename (optional)
     */
    public function __construct(array $filters = [], ?array $selectedIds = null, ?string $filename = null)
    {
        $this->filters = $filters;
        $this->selectedIds = $selectedIds;
        $this->fileName = $filename ?: 'survey_dropout_' . now()->format('Y_m_d_H_i_s') . '.xlsx';
    }

    public function filename(): string
    {
        return $this->fileName;
    }

    public function headings(): array
    {
        return [
            'Question',
            'Members Stopped',
        ];
    }

    public function collection()
    {
        $groupIds = $this->filters['group_id'] ?? null;
        $surveyId = $this->filters['survey_id'] ?? null;
        $countyId = $this->filters['county_id'] ?? null;

        // Base query for SurveyProgress
        $baseQuery = SurveyProgress::query()
            ->whereNull('completed_at')
            ->whereIn('status', ['ACTIVE', 'PENDING', 'UPDATING_DETAILS']);

        if (!empty($surveyId)) {
            $baseQuery->where('survey_id', $surveyId);
        }

        if (!empty($groupIds)) {
            $groupIds = is_array($groupIds) ? $groupIds : [$groupIds];
            $baseQuery->whereHas('member', function ($q) use ($groupIds) {
                $q->whereIn('group_id', $groupIds);
            });
        }

        if (!empty($countyId)) {
            $baseQuery->whereHas('member', function ($q) use ($countyId) {
                $q->where('county_id', $countyId);
            });
        }

        if (!empty($this->selectedIds)) {
            $baseQuery->whereIn('id', $this->selectedIds);
        }

        // Get distinct question IDs
        $questionIds = $baseQuery->pluck('current_question_id')->unique();

        // Preload all question texts
        $questions = SurveyQuestion::whereIn('id', $questionIds)
            ->pluck('question', 'id'); // [id => text]

        $exportRows = collect();
        $totalStoppages = 0;

        foreach ($questionIds as $questionId) {
            $countQuery = SurveyProgress::query()
                ->whereNull('completed_at')
                ->whereIn('status', ['ACTIVE', 'PENDING', 'UPDATING_DETAILS'])
                ->where('current_question_id', $questionId);

            if (!empty($surveyId)) $countQuery->where('survey_id', $surveyId);
            if (!empty($groupIds)) {
                $countQuery->whereHas('member', fn($q) => $q->whereIn('group_id', $groupIds));
            }
            if (!empty($countyId)) {
                $countQuery->whereHas('member', fn($q) => $q->where('county_id', $countyId));
            }

            $stopped = (int) $countQuery->count();

            $exportRows->push([
                $questions[$questionId] ?? 'Not Started / Error',
                $stopped,
            ]);

            $totalStoppages += $stopped;
        }

        // Totals row
        $exportRows->push([
            'TOTALS',
            $totalStoppages,
        ]);

        // Handle case when no records exist
        if ($exportRows->isEmpty()) {
            $exportRows->push([
                'No Data',
                0,
            ]);
        }

        return new Collection($exportRows->toArray());
    }
    public function styles(Worksheet $sheet)
    {
        $lastRow = $sheet->getHighestRow(); // get the last row (your totals row)
        return [
            1 => ['font' => ['bold' => true]],  
            $lastRow => ['font' => ['bold' => true]], // make the entire row bold
        ];
    }
}
