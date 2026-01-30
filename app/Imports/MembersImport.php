<?php

namespace App\Imports;

use App\Models\Member;
use App\Models\Group;
use Propaganistas\LaravelPhone\PhoneNumber;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Maatwebsite\Excel\Concerns\WithChunkReading;
use Illuminate\Contracts\Queue\ShouldQueue;
use App\Models\County;

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

            foreach ($rows as $index => $row) {
            try {
                $groupName = trim($row['group_name'] ?? '');
                $name = trim($row['name_of_participant'] ?? '') ?: 'XXXXX';
                $phone = trim($row['phone_no'] ?? '');
                $nationalId = trim($row['national_id'] ?? '');

                // Check if fields are switched - Logic to fix switched Phone and ID columns
                // Some records have the Phone Number in the ID field and the ID in the Phone field
                $cleanPhone = preg_replace('/\D/', '', $phone);
                $cleanId = preg_replace('/\D/', '', $nationalId);

                $idLooksLikePhone = false;
                // Check if National ID looks like a valid phone number
                // 1. 12 digits starting with 254 (e.g., 254712345678)
                // 2. 10 digits starting with 07 or 01 (e.g., 0712345678, 0123456789)
                // 3. 9 digits starting with 7 or 1 (missing leading 0, e.g., 712345678)
                if (
                    (strlen($cleanId) === 12 && substr($cleanId, 0, 3) === '254') ||
                    (strlen($cleanId) === 10 && (substr($cleanId, 0, 2) === '07' || substr($cleanId, 0, 2) === '01')) ||
                    (strlen($cleanId) === 9 && (substr($cleanId, 0, 1) === '7' || substr($cleanId, 0, 1) === '1'))
                ) {
                    $idLooksLikePhone = true;
                }

                $phoneLooksLikePhone = false;
                // Check if Phone field looks like a valid phone number
                if (
                    (strlen($cleanPhone) === 12 && substr($cleanPhone, 0, 3) === '254') ||
                    (strlen($cleanPhone) === 10 && (substr($cleanPhone, 0, 2) === '07' || substr($cleanPhone, 0, 2) === '01')) ||
                    (strlen($cleanPhone) === 9 && (substr($cleanPhone, 0, 1) === '7' || substr($cleanPhone, 0, 1) === '1'))
                ) {
                    $phoneLooksLikePhone = true;
                }

                // If National ID looks like a valid phone number AND Phone field does NOT,
                // assume they are swapped or the phone number is in the ID field.
                if ($idLooksLikePhone && !$phoneLooksLikePhone) {
                    $temp = $phone;
                    $phone = $nationalId;
                    $nationalId = $temp;
                }

                // Apply default to nationalId if empty
                $nationalId = $nationalId ?: '00000000';
                
                $gender = trim($row['gender'] ?? '');
                // $dob = $row['year_birth'] ?? $row['year'] ?? null;
                // Handle DOB (year only)
                $dobYear = trim($row['year'] ?? null);
                $countyName = trim($row['county_name'] ?? '');


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

                $countyId = null;

                // Commented out county check - no longer validating county presence
                // if (!empty($countyName)) {
                //     $county = County::whereRaw('LOWER(name) = ?', [strtolower($countyName)])->first();

                //     if ($county) {
                //         $countyId = $county->id;
                //     } else {
                //         Log::warning("County not found: {$countyName}");
                //     }
                // }

                // Normalize phone number using Laravel Phone - invalid phones become null but do not skip the row
                $normalizedPhone = $this->normalizePhoneNumber($phone);
                if ($phone && $normalizedPhone === null) {
                    Log::warning("Phone number invalid after Laravel Phone normalization, setting to null", [
                        'row_index' => $index,
                        'original_phone' => $phone,
                        'national_id' => $nationalId,
                    ]);
                }
                $phone = $normalizedPhone;

                // Use a transaction to ensure safe updates
                DB::transaction(function () use (
                    $group,
                    $name,
                    $phone,
                    $nationalId,
                    $gender,
                    $dobCarbon,
                    $countyId,
                    &$importedCount,
                    &$updatedCount
                ) {
                    // Check if member exists
                    $existing = Member::where('national_id', $nationalId)->first();

                    if ($existing) {
                        // Update only missing or outdated fields
                        $existing->update([
                            'name' => $existing->name ?: $name,
                            'phone' => $phone ?: $existing->phone,
                            'gender' => $this->normalizeGender($gender) ?: $existing->gender,
                            'dob' => $dobCarbon ?: $existing->dob,
                            'county_id' => $countyId ?: $existing->county_id,
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
                            'name' => $name,
                            'phone' => $phone ?: null,
                            'national_id' => $nationalId,
                            'gender' => $this->normalizeGender($gender),
                            'county_id' => $countyId,
                            'dob' => $dobCarbon,
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

    /**
     * Normalize and validate a phone number using Laravel Phone.
     * Returns E.164 formatted string or null if invalid/empty.
     */
    private function normalizePhoneNumber(?string $phone): ?string
    {
        $phone = trim((string) $phone);

        if ($phone === '') {
            return null;
        }

        try {
            // Assume Kenya as default region; adjust if you need another.
            $phoneNumber = new PhoneNumber($phone, 'KE');

            return $phoneNumber->formatE164();
        } catch (\Throwable $e) {
            Log::warning('Failed to normalize phone number with Laravel Phone', [
                'phone' => $phone,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    private function normalizeGender($gender): string
    {
        $gender = strtolower(trim($gender));
        return in_array($gender, ['male', 'female']) ? $gender : 'female';
    }
}
