<?php

namespace App\Imports;

use App\Imports\Support\MemberRowAnalyzer;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithChunkReading;
use Maatwebsite\Excel\Concerns\WithHeadingRow;

/**
 * Dry-run analysis for FinanceBatchImport.
 *
 * Reports what the real import WOULD do without writing anything: create vs update,
 * how many members are reachable (have a usable phone) — which is what actually
 * determines SMS volume and credit cost — plus the test/main batch split and any
 * data-quality warnings. Runs synchronously so the result can be shown before commit.
 */
class FinanceBatchImportPreview implements ToCollection, WithHeadingRow, WithChunkReading
{
    protected int $rowNumber = 0;

    protected array $summary = [
        'total' => 0,
        'create' => 0,
        'update' => 0,
        'errors' => 0,
        'reachable' => 0,        // members with a usable (non-blank) phone
        'blank_phone' => 0,      // no phone provided
        'invalid_phone' => 0,    // phone provided but failed normalization -> saved blank
        'duplicate_in_file' => 0,
        'blank_national_id' => 0,
        'new_groups' => 0,
        'test_total' => 0,
        'test_reachable' => 0,
        'main_total' => 0,
        'main_reachable' => 0,
    ];

    /** @var array<string,bool> */
    protected array $newGroups = [];

    public function __construct(protected int $testSize = 1000) {}

    public function chunkSize(): int
    {
        return 1000;
    }

    public function collection(Collection $rows)
    {
        $analyzer = new MemberRowAnalyzer();

        foreach ($rows as $row) {
            $this->rowNumber++;
            $this->summary['total']++;
            $isTest = $this->rowNumber <= $this->testSize;

            try {
                $parsed = $analyzer->analyze($row->toArray());
            } catch (\Throwable $e) {
                $this->summary['errors']++;
                continue;
            }

            $this->summary[$parsed['action']]++;

            $reachable = !empty($parsed['phone']);
            if ($reachable) {
                $this->summary['reachable']++;
            }

            if ($isTest) {
                $this->summary['test_total']++;
                if ($reachable) {
                    $this->summary['test_reachable']++;
                }
            } else {
                $this->summary['main_total']++;
                if ($reachable) {
                    $this->summary['main_reachable']++;
                }
            }

            if ($parsed['group_is_new']) {
                $this->newGroups[$parsed['group_name']] = true;
            }

            foreach ($parsed['warnings'] as $warning) {
                if (str_starts_with($warning, 'No phone number')) {
                    $this->summary['blank_phone']++;
                } elseif (str_starts_with($warning, 'Invalid phone number')) {
                    $this->summary['invalid_phone']++;
                } elseif (str_starts_with($warning, 'Duplicate national ID')) {
                    $this->summary['duplicate_in_file']++;
                } elseif (str_starts_with($warning, 'No national ID')) {
                    $this->summary['blank_national_id']++;
                }
            }
        }
    }

    /** @return array<string,mixed> */
    public function result(): array
    {
        $this->summary['new_groups'] = count($this->newGroups);

        return $this->summary;
    }
}
