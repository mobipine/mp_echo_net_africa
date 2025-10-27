<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Member;
use App\Services\FeeAccrualService;

class AccrueMemberFeesSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $this->command->info('Accruing mandatory fees for existing members...');
        
        $feeAccrualService = app(FeeAccrualService::class);
        $members = Member::all();
        $count = 0;
        
        foreach ($members as $member) {
            $accrued = $feeAccrualService->accrueMandatoryFees($member);
            if (count($accrued) > 0) {
                $count += count($accrued);
                $this->command->info(" ✓ Accrued " . count($accrued) . " fees for {$member->name}");
            }
        }
        
        $this->command->info("Total fees accrued: {$count}");
    }
}
