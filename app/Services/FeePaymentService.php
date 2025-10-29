<?php

namespace App\Services;

use App\Models\{Member, SaccoProduct, Transaction};
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class FeePaymentService
{
    /**
     * Record a fee payment
     */
    public function recordPayment(
        Member $member,
        SaccoProduct $product,
        float $amount,
        string $paymentMethod = 'cash',
        string $referenceNumber = null,
        string $notes = null
    ): array {
        return DB::transaction(function () use ($member, $product, $amount, $paymentMethod, $referenceNumber, $notes) {
            // Get account mappings
            $bankAccountGL = $product->getAccountNumber('bank');
            $bankAccountName = $product->getAccountName('bank');
            $incomeAccountGL = $product->getAccountNumber('fee_income');
            $incomeAccountName = $product->getAccountName('fee_income');
            
            if (!$bankAccountGL || !$incomeAccountGL) {
                throw new \Exception('Bank or income account not configured for this product');
            }
            
            $transactions = [];
            
            // Debit: Bank Account (money coming in)
            $transactions[] = Transaction::create([
                'account_name' => $bankAccountName,
                'account_number' => $bankAccountGL,
                'member_id' => $member->id,
                'transaction_type' => 'fee_payment',
                'dr_cr' => 'dr',
                'amount' => $amount,
                'transaction_date' => now(),
                'description' => "Payment of {$product->name} by {$member->name}",
                'reference_number' => $referenceNumber,
                'metadata' => [
                    'payment_method' => $paymentMethod,
                    'notes' => $notes,
                    'product_code' => $product->code,
                    'product_name' => $product->name,
                ],
            ]);
            
            // Credit: Fee Income
            $transactions[] = Transaction::create([
                'account_name' => $incomeAccountName,
                'account_number' => $incomeAccountGL,
                'member_id' => $member->id,
                'transaction_type' => 'fee_payment',
                'dr_cr' => 'cr',
                'amount' => $amount,
                'transaction_date' => now(),
                'description' => "Payment of {$product->name} by {$member->name}",
                'reference_number' => $referenceNumber,
                'metadata' => [
                    'payment_method' => $paymentMethod,
                    'notes' => $notes,
                    'product_code' => $product->code,
                    'product_name' => $product->name,
                ],
            ]);
            
            return [
                'success' => true,
                'transactions' => $transactions,
                'amount_paid' => $amount,
            ];
        });
    }
    
    /**
     * Calculate fee amount based on product rules
     */
    public function calculateFeeAmount(SaccoProduct $product, Member $member = null): float
    {
        // Check for fixed amount
        $fixedAmount = $product->getProductAttributeValue('fixed_amount');
        if ($fixedAmount) {
            return (float) $fixedAmount;
        }
        
        // Check for escalating formula (e.g., registration fee)
        $formula = $product->getProductAttributeValue('calculation_formula');
        if ($formula) {
            return $this->calculateFromFormula($formula, $member);
        }
        
        return 0;
    }
    
    /**
     * Calculate fee from formula
     */
    private function calculateFromFormula($formula, Member $member = null): float
    {
        if (is_string($formula)) {
            $formula = json_decode($formula, true);
        }
        
        if (!is_array($formula)) {
            return 0;
        }
        
        $type = $formula['type'] ?? 'fixed';
        
        if ($type === 'escalating') {
            return $this->calculateEscalatingFee($formula, $member);
        }
        
        return $formula['base_amount'] ?? 0;
    }
    
    /**
     * Calculate escalating fee (increases over time)
     */
    private function calculateEscalatingFee(array $formula, Member $member = null): float
    {
        $baseAmount = $formula['base_amount'] ?? 0;
        $incrementAmount = $formula['increment_amount'] ?? 0;
        $incrementFrequency = $formula['increment_frequency'] ?? 'monthly';
        $maxAmount = $formula['max_amount'] ?? null;
        $launchDate = $formula['launch_date'] ?? null;
        
        if (!$launchDate) {
            return $baseAmount;
        }
        
        $launch = Carbon::parse($launchDate);
        $now = now();
        
        // Calculate periods elapsed
        $periodsElapsed = match($incrementFrequency) {
            'daily' => $launch->diffInDays($now),
            'weekly' => $launch->diffInWeeks($now),
            'monthly' => $launch->diffInMonths($now),
            'yearly' => $launch->diffInYears($now),
            default => $launch->diffInMonths($now),
        };
        
        $calculatedAmount = $baseAmount + ($incrementAmount * $periodsElapsed);
        
        // Apply max cap
        if ($maxAmount && $calculatedAmount > $maxAmount) {
            return $maxAmount;
        }
        
        return $calculatedAmount;
    }
    
    /**
     * Get member's fee payment history for a product
     */
    public function getPaymentHistory(Member $member, SaccoProduct $product)
    {
        return Transaction::where('member_id', $member->id)
            ->where('transaction_type', 'fee_payment')
            ->where('metadata->product_code', $product->code)
            ->orderBy('transaction_date', 'desc')
            ->get();
    }
    
    /**
     * Check if member has paid a specific fee
     */
    public function hasPaidFee(Member $member, SaccoProduct $product): bool
    {
        return Transaction::where('member_id', $member->id)
            ->where('transaction_type', 'fee_payment')
            ->where('metadata->product_code', $product->code)
            ->exists();
    }
    
    /**
     * Get total amount paid for a fee
     */
    public function getTotalPaid(Member $member, SaccoProduct $product): float
    {
        return Transaction::where('member_id', $member->id)
            ->where('transaction_type', 'fee_payment')
            ->where('metadata->product_code', $product->code)
            ->where('dr_cr', 'dr') // Bank account side
            ->sum('amount');
    }
}
