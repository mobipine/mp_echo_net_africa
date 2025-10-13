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
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
class MembersImport implements ToCollection, WithHeadingRow
{
    public function collection(Collection $rows)
    {
        $importedCount = 0;
        $skippedCount = 0;

        foreach ($rows as $row) {
            try {
                $groupName = trim($row['group_name'] ?? '');
                $name = trim($row['name_of_participant'] ?? '');
                $phone = trim($row['phone_no'] ?? '');
                $nationalId = trim($row['national_id'] ?? '');
                $gender = trim($row['gender'] ?? '');
                $dob = $row['year_birth'] ?? null;

                // Skip empty or invalid rows
                if (empty($name) || empty($nationalId)) {
                    $skippedCount++;
                    continue;
                }

                // Skip if national ID already exists
                if (Member::where('national_id', $nationalId)->exists()) {
                    $skippedCount++;
                    continue;
                }

                // Normalize phone number (ensure leading 0)
                if (!empty($phone) && !Str::startsWith($phone, '0')) {
                    $phone = '0' . $phone;
                }

                // Create group if not exists
                $group = Group::firstOrCreate(
                    ['name' => $groupName ?: 'Default Group'],
                    ['description' => 'Imported group - ' . ($groupName ?: 'Default')]
                );

                // Parse date of birth
                $dobCarbon = null;
                if (!empty($dob)) {
                    try {
                        if (is_numeric($dob)) {
                            // Convert Excel serial number to Carbon
                            $dobCarbon = Carbon::instance(ExcelDate::excelToDateTimeObject($dob));
                        } else {
                            // Parse normal date formats like "1/1/1989"
                            $dobCarbon = Carbon::parse($dob);
                        }
                    } catch (\Exception $e) {
                        $dobCarbon = null;
                    }
                }

                // Use a transaction to avoid duplicate account numbers
                DB::transaction(function () use ($group, $name, $phone, $nationalId, $gender, $dobCarbon, &$importedCount, &$skippedCount) {
                    // Create member first to get an auto-increment ID
                    $member = Member::create([
                        'group_id' => $group->id,
                        'name' => $name,
                        'phone' => $phone ?: null,
                        'national_id' => $nationalId,
                        'gender' => $this->normalizeGender($gender),
                        'dob' => $dobCarbon,
                        'is_active' => true,
                    ]);

                    $importedCount++;
                });
            } catch (\Exception $e) {
                Log::info($e);
                $skippedCount++;
                continue;
              
            }
        }

        // Flash results to session for user feedback
        session()->flash('import_results', [
            'imported' => $importedCount,
            'skipped' => $skippedCount,
        ]);
    }

    /**
     * Normalize gender input
     */
    private function normalizeGender($gender): string
    {
        $gender = strtolower(trim($gender));
        return in_array($gender, ['male', 'female']) ? $gender : 'female';
    }
}
