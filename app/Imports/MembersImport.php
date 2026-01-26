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

        foreach ($rows as $row) {
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

                $phoneLooksLikeId = false;
                // Check if Phone field looks like an ID (or is empty)
                // National IDs are typically 7-8 digits in Kenya. 
                // We assume if it's 8 digits or less, it's NOT a valid phone number (too short)
                if (strlen($cleanPhone) <= 8) {
                    $phoneLooksLikeId = true;
                }

                if ($idLooksLikePhone && $phoneLooksLikeId) {
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

               

                // Normalize phone number - format all phone numbers to ensure they're valid
                if (!empty($phone)) {
                    // Remove all non-digit characters
                    $phone = preg_replace('/\D/', '', $phone);
                    $originalPhone = $phone;
                    
                    // Handle different phone number formats
                    // If it starts with 254 (country code), convert to 0 format
                    if (substr($phone, 0, 3) === "254") {
                        $phone = "0" . substr($phone, 3);
                    }
                    
                    // If it's 13 digits starting with 0, it might be 0 + 254 + 9 digits
                    // Remove the extra leading 0 and 254
                    if (strlen($phone) === 13 && substr($phone, 0, 1) === "0" && substr($phone, 1, 3) === "254") {
                        $phone = "0" . substr($phone, 4);
                    }
                    
                    // If it's 12 digits starting with 0, it might be 0 + 254 + 8 digits
                    // Remove the 254 part
                    if (strlen($phone) === 12 && substr($phone, 0, 1) === "0" && substr($phone, 1, 3) === "254") {
                        $phone = "0" . substr($phone, 4);
                    }
                    
                    // If it's 11 digits starting with 0, take the last 10 digits (remove first digit)
                    if (strlen($phone) === 11 && substr($phone, 0, 1) === "0") {
                        $phone = "0" . substr($phone, 2);
                    }
                    
                    // If it's 9 digits, add a leading 0 (assuming missing first digit)
                    if (strlen($phone) === 9) {
                        $phone = "0" . $phone;
                    }
                    
                    // Ensure it starts with 0
                    if (strlen($phone) > 0 && substr($phone, 0, 1) !== "0") {
                        $phone = "0" . ltrim($phone, '0');
                    }
                    
                    // If still not 10 digits, try to fix it
                    if (strlen($phone) > 10) {
                        // Take last 10 digits (ensuring it starts with 0)
                        $phone = "0" . substr($phone, -9);
                    } elseif (strlen($phone) < 10 && strlen($phone) > 0) {
                        // Pad with leading zeros if less than 10 digits
                        $phone = str_pad($phone, 10, "0", STR_PAD_LEFT);
                    }
                    
                    // Final validation - if still not 10 digits, set to null but don't skip the row
                    if (strlen($phone) !== 10) {
                        Log::warning("Phone number could not be normalized properly (original: {$originalPhone}, normalized: {$phone}), setting to null");
                        $phone = null;
                    } elseif ($originalPhone !== $phone) {
                        // Only log if the phone number was actually changed during normalization
                        // Log::info("Phone number normalized: {$originalPhone} -> {$phone}");
                    }
                } else {
                    $phone = null;
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
                Log::error('Import Error: ' . $e->getMessage() . ' | Skipped Row Data: ' . json_encode($row));
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

    private function normalizeGender($gender): string
    {
        $gender = strtolower(trim($gender));
        return in_array($gender, ['male', 'female']) ? $gender : 'female';
    }
}
