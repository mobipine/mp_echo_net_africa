<?php

namespace App\Services;

use App\Models\Group;
use App\Models\GroupAccount;
use App\Models\Transaction;
use Illuminate\Support\Facades\DB;

class GroupTransactionService
{
    /**
     * Get group account by type
     */
    public function getGroupAccount(Group $group, string $accountType): GroupAccount
    {
        // Add 'group_' prefix if not already present
        $searchType = str_starts_with($accountType, 'group_') ? $accountType : 'group_' . $accountType;
        
        $account = GroupAccount::where('group_id', $group->id)
            ->where('account_type', $searchType)
            ->where('is_active', true)
            ->first();
        
        if (!$account) {
            throw new \Exception("Group account not found: {$searchType} for group {$group->name}");
        }
        
        return $account;
    }
    
    /**
     * Create double-entry transaction at group level
     */
    public function createGroupTransaction(
        Group $group,
        string $debitAccountType,
        string $creditAccountType,
        float $amount,
        string $transactionType,
        array $references = [],
        string $description = '',
        array $metadata = []
    ): array {
        return DB::transaction(function () use (
            $group, $debitAccountType, $creditAccountType, $amount, 
            $transactionType, $references, $description, $metadata
        ) {
            $debitAccount = $this->getGroupAccount($group, $debitAccountType);
            $creditAccount = $this->getGroupAccount($group, $creditAccountType);
            
            $baseData = [
                'group_id' => $group->id,
                'transaction_type' => $transactionType,
                'amount' => $amount,
                'transaction_date' => now(),
                'description' => $description,
                'metadata' => $metadata,
            ];
            
            $baseData = array_merge($baseData, $references);
            
            // Debit transaction
            $debitTx = Transaction::create(array_merge($baseData, [
                'account_name' => $debitAccount->account_name,
                'account_number' => $debitAccount->account_code,
                'dr_cr' => 'dr',
            ]));
            
            // Credit transaction
            $creditTx = Transaction::create(array_merge($baseData, [
                'account_name' => $creditAccount->account_name,
                'account_number' => $creditAccount->account_code,
                'dr_cr' => 'cr',
            ]));
            
            return [$debitTx, $creditTx];
        });
    }
    
    /**
     * Calculate group account balance
     */
    public function getGroupAccountBalance(GroupAccount $account): float
    {
        $debits = Transaction::where('account_number', $account->account_code)
            ->where('dr_cr', 'dr')
            ->sum('amount');
        
        $credits = Transaction::where('account_number', $account->account_code)
            ->where('dr_cr', 'cr')
            ->sum('amount');
        
        // For assets and expenses: debits increase, credits decrease
        // For liabilities, equity, and revenue: credits increase, debits decrease
        return match($account->account_nature) {
            'asset', 'expense' => $debits - $credits,
            'liability', 'equity', 'revenue' => $credits - $debits,
            default => 0,
        };
    }

    /**
     * Get all account balances for a group
     */
    public function getAllGroupAccountBalances(Group $group): array
    {
        $accounts = GroupAccount::where('group_id', $group->id)
            ->where('is_active', true)
            ->get();
        
        $balances = [];
        foreach ($accounts as $account) {
            $balances[$account->account_type] = [
                'account_code' => $account->account_code,
                'account_name' => $account->account_name,
                'account_nature' => $account->account_nature,
                'balance' => $this->getGroupAccountBalance($account),
            ];
        }
        
        return $balances;
    }

    /**
     * Get group financial summary
     */
    public function getGroupFinancialSummary(Group $group): array
    {
        $balances = $this->getAllGroupAccountBalances($group);
        
        $assets = 0;
        $liabilities = 0;
        $equity = 0;
        $revenue = 0;
        $expenses = 0;
        
        foreach ($balances as $accountType => $accountData) {
            $balance = $accountData['balance'];
            match($accountData['account_nature']) {
                'asset' => $assets += $balance,
                'liability' => $liabilities += $balance,
                'equity' => $equity += $balance,
                'revenue' => $revenue += $balance,
                'expense' => $expenses += $balance,
                default => null,
            };
        }
        
        return [
            'total_assets' => $assets,
            'total_liabilities' => $liabilities,
            'total_equity' => $equity,
            'total_revenue' => $revenue,
            'total_expenses' => $expenses,
            'net_income' => $revenue - $expenses,
            'equity_balance' => $assets - $liabilities, // Should equal equity + net income
        ];
    }

    /**
     * Validate double-entry integrity for a group
     */
    public function validateGroupDoubleEntry(Group $group): array
    {
        $groupAccountCodes = GroupAccount::where('group_id', $group->id)
            ->pluck('account_code');
        
        $totalDebits = Transaction::whereIn('account_number', $groupAccountCodes)
            ->where('dr_cr', 'dr')
            ->sum('amount');
        
        $totalCredits = Transaction::whereIn('account_number', $groupAccountCodes)
            ->where('dr_cr', 'cr')
            ->sum('amount');
        
        $difference = $totalDebits - $totalCredits;
        
        return [
            'total_debits' => $totalDebits,
            'total_credits' => $totalCredits,
            'difference' => $difference,
            'is_balanced' => abs($difference) < 0.01, // Allow for rounding errors
        ];
    }
}

