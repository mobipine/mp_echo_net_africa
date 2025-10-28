<?php

namespace App\Services;

use App\Models\Transaction;

class BalanceCalculationService
{
    /**
     * Calculate balance for an account
     * 
     * @param string $accountName
     * @param array $filters Additional filters (member_id, loan_id, etc.)
     * @param string $accountNature 'asset' or 'liability'
     */
    public function calculateBalance(
        string $accountName,
        array $filters = [],
        string $accountNature = 'asset'
    ): float {
        $query = Transaction::where('account_name', $accountName);
        
        // Apply filters
        foreach ($filters as $field => $value) {
            $query->where($field, $value);
        }
        
        $debits = (clone $query)->where('dr_cr', 'dr')->sum('amount');
        $credits = (clone $query)->where('dr_cr', 'cr')->sum('amount');
        
        // For assets: debit increases balance
        // For liabilities: credit increases balance
        return $accountNature === 'asset'
            ? $debits - $credits
            : $credits - $debits;
    }
}

