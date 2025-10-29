<?php

namespace App\Services;

use App\Models\Member;
use App\Models\MemberSavingsAccount;
use App\Models\Transaction;
use App\Models\SaccoProduct;
use Illuminate\Support\Facades\DB;

class SavingsService
{
    protected TransactionService $transactionService;
    
    public function __construct(TransactionService $transactionService)
    {
        $this->transactionService = $transactionService;
    }
    
    /**
     * Open a savings account for a member
     */
    public function openSavingsAccount(Member $member, SaccoProduct $product): MemberSavingsAccount
    {
        // Check if account already exists
        $existing = MemberSavingsAccount::where('member_id', $member->id)
            ->where('sacco_product_id', $product->id)
            ->first();
        
        if ($existing) {
            return $existing;
        }
        
        // Generate account number
        $accountNumber = $this->generateSavingsAccountNumber($member, $product);
        
        return MemberSavingsAccount::create([
            'member_id' => $member->id,
            'sacco_product_id' => $product->id,
            'account_number' => $accountNumber,
            'opening_date' => now(),
            'status' => 'active',
        ]);
    }
    
    /**
     * Generate savings account number
     */
    private function generateSavingsAccountNumber(Member $member, SaccoProduct $product): string
    {
        // Format: SAV-{PRODUCT_CODE}-{MEMBER_ACC_NUMBER}
        // Example: SAV-MAIN-ACC-0001
        return 'SAV-' . strtoupper($product->code) . '-' . $member->account_number;
    }
    
    /**
     * Deposit money to member savings account
     */
    public function deposit(
        MemberSavingsAccount $savingsAccount,
        float $amount,
        string $paymentMethod = 'cash',
        string $referenceNumber = null,
        string $notes = null
    ): array {
        return DB::transaction(function () use ($savingsAccount, $amount, $paymentMethod, $referenceNumber, $notes) {
            // Get account mappings
            $savingsAccountGL = $savingsAccount->product->getAccountNumber('savings_account');
            $savingsAccountName = $savingsAccount->product->getAccountName('savings_account');
            $bankAccountGL = $savingsAccount->product->getAccountNumber('bank');
            $bankAccountName = $savingsAccount->product->getAccountName('bank');
            
            if (!$savingsAccountGL || !$bankAccountGL) {
                throw new \Exception('Savings account or bank account not configured for this product');
            }
            
            $transactions = [];
            
            // Debit: Bank/Cash Account (money coming in)
            $transactions[] = Transaction::create([
                'account_name' => $bankAccountName,
                'account_number' => $bankAccountGL,
                'member_id' => $savingsAccount->member_id,
                'savings_account_id' => $savingsAccount->id,
                'transaction_type' => 'savings_deposit',
                'dr_cr' => 'dr',
                'amount' => $amount,
                'transaction_date' => now(),
                'description' => "Savings deposit by {$savingsAccount->member->name}",
                'reference_number' => $referenceNumber,
                'metadata' => ['payment_method' => $paymentMethod, 'notes' => $notes],
            ]);
            
            // Credit: Member Savings Account (liability increases)
            $transactions[] = Transaction::create([
                'account_name' => $savingsAccountName,
                'account_number' => $savingsAccountGL,
                'member_id' => $savingsAccount->member_id,
                'savings_account_id' => $savingsAccount->id,
                'transaction_type' => 'savings_deposit',
                'dr_cr' => 'cr',
                'amount' => $amount,
                'transaction_date' => now(),
                'description' => "Savings deposit by {$savingsAccount->member->name}",
                'reference_number' => $referenceNumber,
                'metadata' => ['payment_method' => $paymentMethod, 'notes' => $notes],
            ]);
            
            return [
                'success' => true,
                'transactions' => $transactions,
                'new_balance' => $this->getBalance($savingsAccount),
            ];
        });
    }
    
    /**
     * Withdraw money from savings account
     */
    public function withdraw(
        MemberSavingsAccount $savingsAccount,
        float $amount,
        string $paymentMethod = 'cash',
        string $referenceNumber = null,
        string $notes = null
    ): array {
        // Validate withdrawal allowed
        if (!$savingsAccount->product->getProductAttributeValue('allows_withdrawal')) {
            throw new \Exception('Withdrawals not allowed for this savings product');
        }
        
        // Check sufficient balance
        $balance = $this->getBalance($savingsAccount);
        if ($balance < $amount) {
            throw new \Exception("Insufficient balance. Available: {$balance}");
        }
        
        return DB::transaction(function () use ($savingsAccount, $amount, $paymentMethod, $referenceNumber, $notes) {
            $savingsAccountGL = $savingsAccount->product->getAccountNumber('savings_account');
            $savingsAccountName = $savingsAccount->product->getAccountName('savings_account');
            $bankAccountGL = $savingsAccount->product->getAccountNumber('bank');
            $bankAccountName = $savingsAccount->product->getAccountName('bank');
            
            $transactions = [];
            
            // Debit: Member Savings Account (liability decreases)
            $transactions[] = Transaction::create([
                'account_name' => $savingsAccountName,
                'account_number' => $savingsAccountGL,
                'member_id' => $savingsAccount->member_id,
                'savings_account_id' => $savingsAccount->id,
                'transaction_type' => 'savings_withdrawal',
                'dr_cr' => 'dr',
                'amount' => $amount,
                'transaction_date' => now(),
                'description' => "Savings withdrawal by {$savingsAccount->member->name}",
                'reference_number' => $referenceNumber,
                'metadata' => ['payment_method' => $paymentMethod, 'notes' => $notes],
            ]);
            
            // Credit: Bank/Cash Account (money going out)
            $transactions[] = Transaction::create([
                'account_name' => $bankAccountName,
                'account_number' => $bankAccountGL,
                'member_id' => $savingsAccount->member_id,
                'savings_account_id' => $savingsAccount->id,
                'transaction_type' => 'savings_withdrawal',
                'dr_cr' => 'cr',
                'amount' => $amount,
                'transaction_date' => now(),
                'description' => "Savings withdrawal by {$savingsAccount->member->name}",
                'reference_number' => $referenceNumber,
                'metadata' => ['payment_method' => $paymentMethod, 'notes' => $notes],
            ]);
            
            return [
                'success' => true,
                'transactions' => $transactions,
                'new_balance' => $this->getBalance($savingsAccount),
            ];
        });
    }
    
    /**
     * Get savings account balance
     */
    public function getBalance(MemberSavingsAccount $savingsAccount): float
    {
        $savingsAccountName = $savingsAccount->product->getAccountName('savings_account');
        
        if (!$savingsAccountName) {
            return 0;
        }
        
        // Sum credits (deposits increase liability)
        $deposits = Transaction::where('savings_account_id', $savingsAccount->id)
            ->where('account_name', $savingsAccountName)
            ->where('dr_cr', 'cr')
            ->sum('amount');
        
        // Sum debits (withdrawals decrease liability)
        $withdrawals = Transaction::where('savings_account_id', $savingsAccount->id)
            ->where('account_name', $savingsAccountName)
            ->where('dr_cr', 'dr')
            ->sum('amount');
        
        return max(0, $deposits - $withdrawals);
    }
    
    /**
     * Get cumulative savings for loan eligibility
     */
    public function getCumulativeSavings(Member $member, int $months = null): float
    {
        $query = Transaction::where('member_id', $member->id)
            ->where('transaction_type', 'savings_deposit')
            ->where('dr_cr', 'cr'); // Credits to savings account
        
        if ($months) {
            $query->where('transaction_date', '>=', now()->subMonths($months));
        }
        
        return $query->sum('amount');
    }
}

