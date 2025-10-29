<?php

namespace App\Services;

use App\Models\Transaction;
use Illuminate\Support\Facades\DB;

class TransactionService
{
    /**
     * Create double-entry transaction
     */
    public function createDoubleEntry(
        string $debitAccount,
        string $debitAccountNumber,
        string $creditAccount,
        string $creditAccountNumber,
        float $amount,
        string $transactionType,
        array $references = [],
        string $description = '',
        array $metadata = []
    ): array {
        return DB::transaction(function () use (
            $debitAccount,
            $debitAccountNumber,
            $creditAccount,
            $creditAccountNumber,
            $amount,
            $transactionType,
            $references,
            $description,
            $metadata
        ) {
            $baseData = [
                'transaction_type' => $transactionType,
                'amount' => $amount,
                'transaction_date' => now(),
                'description' => $description,
                'metadata' => $metadata,
            ];
            
            // Merge references (loan_id, member_id, savings_account_id, etc.)
            $baseData = array_merge($baseData, $references);
            
            // Debit transaction
            $debitTx = Transaction::create(array_merge($baseData, [
                'account_name' => $debitAccount,
                'account_number' => $debitAccountNumber,
                'dr_cr' => 'dr',
            ]));
            
            // Credit transaction
            $creditTx = Transaction::create(array_merge($baseData, [
                'account_name' => $creditAccount,
                'account_number' => $creditAccountNumber,
                'dr_cr' => 'cr',
            ]));
            
            return [$debitTx, $creditTx];
        });
    }
    
    /**
     * Reverse a transaction
     */
    public function reverseTransaction(Transaction $transaction, string $reason): Transaction
    {
        // Create reversal with opposite dr_cr
        $reversalType = $transaction->transaction_type . '_reversal';
        $oppositeDrCr = $transaction->dr_cr === 'dr' ? 'cr' : 'dr';
        
        return Transaction::create([
            'account_name' => $transaction->account_name,
            'account_number' => $transaction->account_number,
            'loan_id' => $transaction->loan_id,
            'member_id' => $transaction->member_id,
            'savings_account_id' => $transaction->savings_account_id,
            'product_subscription_id' => $transaction->product_subscription_id,
            'transaction_type' => $reversalType,
            'dr_cr' => $oppositeDrCr,
            'amount' => $transaction->amount,
            'transaction_date' => now(),
            'description' => "Reversal: {$reason}. Original: {$transaction->description}",
            'reference_number' => $transaction->reference_number,
            'metadata' => array_merge($transaction->metadata ?? [], [
                'reversed_transaction_id' => $transaction->id,
                'reason' => $reason,
            ]),
        ]);
    }
}

