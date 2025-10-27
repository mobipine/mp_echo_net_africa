<?php

namespace App\Services;

use App\Models\{Member, SaccoProduct, MemberFeeObligation};
use Illuminate\Support\Facades\DB;

class FeeAccrualService
{
    protected FeePaymentService $feePaymentService;
    
    public function __construct(FeePaymentService $feePaymentService)
    {
        $this->feePaymentService = $feePaymentService;
    }
    
    /**
     * Accrue all mandatory fees for a new member
     */
    public function accrueMandatoryFees(Member $member): array
    {
        $accrued = [];
        
        // Get all mandatory fee products
        $mandatoryFees = SaccoProduct::where('is_mandatory', true)
            ->where('is_active', true)
            ->whereHas('productType', function ($query) {
                $query->whereIn('category', ['fee', 'fine']);
            })
            ->get();
        
        foreach ($mandatoryFees as $fee) {
            // Check if already accrued
            $existing = MemberFeeObligation::where('member_id', $member->id)
                ->where('sacco_product_id', $fee->id)
                ->first();
            
            if (!$existing) {
                $obligation = $this->accrueFee($member, $fee);
                $accrued[] = $obligation;
            }
        }
        
        return $accrued;
    }
    
    /**
     * Accrue a specific fee for a member
     */
    public function accrueFee(Member $member, SaccoProduct $product, ?string $notes = null): MemberFeeObligation
    {
        // Calculate the fee amount
        $amount = $this->feePaymentService->calculateFeeAmount($product, $member);
        
        // Create the obligation
        return MemberFeeObligation::create([
            'member_id' => $member->id,
            'sacco_product_id' => $product->id,
            'amount_due' => $amount,
            'amount_paid' => 0,
            'due_date' => now()->addDays(30), // Due in 30 days by default
            'status' => 'pending',
            'description' => "Accrued fee: {$product->name}",
            'notes' => $notes,
        ]);
    }
    
    /**
     * Update obligation after payment
     */
    public function recordPayment(MemberFeeObligation $obligation, float $amount): void
    {
        DB::transaction(function () use ($obligation, $amount) {
            $obligation->amount_paid += $amount;
            $obligation->updateStatus();
            $obligation->save();
        });
    }
    
    /**
     * Get pending obligations for a member
     */
    public function getPendingObligations(Member $member)
    {
        return $member->feeObligations()
            ->with('saccoProduct')
            ->pending()
            ->orderBy('due_date')
            ->get();
    }
    
    /**
     * Get all obligations for a member
     */
    public function getAllObligations(Member $member, ?string $status = null)
    {
        $query = $member->feeObligations()->with('saccoProduct');
        
        if ($status) {
            $query->where('status', $status);
        }
        
        return $query->orderBy('due_date', 'desc')->get();
    }
    
    /**
     * Waive a fee obligation
     */
    public function waiveObligation(MemberFeeObligation $obligation, string $reason): void
    {
        $obligation->status = 'waived';
        $obligation->notes = ($obligation->notes ?? '') . "\nWaived: {$reason}";
        $obligation->save();
    }
}

