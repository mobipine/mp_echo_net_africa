<?php

namespace App\Imports;

use App\Models\Group;
use App\Models\CountyENAStaff;
use App\Models\LocalImplementingPartner;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class GroupsImport implements ToCollection, WithHeadingRow
{
    public function collection(Collection $rows)
    {
        $importedCount = 0;
        $skippedCount = 0;
        
        
        foreach ($rows as $index => $row) {
            try {
                Log::info($row['name']);
                
                // Skip if required fields are missing
                if (empty($row['name']) || empty($row['county'])) {
                    $skippedCount++;
                    Log::info("The group has no name or county. Skipping it..");
                    continue;
                }
                Log::info($row['name']);

                // Check if group name already exists
                if (Group::where('name', $row['name'])->exists()) {
                    $skippedCount++;
                    Log::info("{$row['name']} already exists in the database. Skipping it...");
                    continue;
                }
                Log::info($row['name']);
                $county = $row['county'];
                $counties = config('counties');
                $countyExists = collect($counties)->contains('county', $county);
                
                if (!$countyExists) {
                    $skippedCount++;
                    continue;
                }
                $countyEntry = collect($counties)->firstWhere('county', $county);
                $countyCode = $countyEntry['code'] ?? null;
                Log::info($row['name']);

                // Find or create Local Implementing Partner
                $localImplementingPartnerId = null;
                if (!empty($row['local_implementing_partner'])) {
                    Log::info("{$row['local_implementing_partner']} is the LIP");

                    $localImplementingPartner = LocalImplementingPartner::firstOrCreate(
                        ['name' => $row['local_implementing_partner']],
                    );
                    $localImplementingPartnerId = $localImplementingPartner->id;
                    Log::info("{$localImplementingPartner->name} is the local implementing partner set for {$row['name']}");
                }
               
                Log::info($row['name']);
                
                // Find County ENA Staff
                $countyENAStaffId = null;
                if (!empty($row['county_ena_staff'])) {
                    $countyENAStaff = CountyENAStaff::where('name', $row['county_ena_staff'])
                        ->orWhere('county', $row['county'])
                        ->first();
                    Log::info("ENA staff $countyENAStaff" );
                    Log::info("{$countyENAStaff->name} is the ENA staff for the group ");
                    $countyENAStaffId = $countyENAStaff->id ?? null;
                }
                Log::info($countyCode);
                Log::info($row['name']);
                Log::info($row['email']);
                // Log::info($row['phone_number'] );
                Log::info($row['county']);
                Log::info($row['sub_county']);
                // Log::info($row['address'] );
                Log::info($row['ward'] );
                Log::info($localImplementingPartnerId);
                Log::info($countyENAStaffId);

                $newGroup=Group::create([
                    'name' => $row['name'],
                    'email' => $row['email'] ?? null,
                    'phone_number' => $row['phone_number'] ?? '0709',
                    'county' => $countyCode,
                    'sub_county' => $row['sub_county'] ?? null,
                    // 'address' => $row['address'] ?? null,
                    // 'township' => $row['township'] ?? null,
                    'ward' => $row['ward'] ?? null,
                    'local_implementing_partner_id' => $localImplementingPartnerId,
                    'county_ENA_staff_id' => $countyENAStaffId,
                ]);
                Log::info($newGroup);

                $importedCount++;

            } catch (\Exception $e) {
                $skippedCount++;
                Log::info("{$skippedCount} Groups have been skipped due to errors or duplicates");
                continue;
            }
        }

        // Store import results in session for display
        session()->flash('import_results', [
            'imported' => $importedCount,
            'skipped' => $skippedCount
        ]);
    }
}