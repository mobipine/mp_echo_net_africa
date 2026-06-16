<?php

namespace App\Imports;

use App\Imports\Support\MemberRowAnalyzer;
use App\Models\Member;
use App\Models\Group;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Illuminate\Support\Facades\Log;
use Maatwebsite\Excel\Concerns\WithChunkReading;
use Illuminate\Contracts\Queue\ShouldQueue;

class MembersImport implements ToCollection, WithHeadingRow, WithChunkReading, ShouldQueue
{
    public function chunkSize(): int
    {
        return 1000; // change to suit memory/throughput
    }

    public function collection(Collection $rows)
    {
        $importedCount = 0;
        $updatedCount = 0;
        $skippedCount = 0;

        $analyzer = new MemberRowAnalyzer();

        foreach ($rows as $index => $row) {
            try {
                $parsed = $analyzer->analyze($row->toArray());

                // Create or get group
                $group = Group::firstOrCreate(
                    ['name' => $parsed['group_name']],
                    ['description' => 'Imported group - ' . $parsed['group_name']]
                );

                // Use a transaction to ensure safe updates
                DB::transaction(function () use (
                    $group,
                    $parsed,
                    &$importedCount,
                    &$updatedCount
                ) {
                    // Check if member exists
                    $existing = Member::where('national_id', $parsed['national_id'])->first();

                    if ($existing) {
                        // Update only missing or outdated fields
                        $existing->update([
                            'name' => $existing->name ?: $parsed['name'],
                            'phone' => $parsed['phone'] ?: $existing->phone,
                            'gender' => $parsed['gender'] ?: $existing->gender,
                            'dob' => $parsed['dob'] ?: $existing->dob,
                            'county_id' => $existing->county_id,
                            'is_active' => true,
                        ]);

                        // Sync groups (add group if not already associated)
                        if (!$existing->groups()->where('groups.id', $group->id)->exists()) {
                            $existing->groups()->attach($group->id);
                        }

                        $updatedCount++;
                    } else {
                        // Create new member
                        $member = Member::create([
                            'name' => $parsed['name'],
                            'phone' => $parsed['phone'] ?: null,
                            'national_id' => $parsed['national_id'],
                            'gender' => $parsed['gender'],
                            'county_id' => null,
                            'dob' => $parsed['dob'],
                            'is_active' => true,
                        ]);

                        // Attach group
                        $member->groups()->attach($group->id);

                        $importedCount++;
                    }
                });
            } catch (\Exception $e) {
                Log::error('Import Error: ' . $e->getMessage(), [
                    'row_index' => $index,
                    'row_data' => $row,
                ]);
                $skippedCount++;
                continue;
            }
        }

        Log::info("Import Process Completed. Imported: {$importedCount}, Updated: {$updatedCount}, Skipped: {$skippedCount}");

        // Flash results for user feedback
        session()->flash('import_results', [
            'imported' => $importedCount,
            'updated' => $updatedCount,
            'skipped' => $skippedCount,
        ]);
    }
}
