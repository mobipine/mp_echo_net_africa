<?php

namespace App\Imports;

use App\Imports\Support\MemberRowAnalyzer;
use App\Models\Group;
use App\Models\Member;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithChunkReading;
use Maatwebsite\Excel\Concerns\WithHeadingRow;

/**
 * Imports the ENAF member file for the Finance survey rollout.
 *
 * Behaves exactly like App\Imports\MembersImport (same MemberRowAnalyzer parsing,
 * same create/update + real-group attach) but ADDITIONALLY tags every member into
 * one of two dedicated, file-order batch groups so the Finance survey can be
 * dispatched to a controlled test slice first, then the remainder:
 *
 *   - rows 1..testSize        -> "Finance Test Batch 2026-06"
 *   - rows testSize+1..end    -> "Finance Main Batch 2026-06"
 *
 * Members <-> groups is many-to-many (group_member), so a member sits in both its
 * real named group and a batch group with no conflict. No GroupSurvey assignment is
 * created here, so surveys:due-dispatch never auto-touches these batch groups —
 * dispatch happens only via the admin "Dispatch Survey To Multiple Groups" page.
 *
 * Run synchronously (NOT queued) so the caller gets the final counts to report.
 */
class FinanceBatchImport implements ToCollection, WithHeadingRow, WithChunkReading
{
    public const TEST_GROUP_NAME = 'Finance Test Batch 2026-06';
    public const MAIN_GROUP_NAME = 'Finance Main Batch 2026-06';

    /** Running 1-based row counter that persists across chunks (Excel reads in order). */
    protected int $rowNumber = 0;

    protected int $importedCount = 0;
    protected int $updatedCount = 0;
    protected int $skippedCount = 0;

    protected ?Group $testGroup = null;
    protected ?Group $mainGroup = null;

    public function __construct(protected int $testSize = 1000) {}

    public function chunkSize(): int
    {
        return 1000;
    }

    public function collection(Collection $rows)
    {
        $this->ensureBatchGroups();
        $analyzer = new MemberRowAnalyzer();

        foreach ($rows as $index => $row) {
            $this->rowNumber++;
            $batchGroup = $this->rowNumber <= $this->testSize ? $this->testGroup : $this->mainGroup;

            try {
                $parsed = $analyzer->analyze($row->toArray());

                // Create or get the member's real named group (from the file's "Group Name" column).
                $realGroup = Group::firstOrCreate(
                    ['name' => $parsed['group_name']],
                    ['description' => 'Imported group - ' . $parsed['group_name']]
                );

                DB::transaction(function () use ($parsed, $realGroup, $batchGroup) {
                    $existing = Member::where('national_id', $parsed['national_id'])->first();

                    if ($existing) {
                        $existing->update([
                            'name' => $existing->name ?: $parsed['name'],
                            'phone' => $parsed['phone'] ?: $existing->phone,
                            'gender' => $parsed['gender'] ?: $existing->gender,
                            'dob' => $parsed['dob'] ?: $existing->dob,
                            'county_id' => $existing->county_id,
                            'is_active' => true,
                        ]);

                        $member = $existing;
                        $this->updatedCount++;
                    } else {
                        $member = Member::create([
                            'name' => $parsed['name'],
                            'phone' => $parsed['phone'] ?: null,
                            'national_id' => $parsed['national_id'],
                            'gender' => $parsed['gender'],
                            'county_id' => null,
                            'dob' => $parsed['dob'],
                            'is_active' => true,
                        ]);

                        $this->importedCount++;
                    }

                    // Attach real group + batch group without creating duplicate pivot rows.
                    $member->groups()->syncWithoutDetaching([$realGroup->id, $batchGroup->id]);
                });
            } catch (\Exception $e) {
                Log::error('Finance batch import error: ' . $e->getMessage(), [
                    'row_index' => $index,
                    'row_number' => $this->rowNumber,
                ]);
                $this->skippedCount++;
                continue;
            }
        }

        Log::info("FinanceBatchImport chunk done. Imported: {$this->importedCount}, Updated: {$this->updatedCount}, Skipped: {$this->skippedCount}");
    }

    protected function ensureBatchGroups(): void
    {
        $this->testGroup ??= Group::firstOrCreate(
            ['name' => self::TEST_GROUP_NAME],
            ['description' => 'Finance survey test batch (first ' . $this->testSize . ' members) - ' . now()->toDateString()]
        );

        $this->mainGroup ??= Group::firstOrCreate(
            ['name' => self::MAIN_GROUP_NAME],
            ['description' => 'Finance survey main batch (remaining members) - ' . now()->toDateString()]
        );
    }

    /** @return array<string,mixed> */
    public function result(): array
    {
        $this->ensureBatchGroups();

        return [
            'imported' => $this->importedCount,
            'updated' => $this->updatedCount,
            'skipped' => $this->skippedCount,
            'total_rows' => $this->rowNumber,
            'test_group_id' => $this->testGroup->id,
            'test_group_name' => $this->testGroup->name,
            'main_group_id' => $this->mainGroup->id,
            'main_group_name' => $this->mainGroup->name,
        ];
    }
}
