<?php

namespace App\Observers;

use App\Models\Member;
use App\Models\SurveyProgress;
use App\Services\FeeAccrualService;
use App\Support\SurveyProgressState;

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
        SurveyProgress::where('member_id', $member->id)
            ->whereNull('completed_at')
            ->whereIn('status', SurveyProgressState::OPEN_STATUSES)
            ->update([
                'status' => 'CANCELLED',
                'open_progress_guard' => null,
            ]);
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
