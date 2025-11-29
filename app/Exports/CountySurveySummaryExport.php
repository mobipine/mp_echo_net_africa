<?php

namespace App\Exports;

use App\Models\County;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Events\AfterSheet;

class CountySurveySummaryExport implements 
    FromCollection, 
    WithHeadings, 
    ShouldAutoSize,
    WithEvents
{
    protected ?array $filters;
    protected ?array $selectedIds;

    public function __construct(array $filters = [], ?array $selectedIds = null)
    {
        $this->filters = $filters;
        $this->selectedIds = $selectedIds;
    }

    public function headings(): array
    {
        return [
            'County',
            'Total',
            'Completed',
            'Ongoing',
            'Cancelled',
        ];
    }

    public function collection()
    {
        $query = County::query()
            ->select([
                'counties.id',
                'counties.name',

                DB::raw('(SELECT COUNT(*) 
                    FROM survey_progress
                    JOIN members ON members.id = survey_progress.member_id
                    WHERE members.county_id = counties.id' 
                    . $this->filterSQL(true) .
                ') as total'),

                DB::raw('(SELECT COUNT(*) 
                    FROM survey_progress
                    JOIN members ON members.id = survey_progress.member_id
                    WHERE members.county_id = counties.id
                    AND survey_progress.completed_at IS NOT NULL'
                    . $this->filterSQL(true) .
                ') as completed'),

                DB::raw('(SELECT COUNT(*) 
                    FROM survey_progress
                    JOIN members ON members.id = survey_progress.member_id
                    WHERE members.county_id = counties.id
                    AND survey_progress.status IN ("ACTIVE","PENDING","UPDATING_DETAILS")'
                    . $this->filterSQL(true) .
                ') as ongoing'),

                DB::raw('(SELECT COUNT(*) 
                    FROM survey_progress
                    JOIN members ON members.id = survey_progress.member_id
                    WHERE members.county_id = counties.id
                    AND survey_progress.status = "CANCELLED"'
                    . $this->filterSQL(true) .
                ') as cancelled'),
            ]);

        // Filter by selected counties
        if (!empty($this->selectedIds)) {
            $query->whereIn('counties.id', $this->selectedIds);
        }

        // Filter by county_id filter
        if (!empty($this->filters['county_id'])) {
            $query->where('counties.id', $this->filters['county_id']);
        }

        $rows = $query->get();

        $export = collect();
        $totalTotals = $totalCompleted = $totalOngoing = $totalCancelled = 0;

        foreach ($rows as $row) {
            $totalTotals += $row->total;
            $totalCompleted += $row->completed;
            $totalOngoing += $row->ongoing;
            $totalCancelled += $row->cancelled;

            $export->push([
                $row->name,
                $row->total != null ? $row->total : '0',
                $row->completed != null ? $row->completed : '0',
                $row->ongoing != null ? $row->ongoing : '0',
                $row->cancelled != null ? $row->cancelled : '0',
            ]);
        }

        // Add totals row
        $export->push([
            'TOTALS',
            $totalTotals != null ? $totalTotals : '0',
            $totalCompleted != null ? $totalCompleted : '0',
            $totalOngoing != null ? $totalOngoing : '0',
            $totalCancelled != null ? $totalCancelled : '0',
        ]);

        return new Collection($export);
    }

    private function filterSQL(bool $ignoreCounty): string
    {
        $sql = '';

        if (!empty($this->filters['survey_id'])) {
            $sql .= " AND survey_progress.survey_id = {$this->filters['survey_id']}";
        }

        if (!empty($this->filters['group_id'])) {
            $sql .= " AND members.group_id = {$this->filters['group_id']}";
        }

        if (!$ignoreCounty && !empty($this->filters['county_id'])) {
            $sql .= " AND members.county_id = {$this->filters['county_id']}";
        }

        return $sql;
    }

    /**
     * Styling: make headings and totals row bold
     */
    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $event) {
                $sheet = $event->sheet;

                // Bold headings
                $sheet->getStyle('A1:E1')->applyFromArray([
                    'font' => ['bold' => true],
                ]);

                // Bold totals row
                $lastRow = $sheet->getHighestRow();

                $sheet->getStyle("A{$lastRow}:E{$lastRow}")->applyFromArray([
                    'font' => ['bold' => true],
                ]);
            },
        ];
    }
}
