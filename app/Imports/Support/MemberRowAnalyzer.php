<?php

namespace App\Imports\Support;

use App\Models\Group;
use App\Models\Member;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use libphonenumber\NumberParseException;
use Propaganistas\LaravelPhone\PhoneNumber;

/**
 * Parses a single member import row and decides what would happen to it.
 *
 * This is the single source of truth shared by the real importer
 * (App\Imports\MembersImport) and the dry-run preview
 * (App\Imports\MembersImportPreview) so their behaviour can never drift.
 *
 * Instantiate one analyzer per import run: it keeps in-memory state to
 * detect duplicate national IDs and newly-created groups within the file.
 */
class MemberRowAnalyzer
{
    /** @var array<string,bool> national_id => already seen earlier in this file */
    protected array $seenNationalIds = [];

    /** @var array<string,bool> lowercased group name => exists in DB */
    protected array $groupExistsInDb = [];

    /** @var array<string,bool> lowercased group name => already seen earlier in this file */
    protected array $seenGroupNames = [];

    /**
     * Analyze one row.
     *
     * @param  array<string,mixed>  $row  Heading-row keyed values.
     * @return array{
     *     action: 'create'|'update',
     *     name: string,
     *     phone: ?string,
     *     national_id: ?string,
     *     gender: string,
     *     dob: ?Carbon,
     *     group_name: string,
     *     group_is_new: bool,
     *     warnings: array<int,string>
     * }
     */
    public function analyze(array $row): array
    {
        $groupNameRaw = trim((string) ($row['group_name'] ?? ''));
        $groupName = $groupNameRaw !== '' ? $groupNameRaw : 'Default Group';
        $name = trim((string) ($row['name_of_participant'] ?? '')) ?: 'XXXXX';
        // Accept both the legacy "Phone No" header and the "Phone Number" header used by ENAF exports.
        $phone = trim((string) ($row['phone_no'] ?? $row['phone_number'] ?? ''));
        $nationalId = trim((string) ($row['national_id'] ?? ''));

        // --- Detect & fix swapped Phone/National ID columns (mirrors MembersImport) ---
        $cleanPhone = preg_replace('/\D/', '', $phone);
        $cleanId = preg_replace('/\D/', '', $nationalId);

        $idLooksLikePhone = $this->looksLikePhone($cleanId);
        $phoneLooksLikePhone = $this->looksLikePhone($cleanPhone);

        $swapped = false;
        if ($idLooksLikePhone && !$phoneLooksLikePhone) {
            [$phone, $nationalId] = [$nationalId, $phone];
            $swapped = true;
        }

        // A blank national ID becomes NULL (not a shared '00000000' sentinel).
        // national_id is a nullable UNIQUE column, and MySQL permits many NULLs,
        // so ID-less people are stored as distinct members instead of merging.
        $nationalId = $nationalId !== '' ? $nationalId : null;
        $idWasBlank = ($nationalId === null);

        $gender = trim((string) ($row['gender'] ?? ''));

        // --- Date of birth (year only, must be 4 digits) ---
        // Accept both the legacy "Year" header and the "Year Of Birth" header used by ENAF exports.
        $dobYear = trim((string) ($row['year'] ?? $row['year_of_birth'] ?? ''));
        $dobCarbon = null;
        $dobInvalid = false;
        if ($dobYear !== '') {
            if (preg_match('/^\d{4}$/', $dobYear)) {
                $dobCarbon = Carbon::createFromDate((int) $dobYear, 1, 1);
            } else {
                $dobInvalid = true;
            }
        }

        // --- Phone normalization ---
        $normalizedPhone = $this->normalizePhoneNumber($phone);
        $phoneInvalid = ($phone !== '' && $normalizedPhone === null);
        $phoneBlank = ($phone === '');
        $phone = $normalizedPhone;

        // --- Group: does it already exist (DB or earlier in file)? ---
        $groupKey = strtolower($groupName);
        $existsInDb = $this->groupExistsInDb[$groupKey] ??= Group::where('name', $groupName)->exists();
        $seenInFile = $this->seenGroupNames[$groupKey] ?? false;
        $groupIsNew = !$existsInDb && !$seenInFile;
        $this->seenGroupNames[$groupKey] = true;

        // --- Member: create or update? ---
        // Rows without a national ID are always created as new, distinct members
        // (no dedup key to match on). Only ID-bearing rows are deduplicated.
        if ($nationalId === null) {
            $action = 'create';
            $duplicateInFile = false;
        } else {
            $seenBefore = $this->seenNationalIds[$nationalId] ?? false;
            $existingMember = $seenBefore ? true : Member::where('national_id', $nationalId)->exists();
            $action = $existingMember ? 'update' : 'create';
            $duplicateInFile = $seenBefore;
            $this->seenNationalIds[$nationalId] = true;
        }

        // --- Human-readable warnings for the preview ---
        $warnings = [];
        if ($idWasBlank) {
            $warnings[] = 'No national ID — will be imported as a separate member';
        }
        if ($swapped) {
            $warnings[] = 'Phone and National ID looked swapped — auto-corrected';
        }
        if ($phoneBlank) {
            $warnings[] = 'No phone number';
        } elseif ($phoneInvalid) {
            $warnings[] = 'Invalid phone number — will be saved blank';
        }
        if ($dobInvalid) {
            $warnings[] = "Birth year \"{$dobYear}\" is not a 4-digit year — date of birth will be left blank";
        }
        if ($groupIsNew) {
            $warnings[] = "New group will be created: \"{$groupName}\"";
        }
        if ($duplicateInFile) {
            $warnings[] = 'Duplicate national ID earlier in this file — this row updates that record instead of adding a new one';
        }

        return [
            'action' => $action,
            'name' => $name,
            'phone' => $phone,
            'national_id' => $nationalId,
            'gender' => $this->normalizeGender($gender),
            'dob' => $dobCarbon,
            'group_name' => $groupName,
            'group_is_new' => $groupIsNew,
            'warnings' => $warnings,
        ];
    }

    /**
     * A cleaned digit string that looks like a Kenyan phone number:
     *  - 12 digits starting with 254 (e.g. 254712345678)
     *  - 10 digits starting with 07 or 01 (e.g. 0712345678)
     *  - 9 digits starting with 7 or 1 (missing leading 0, e.g. 712345678)
     */
    protected function looksLikePhone(string $clean): bool
    {
        return (strlen($clean) === 12 && substr($clean, 0, 3) === '254')
            || (strlen($clean) === 10 && in_array(substr($clean, 0, 2), ['07', '01'], true))
            || (strlen($clean) === 9 && in_array(substr($clean, 0, 1), ['7', '1'], true));
    }

    /**
     * Normalize and validate a phone number using Laravel Phone.
     * Returns E.164 formatted string or null if invalid/empty.
     */
    public function normalizePhoneNumber(?string $phone): ?string
    {
        $phone = trim((string) $phone);

        if ($phone === '') {
            return null;
        }

        try {
            return (new PhoneNumber($phone, 'KE'))->formatE164();
        } catch (NumberParseException $e) {
            // Genuinely unparseable input for the KE region — treat as blank.
            Log::warning('Failed to normalize phone number with Laravel Phone', [
                'phone' => $phone,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
        // NOTE: any other Throwable (e.g. a missing dependency) is intentionally
        // NOT caught here, so configuration problems surface loudly instead of
        // silently blanking every phone number.
    }

    public function normalizeGender($gender): string
    {
        $gender = strtolower(trim((string) $gender));

        return in_array($gender, ['male', 'female'], true) ? $gender : 'female';
    }
}
