<?php

namespace App\Services;

use App\Models\{Member, MemberProductSubscription, SaccoProduct, Transaction};
use Illuminate\Support\Facades\DB;

class SubscriptionPaymentService
{
    /**
     * Record a subscription payment
     */
    public function recordPayment(
        MemberProductSubscription $subscription,
        float $amount,
        string $paymentMethod = 'cash',
        string $referenceNumber = null,
        string $notes = null
    ): array {
        return DB::transaction(function () use ($subscription, $amount, $paymentMethod, $referenceNumber, $notes) {
            $product = $subscription->saccoProduct;
            
            // Get account mappings
            $bankAccountGL = $product->getAccountNumber('bank');
            $bankAccountName = $product->getAccountName('bank');
            $incomeAccountGL = $product->getAccountNumber('contribution_income');
            $incomeAccountName = $product->getAccountName('contribution_income');
            
            if (!$bankAccountGL || !$incomeAccountGL) {
                throw new \Exception('Bank or income account not configured for this product');
            }
            
            $transactions = [];
            
            // Debit: Bank Account (money coming in)
            $transactions[] = Transaction::create([
                'account_name' => $bankAccountName,
                'account_number' => $bankAccountGL,
                'member_id' => $subscription->member_id,
                'product_subscription_id' => $subscription->id,
                'transaction_type' => 'subscription_payment',
                'dr_cr' => 'dr',
                'amount' => $amount,
                'transaction_date' => now(),
                'description' => "Subscription payment for {$product->name} by {$subscription->member->name}",
                'reference_number' => $referenceNumber,
                'metadata' => ['payment_method' => $paymentMethod, 'notes' => $notes],
            ]);
            
            // Credit: Contribution Income
            $transactions[] = Transaction::create([
                'account_name' => $incomeAccountName,
                'account_number' => $incomeAccountGL,
                'member_id' => $subscription->member_id,
                'product_subscription_id' => $subscription->id,
                'transaction_type' => 'subscription_payment',
                'dr_cr' => 'cr',
                'amount' => $amount,
                'transaction_date' => now(),
                'description' => "Subscription payment for {$product->name} by {$subscription->member->name}",
                'reference_number' => $referenceNumber,
                'metadata' => ['payment_method' => $paymentMethod, 'notes' => $notes],
            ]);
            
            // Update subscription record
            $subscription->total_paid += $amount;
            $subscription->payment_count += 1;
            $subscription->last_payment_date = now();
            
            // Calculate next payment date based on recurrence
            $this->calculateNextPaymentDate($subscription);
            
            // Check if completed
            if ($subscription->total_expected && $subscription->total_paid >= $subscription->total_expected) {
                $subscription->status = 'completed';
                $subscription->end_date = now();
            }
            
            $subscription->save();
            
            return [
                'success' => true,
                'transactions' => $transactions,
                'subscription' => $subscription->fresh(),
                'outstanding' => $subscription->outstanding_amount,
            ];
        });
    }
    
    /**
     * Create or get subscription for member
     */
    public function getOrCreateSubscription(Member $member, SaccoProduct $product): MemberProductSubscription
    {
        // Check if active subscription exists
        $existing = MemberProductSubscription::where('member_id', $member->id)
            ->where('sacco_product_id', $product->id)
            ->whereIn('status', ['active', 'suspended'])
            ->first();
        
        if ($existing) {
            return $existing;
        }
        
        // Create new subscription
        $totalExpected = $this->calculateTotalExpected($product);
        
        return MemberProductSubscription::create([
            'member_id' => $member->id,
            'sacco_product_id' => $product->id,
            'subscription_date' => now(),
            'start_date' => now(),
            'status' => 'active',
            'total_expected' => $totalExpected,
            'next_payment_date' => now(),
        ]);
    }
    
    /**
     * Calculate total expected amount
     */
    private function calculateTotalExpected(SaccoProduct $product): ?float
    {
        $maxTotal = $product->getProductAttributeValue('max_total_amount');
        $amountPerCycle = $product->getProductAttributeValue('amount_per_cycle');
        $totalCycles = $product->getProductAttributeValue('total_cycles');
        
        if ($maxTotal) {
            return (float) $maxTotal;
        }
        
        if ($amountPerCycle && $totalCycles) {
            return (float) $amountPerCycle * (int) $totalCycles;
        }
        
        return null; // Ongoing subscription
    }
    
    /**
     * Calculate next payment date
     */
    private function calculateNextPaymentDate(MemberProductSubscription $subscription): void
    {
        $product = $subscription->saccoProduct;
        $frequency = $product->getProductAttributeValue('payment_frequency') ?? 'monthly';
        
        $lastDate = $subscription->last_payment_date ?? $subscription->start_date;
        
        $nextDate = match($frequency) {
            'daily' => $lastDate->addDay(),
            'weekly' => $lastDate->addWeek(),
            'monthly' => $lastDate->addMonth(),
            'quarterly' => $lastDate->addMonths(3),
            'yearly' => $lastDate->addYear(),
            default => $lastDate->addMonth(),
        };
        
        $subscription->next_payment_date = $nextDate;
    }
    
    /**
     * Get expected payment amount
     */
    public function getExpectedAmount(MemberProductSubscription $subscription): float
    {
        $product = $subscription->saccoProduct;
        $amountPerCycle = $product->getProductAttributeValue('amount_per_cycle');
        
        if ($amountPerCycle) {
            return (float) $amountPerCycle;
        }
        
        // If total expected and cycles known
        if ($subscription->total_expected) {
            $totalCycles = $product->getProductAttributeValue('total_cycles');
            if ($totalCycles && $totalCycles > 0) {
                return $subscription->total_expected / $totalCycles;
            }
        }
        
        return 0;
    }
}

