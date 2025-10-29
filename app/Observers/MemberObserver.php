<?php

namespace App\Observers;

use App\Models\Member;
use App\Services\FeeAccrualService;

class MemberObserver
{
    /**
     * Handle the Member "created" event.
     */
    public function created(Member $member): void
    {
        // Accrue mandatory fees for new members
        try {
            $feeAccrualService = app(FeeAccrualService::class);
            $feeAccrualService->accrueMandatoryFees($member);
        } catch (\Exception $e) {
            \Log::error("Failed to accrue fees for member {$member->id}: " . $e->getMessage());
        }
    }

    /**
     * Handle the Member "updated" event.
     */
    public function updated(Member $member): void
    {
        //
    }

    /**
     * Handle the Member "deleted" event.
     */
    public function deleted(Member $member): void
    {
        //
    }

    /**
     * Handle the Member "restored" event.
     */
    public function restored(Member $member): void
    {
        //
    }

    /**
     * Handle the Member "force deleted" event.
     */
    public function forceDeleted(Member $member): void
    {
        //
    }
}
