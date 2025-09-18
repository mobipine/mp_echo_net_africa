<?php

namespace App\Services;

use App\Models\Loan;
use App\Models\Transaction;
use Illuminate\Support\Facades\Log;

class RepaymentAllocationService
{
    /**
     * Allocate repayment amount between loan charges, principal and interest
     */
    public function allocateRepayment(Loan $loan, float $repaymentAmount): array
    {
        $priority = config('repayment_priority.priority', 'interest');
        
        // Get outstanding amounts
        $outstandingCharges = $this->getOutstandingLoanCharges($loan);
        $outstandingInterest = $this->getOutstandingInterest($loan);
        $outstandingPrincipal = $this->getOutstandingPrincipal($loan);
        
        $allocation = [
            'charges_payment' => 0.0,
            'interest_payment' => 0.0,
            'principal_payment' => 0.0,
            'remaining_amount' => $repaymentAmount,
        ];

        // First priority: Pay off loan charges
        if ($outstandingCharges > 0 && $repaymentAmount > 0) {
            $chargesPayment = min($repaymentAmount, $outstandingCharges);
            $allocation['charges_payment'] = $chargesPayment;
            $repaymentAmount -= $chargesPayment;
        }

        // If there's still money left after paying charges, apply the configured priority
        if ($repaymentAmount > 0) {
            $remainingAllocation = $this->allocateRemainingAmount($repaymentAmount, $outstandingInterest, $outstandingPrincipal, $priority);
            $allocation['interest_payment'] = $remainingAllocation['interest_payment'];
            $allocation['principal_payment'] = $remainingAllocation['principal_payment'];
            $allocation['remaining_amount'] = $remainingAllocation['remaining_amount'];
        }

        return $allocation;
    }

    /**
     * Allocate remaining amount after charges are paid
     */
    private function allocateRemainingAmount(float $amount, float $outstandingInterest, float $outstandingPrincipal, string $priority): array
    {
        switch ($priority) {
            case 'interest':
                return $this->allocateInterestFirst($amount, $outstandingInterest, $outstandingPrincipal);
                
            case 'principal':
                return $this->allocatePrincipalFirst($amount, $outstandingInterest, $outstandingPrincipal);
                
            case 'interest+principal':
                return $this->allocateProportionally($amount, $outstandingInterest, $outstandingPrincipal);
                
            default:
                return $this->allocateInterestFirst($amount, $outstandingInterest, $outstandingPrincipal);
        }
    }

    /**
     * Allocate payment to interest first, then principal
     */
    private function allocateInterestFirst(float $amount, float $outstandingInterest, float $outstandingPrincipal): array
    {
        $interestPayment = min($amount, $outstandingInterest);
        $remainingAmount = $amount - $interestPayment;
        $principalPayment = min($remainingAmount, $outstandingPrincipal);
        
        return [
            'interest_payment' => $interestPayment,
            'principal_payment' => $principalPayment,
            'remaining_amount' => $amount - $interestPayment - $principalPayment,
        ];
    }

    /**
     * Allocate payment to principal first, then interest
     */
    private function allocatePrincipalFirst(float $amount, float $outstandingInterest, float $outstandingPrincipal): array
    {
        $principalPayment = min($amount, $outstandingPrincipal);
        $remainingAmount = $amount - $principalPayment;
        $interestPayment = min($remainingAmount, $outstandingInterest);
        
        return [
            'interest_payment' => $interestPayment,
            'principal_payment' => $principalPayment,
            'remaining_amount' => $amount - $interestPayment - $principalPayment,
        ];
    }

    /**
     * Allocate payment proportionally between interest and principal
     */
    private function allocateProportionally(float $amount, float $outstandingInterest, float $outstandingPrincipal): array
    {
        $totalOutstanding = $outstandingInterest + $outstandingPrincipal;
        
        if ($totalOutstanding <= 0) {
            return [
                'interest_payment' => 0.0,
                'principal_payment' => 0.0,
                'remaining_amount' => $amount,
            ];
        }
        
        $interestRatio = $outstandingInterest / $totalOutstanding;
        $principalRatio = $outstandingPrincipal / $totalOutstanding;
        
        $interestPayment = min($amount * $interestRatio, $outstandingInterest);
        $principalPayment = min($amount * $principalRatio, $outstandingPrincipal);
        
        return [
            'interest_payment' => $interestPayment,
            'principal_payment' => $principalPayment,
            'remaining_amount' => $amount - $interestPayment - $principalPayment,
        ];
    }

    /**
     * Get outstanding loan charges amount for a loan
     */
    private function getOutstandingLoanCharges(Loan $loan): float
    {
        $attributes = $loan->all_attributes;
        $loanCharges = (float) ($attributes['loan_charges']['value'] ?? 0);
        
        if ($loanCharges <= 0) {
            return 0;
        }
        
        // Get total charges applied
        $totalChargesApplied = Transaction::where('loan_id', $loan->id)
            ->where('transaction_type', 'loan_charges')
            ->where('dr_cr', 'cr')
            ->sum('amount');
            
        // Get total charges paid
        $totalChargesPaid = Transaction::where('loan_id', $loan->id)
            ->where('transaction_type', 'charges_payment')
            ->where('dr_cr', 'cr')
            ->sum('amount');
            
        return max(0, $totalChargesApplied - $totalChargesPaid);
    }

    /**
     * Get outstanding interest amount for a loan
     */
    private function getOutstandingInterest(Loan $loan): float
    {
        // Get total interest accrued
        $totalInterestAccrued = Transaction::where('loan_id', $loan->id)
            ->where('transaction_type', 'interest_accrual')
            ->where('dr_cr', 'dr')
            ->sum('amount');
            
        // Get total interest paid
        $totalInterestPaid = Transaction::where('loan_id', $loan->id)
            ->where('transaction_type', 'interest_payment')
            ->where('dr_cr', 'cr')
            ->sum('amount');
            
        return max(0, $totalInterestAccrued - $totalInterestPaid);
    }

    /**
     * Get outstanding principal amount for a loan
     */
    private function getOutstandingPrincipal(Loan $loan): float
    {
        // Get total principal disbursed
        $totalPrincipalDisbursed = Transaction::where('loan_id', $loan->id)
            ->where('transaction_type', 'loan_issue')
            ->where('dr_cr', 'dr')
            ->sum('amount');
            
        // Get total principal repaid
        $totalPrincipalRepaid = Transaction::where('loan_id', $loan->id)
            ->where('transaction_type', 'principal_payment')
            ->where('dr_cr', 'cr')
            ->sum('amount');
            
        return max(0, $totalPrincipalDisbursed - $totalPrincipalRepaid);
    }

    /**
     * Create repayment transactions with proper allocation
     */
    public function createRepaymentTransactions(Loan $loan, float $repaymentAmount, string $paymentMethod, string $accountName = ""): array
    {
        $allocation = $this->allocateRepayment($loan, $repaymentAmount);
        
        $transactions = [];
        
        // Create charges payment transactions if applicable
        if ($allocation['charges_payment'] > 0) {
            $transactions = array_merge($transactions, $this->createChargesPaymentTransactions($loan, $allocation['charges_payment'], $accountName));
        }
        
        // Create interest payment transactions if applicable
        if ($allocation['interest_payment'] > 0) {
            $transactions = array_merge($transactions, $this->createInterestPaymentTransactions($loan, $allocation['interest_payment'], $accountName));
        }
        
        // Create principal payment transactions if applicable
        if ($allocation['principal_payment'] > 0) {
            $transactions = array_merge($transactions, $this->createPrincipalPaymentTransactions($loan, $allocation['principal_payment'], $accountName));
        }
        
        return $transactions;
    }

    /**
     * Create charges payment transactions
     */
    private function createChargesPaymentTransactions(Loan $loan, float $amount, string $accountName = ""): array
    {
        $accountName = $accountName ?? config('repayment_priority.accounts.bank');
        
        return [
            // Debit: Bank/Cash Account (money coming in)
            [
                'account_name' => $accountName,
                'loan_id' => $loan->id,
                'member_id' => $loan->member_id,
                'transaction_type' => 'charges_payment',
                'dr_cr' => 'dr',
                'amount' => $amount,
                'transaction_date' => now(),
                'description' => "Loan charges payment from member {$loan->member->name}",
            ],
            
            // Credit: Loan Charges Receivable (reducing receivable)
            [
                'account_name' => config('repayment_priority.accounts.loan_charges_receivable'),
                'loan_id' => $loan->id,
                'member_id' => $loan->member_id,
                'transaction_type' => 'charges_payment',
                'dr_cr' => 'cr',
                'amount' => $amount,
                'transaction_date' => now(),
                'description' => "Loan charges payment from member {$loan->member->name}",
            ],
        ];
    }

    /**
     * Create interest payment transactions
     */
    private function createInterestPaymentTransactions(Loan $loan, float $amount, string $accountName = ""): array
    {
        $accountName = $accountName ?? config('repayment_priority.accounts.bank');
        
        return [
            // Debit: Bank/Cash Account (money coming in)
            [
                'account_name' => $accountName,
                'loan_id' => $loan->id,
                'member_id' => $loan->member_id,
                'transaction_type' => 'interest_payment',
                'dr_cr' => 'dr',
                'amount' => $amount,
                'transaction_date' => now(),
                'description' => "Interest payment from member {$loan->member->name}",
            ],
            
            // Credit: Interest Receivable (reducing receivable)
            [
                'account_name' => config('repayment_priority.accounts.interest_receivable'),
                'loan_id' => $loan->id,
                'member_id' => $loan->member_id,
                'transaction_type' => 'interest_payment',
                'dr_cr' => 'cr',
                'amount' => $amount,
                'transaction_date' => now(),
                'description' => "Interest payment from member {$loan->member->name}",
            ],
        ];
    }

    /**
     * Create principal payment transactions
     */
    private function createPrincipalPaymentTransactions(Loan $loan, float $amount, string $accountName = ""): array
    {
        $accountName = $accountName ?? config('repayment_priority.accounts.bank');
        
        return [
            // Debit: Bank/Cash Account (money coming in)
            [
                'account_name' => $accountName,
                'loan_id' => $loan->id,
                'member_id' => $loan->member_id,
                'transaction_type' => 'principal_payment',
                'dr_cr' => 'dr',
                'amount' => $amount,
                'transaction_date' => now(),
                'description' => "Principal payment from member {$loan->member->name}",
            ],
            
            // Credit: Loans Receivable (reducing receivable)
            [
                'account_name' => config('repayment_priority.accounts.loans_receivable'),
                'loan_id' => $loan->id,
                'member_id' => $loan->member_id,
                'transaction_type' => 'principal_payment',
                'dr_cr' => 'cr',
                'amount' => $amount,
                'transaction_date' => now(),
                'description' => "Principal payment from member {$loan->member->name}",
            ],
        ];
    }
}
