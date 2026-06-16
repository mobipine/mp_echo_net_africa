<?php

namespace App\Imports;

use App\Imports\Support\MemberRowAnalyzer;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;

/**
 * Dry-run analysis of a members Excel file.
 *
 * Reads the whole file and reports what the real import (App\Imports\MembersImport)
 * WOULD do — without writing anything to the database. Runs synchronously so the
 * result can be shown to the user before they confirm the import.
 */
class MembersImportPreview implements ToCollection, WithHeadingRow
{
    /** Max number of flagged rows kept for display. */
    protected const MAX_FLAGGED_ROWS = 100;

    protected array $summary = [
        'total' => 0,
        'create' => 0,
        'update' => 0,
        'errors' => 0,
        'with_warnings' => 0,
        'new_groups' => [],
        'flagged_rows' => [],
        'flagged_truncated' => false,
        'error_rows' => [],
    ];

    public function collection(Collection $rows)
    {
        $analyzer = new MemberRowAnalyzer();
        $newGroups = [];

        foreach ($rows as $index => $row) {
            $this->summary['total']++;
            $rowNumber = $index + 2; // +1 for heading row, +1 for 1-based

            try {
                $parsed = $analyzer->analyze($row->toArray());
            } catch (\Throwable $e) {
                $this->summary['errors']++;
                if (count($this->summary['error_rows']) < self::MAX_FLAGGED_ROWS) {
                    $this->summary['error_rows'][] = [
                        'row' => $rowNumber,
                        'message' => $e->getMessage(),
                    ];
                }
                continue;
            }

            $this->summary[$parsed['action']]++;

            if ($parsed['group_is_new']) {
                $newGroups[$parsed['group_name']] = true;
            }

            if (!empty($parsed['warnings'])) {
                $this->summary['with_warnings']++;

                if (count($this->summary['flagged_rows']) < self::MAX_FLAGGED_ROWS) {
                    $this->summary['flagged_rows'][] = [
                        'row' => $rowNumber,
                        'name' => $parsed['name'],
                        'national_id' => $parsed['national_id'],
                        'group_name' => $parsed['group_name'],
                        'action' => $parsed['action'],
                        'warnings' => $parsed['warnings'],
                    ];
                } else {
                    $this->summary['flagged_truncated'] = true;
                }
            }
        }

        $this->summary['new_groups'] = array_keys($newGroups);
    }

    /**
     * @return array<string,mixed>
     */
    public function result(): array
    {
        return $this->summary;
    }
}
