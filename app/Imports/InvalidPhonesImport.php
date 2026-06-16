<?php

namespace App\Imports;

use App\Imports\Support\MemberRowAnalyzer;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;

/**
 * Collects the rows from a members file whose phone number cannot be
 * normalized (and, optionally, rows with no phone at all), so the list
 * can be handed back to the client for correction.
 *
 * Uses the same MemberRowAnalyzer as the importer, so the phone handling
 * (including the swapped phone/ID fix) matches exactly what the import does.
 */
class InvalidPhonesImport implements ToCollection, WithHeadingRow
{
    /** @var array<int,array<string,mixed>> */
    protected array $rows = [];

    protected int $scanned = 0;

    public function __construct(protected bool $includeMissing = false)
    {
    }

    public function collection(Collection $rows)
    {
        $analyzer = new MemberRowAnalyzer();

        foreach ($rows as $index => $row) {
            $this->scanned++;
            $parsed = $analyzer->analyze($row->toArray());

            $isInvalid = $parsed['phone_invalid'];
            $isMissing = $this->includeMissing && $parsed['phone_missing'];

            if (!$isInvalid && !$isMissing) {
                continue;
            }

            $this->rows[] = [
                'row' => $index + 2, // +1 heading row, +1 for 1-based numbering
                'group_name' => $parsed['group_name'],
                'name' => $parsed['name'],
                'national_id' => $parsed['national_id'] ?? '',
                'phone_in_file' => $parsed['phone_input'],
                'issue' => $isInvalid ? 'Invalid / incomplete phone number' : 'No phone number',
            ];
        }
    }

    /** @return array<int,array<string,mixed>> */
    public function rows(): array
    {
        return $this->rows;
    }

    public function scanned(): int
    {
        return $this->scanned;
    }
}
