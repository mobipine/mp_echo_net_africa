<?php

namespace App\Exports;

use App\Models\Group;
use Illuminate\Contracts\Support\Responsable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class GroupSurveySummaryExport implements FromCollection, WithHeadings, ShouldAutoSize, Responsable, WithStyles
{
    use \Maatwebsite\Excel\Concerns\Exportable;

    protected array $filters;
    protected ?array $selectedIds;
    protected string $fileName;

    /**
     * @param array $filters  // e.g. $this->filters from widget
     * @param array|null $selectedIds // optional: selected group ids to export
     * @param string|null $filename
     */
    public function __construct(array $filters = [], ?array $selectedIds = null, ?string $filename = null)
    {
        $this->filters = $filters;
        $this->selectedIds = $selectedIds;
        $this->fileName = $filename ?: 'group_survey_summary_' . now()->format('Y_m_d_H_i_s') . '.xlsx';
    }

    public function filename(): string
    {
        return $this->fileName;
    }

    public function headings(): array
    {
        return [
            'Group',
            'Total',
            'Completed',
            'Ongoing',
            'Cancelled',
            // add other headers you want exported
        ];
    }

    public function collection()
    {

        Log::info("In the export");
        $groupIds = $this->filters['group_id'] ?? null;
        $surveyId = $this->filters['survey_id'] ?? null;
        $countyId = $this->filters['county_id'] ?? null;

        $query = Group::query();

        if (!empty($groupIds)) {
            $groupIds = is_array($groupIds) ? $groupIds : [$groupIds];
            $query->whereIn('id', $groupIds);
        }

        if (!empty($this->selectedIds)) {
            // If user selected specific rows, limit to those
            $query->whereIn('id', $this->selectedIds);
        }

        // reuse the same withCount closures as your widget (ensures consistency)
        $query->withCount([
            'members as total_progresses' => function ($q) use ($surveyId, $countyId) {
                if (!empty($countyId)) {
                    $q->where('county_id', $countyId);
                }
                $q->whereHas('surveyProgresses', function ($sub) use ($surveyId) {
                    if (!empty($surveyId)) {
                        $sub->where('survey_id', $surveyId);
                    }
                });
            },
            'members as completed_progresses' => function ($q) use ($surveyId, $countyId) {
                if (!empty($countyId)) {
                    $q->where('county_id', $countyId);
                }
                $q->whereHas('surveyProgresses', function ($sub) use ($surveyId) {
                    if (!empty($surveyId)) {
                        $sub->where('survey_id', $surveyId);
                    }
                    $sub->whereNotNull('completed_at')
                        ->where('status', 'COMPLETED');
                });
            },
            'members as ongoing_progresses' => function ($q) use ($surveyId, $countyId) {
                if (!empty($countyId)) {
                    $q->where('county_id', $countyId);
                }
                $q->whereHas('surveyProgresses', function ($sub) use ($surveyId) {
                    if (!empty($surveyId)) {
                        $sub->where('survey_id', $surveyId);
                    }
                    $sub->whereNull('completed_at')
                        ->whereIn('status', ['ACTIVE', 'UPDATING_DETAILS', 'PENDING']);
                });
            },
            'members as cancelled_progresses' => function ($q) use ($surveyId, $countyId) {
                if (!empty($countyId)) {
                    $q->where('county_id', $countyId);
                }
                $q->whereHas('surveyProgresses', function ($sub) use ($surveyId) {
                    if (!empty($surveyId)) {
                        $sub->where('survey_id', $surveyId);
                    }
                    $sub->where('status', 'CANCELLED');
                });
            },
        ]);

        $rows = $query->get(['id', 'name', 'total_progresses', 'completed_progresses', 'ongoing_progresses', 'cancelled_progresses']);

        // ensure numeric types and compute totals
        $totalTotal = 0;
        $totalCompleted = 0;
        $totalOngoing = 0;
        $totalCancelled = 0;

        $exportRows = collect();

        foreach ($rows as $g) {
            $total =  (int) ($g->total_progresses ?? 0);
            $completed =  (int) ($g->completed_progresses ?? 0);
            $ongoing = (int) ($g->ongoing_progresses ?? 0);
            $cancelled = (int) ($g->cancelled_progresses ?? 0);

            $exportRows->push([
                $g->name,
                $total != null ? $total : '0',
                $completed != null ? $completed : '0',
                $ongoing != null ? $ongoing : '0',
                $cancelled != null ? $cancelled : '0',
            ]);

            $totalTotal += $total;
            $totalCompleted += $completed;
            $totalOngoing += $ongoing;
            $totalCancelled += $cancelled;
        }

        // Append a totals row
        $exportRows->push([
            'TOTALS',
            $totalTotal != null ?  $totalTotal : '0',
            $totalCompleted != null ?  $totalCompleted : '0',
            $totalOngoing != null ?  $totalOngoing : '0',
            $totalCancelled != null ?  $totalCancelled : '0',
        ]);

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
