<?php

namespace App\Imports;

use App\Models\Member;
use App\Models\Group;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Illuminate\Support\Str;
use Carbon\Carbon;

class MembersImport implements ToCollection, WithHeadingRow
{
    public function collection(Collection $rows)
    {
        $importedCount = 0;
        $skippedCount = 0;
        
        foreach ($rows as $index => $row) {
            try {
                // Skip if required fields are missing
                if (empty($row['name']) || empty($row['email']) || empty($row['national_id'])) {
                    $skippedCount++;
                    continue;
                }

                // Check if email already exists
                if (Member::where('email', $row['email'])->exists()) {
                    $skippedCount++;
                    continue;
                }

                // Check if national_id already exists
                if (Member::where('national_id', $row['national_id'])->exists()) {
                    $skippedCount++;
                    continue;
                }

                // Find or create group
                $group = Group::firstOrCreate(
                    ['name' => $row['group'] ?? 'Default Group'],
                    ['description' => 'Imported group - ' . ($row['group'] ?? 'Default')]
                );

                // Generate unique account number
                $accountNumber = $this->generateUniqueAccountNumber();

                // Parse date of birth
                $dob = null;
                if (!empty($row['date_of_birth'])) {
                    try {
                        $dob = Carbon::parse($row['date_of_birth']);
                    } catch (\Exception $e) {
                        $dob = null;
                    }
                }

                Member::create([
                    'group_id' => $group->id,
                    'name' => $row['name'],
                    'account_number' => $accountNumber,
                    'email' => $row['email'],
                    'phone' => $row['phone'] ?? null,
                    'national_id' => $row['national_id'],
                    'gender' => $this->normalizeGender($row['gender'] ?? 'male'),
                    'dob' => $dob,
                    'marital_status' => $this->normalizeMaritalStatus($row['marital_status'] ?? 'single'),
                    'is_active' => $this->normalizeBoolean($row['is_active'] ?? true),
                ]);

                $importedCount++;

            } catch (\Exception $e) {
                $skippedCount++;
                continue;
            }
        }

        // Store import results in session for display
        session()->flash('import_results', [
            'imported' => $importedCount,
            'skipped' => $skippedCount
        ]);
    }

    /**
     * Generate a unique account number
     */
    private function generateUniqueAccountNumber(): string
    {
        $lastMember = Member::orderBy('id', 'desc')->first();
        $lastId = $lastMember ? $lastMember->id : 0;
        
        $accountNumber = '';
        $attempts = 0;
        
        do {
            $attempts++;
            $nextId = $lastId + $attempts;
            $accountNumber = 'ACC-' . Str::padLeft($nextId, 4, '0');
            
            // Check if this account number already exists
            $exists = Member::where('account_number', $accountNumber)->exists();
            
        } while ($exists && $attempts < 100); // Safety limit to prevent infinite loop
        
        return $accountNumber;
    }

    /**
     * Normalize gender input
     */
    private function normalizeGender($gender): string
    {
        $gender = strtolower(trim($gender));
        return in_array($gender, ['male', 'female']) ? $gender : 'male';
    }

    /**
     * Normalize marital status input
     */
    private function normalizeMaritalStatus($status): string
    {
        $status = strtolower(trim($status));
        return in_array($status, ['single', 'married']) ? $status : 'single';
    }

    /**
     * Normalize boolean input
     */
    private function normalizeBoolean($value): bool
    {
        if (is_bool($value)) return $value;
        if (is_numeric($value)) return (bool)$value;
        
        $value = strtolower(trim($value));
        return in_array($value, ['true', 'yes', '1', 'active']);
    }
}