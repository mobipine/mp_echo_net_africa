<?php

namespace App\Imports;

use App\Models\Member;
use App\Models\Group;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Carbon\Carbon;
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

        foreach ($rows as $row) {
            try {
                $groupName = trim($row['group_name'] ?? '');
                $name = trim($row['name_of_participant'] ?? '');
                $phone = trim($row['phone_no'] ?? '');
                $nationalId = trim($row['national_id'] ?? '');
                $gender = trim($row['gender'] ?? '');
                // $dob = $row['year_birth'] ?? $row['year'] ?? null;
                // Handle DOB (year only)
                $dobYear = trim($row['year'] ?? null);
               

                // Skip invalid rows
                if (empty($name) || empty($nationalId)) {
                    $skippedCount++;
                    continue;
                }

                // Normalize phone number
                if (!empty($phone) && !Str::startsWith($phone, '0')) {
                    $phone = '0' . $phone;
                }
                if (strlen($phone) !== 10) {
                    Log::warning("Invalid phone number skipped: {$phone}");

                    $skippedCount++;
                    continue; // skip this row
                }

                // Create or get group
                $group = Group::firstOrCreate(
                    ['name' => $groupName ?: 'Default Group'],
                    ['description' => 'Imported group - ' . ($groupName ?: 'Default')]
                );

                // Handle DOB
                // $dobCarbon = null;
                // if (!empty($dob)) {
                //     try {
                //         if (is_numeric($dob)) {
                //             $dobCarbon = Carbon::instance(\PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject($dob));
                //         } elseif (preg_match('/^\d{4}$/', trim($dob))) {
                //             $dobCarbon = Carbon::createFromDate((int)$dob, 1, 1);
                //         } else {
                //             $dobCarbon = Carbon::parse($dob);
                //         }
                //     } catch (\Exception $e) {
                //         $dobCarbon = null;
                //     }
                // }

                $dobCarbon = null;
                if (!empty($dobYear) && preg_match('/^\d{4}$/', $dobYear)) {
                    $dobCarbon = Carbon::createFromDate((int)$dobYear, 1, 1);
                }

                // Use a transaction to ensure safe updates
                DB::transaction(function () use (
                    $group,
                    $name,
                    $phone,
                    $nationalId,
                    $gender,
                    $dobCarbon,
                    &$importedCount,
                    &$updatedCount
                ) {
                    // Check if member exists
                    $existing = Member::where('national_id', $nationalId)->first();

                    if ($existing) {
                        // Update only missing or outdated fields
                        $existing->update([
                            'group_id' => $group->id,
                            'name' => $existing->name ?: $name,
                            'phone' => $phone ?: $existing->phone,
                            'gender' => $this->normalizeGender($gender) ?: $existing->gender,
                            'dob' => $dobCarbon ?: $existing->dob,
                            'is_active' => true,
                        ]);

                        $updatedCount++;
                    } else {
                        // Create new member
                        Member::create([
                            'group_id' => $group->id,
                            'name' => $name,
                            'phone' => $phone ?: null,
                            'national_id' => $nationalId,
                            'gender' => $this->normalizeGender($gender),
                            'dob' => $dobCarbon,
                            'is_active' => true,
                        ]);

                        $importedCount++;
                    }
                });
            } catch (\Exception $e) {
                Log::error('Import Error: ' . $e->getMessage());
                $skippedCount++;
                continue;
            }
        }

        // Flash results for user feedback
        session()->flash('import_results', [
            'imported' => $importedCount,
            'updated' => $updatedCount,
            'skipped' => $skippedCount,
        ]);
    }

    private function normalizeGender($gender): string
    {
        $gender = strtolower(trim($gender));
        return in_array($gender, ['male', 'female']) ? $gender : 'female';
    }
}
