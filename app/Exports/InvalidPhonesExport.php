<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;

class InvalidPhonesExport implements FromArray, WithHeadings
{
    /** @param array<int,array<string,mixed>> $rows */
    public function __construct(protected array $rows)
    {
    }

    public function array(): array
    {
        // Use positional values so the columns line up with the headings.
        return array_map('array_values', $this->rows);
    }

    public function headings(): array
    {
        return [
            'Spreadsheet Row',
            'Group Name',
            'Name of Participant',
            'National ID',
            'Phone (as in file)',
            'Issue',
        ];
    }
}
