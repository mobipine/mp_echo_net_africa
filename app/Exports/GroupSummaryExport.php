<?php

namespace App\Exports;

use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;

class GroupSummaryExport implements FromCollection, WithHeadings
{
    protected Collection $records;

    public function __construct(Collection $records)
    {
        $this->records = $records;
    }

    public function collection()
    {
        return $this->records->map(function ($group) {
            return [
                'Group Name' => $group->name,
                'Total' => $group->total_progresses,
                'Completed' => $group->completed_progresses,
                'Ongoing' => $group->ongoing_progresses,
                'Cancelled' => $group->cancelled_progresses,
            ];
        });
    }

    public function headings(): array
    {
        return ['Group Name', 'Total', 'Completed', 'Ongoing', 'Cancelled'];
    }
}
