<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SmsCredit extends Model
{
    protected $fillable = ['balance'];

    /**
     * Get current credit balance
     */
    public static function getBalance(): int
    {
        return static::first()?->balance ?? 0;
    }

    /**
     * Check if sufficient credits available
     */
    public static function hasSufficientCredits(int $required): bool
    {
        return static::getBalance() > 0; // Allow sending even if it goes negative
    }

    /**
     * Add credits
     */
    public static function addCredits(int $amount, string $description = null, $userId = null): void
    {
        $credit = static::firstOrCreate([], ['balance' => 0]);
        $balanceBefore = $credit->balance;
        $credit->increment('balance', $amount);
        $balanceAfter = $credit->fresh()->balance;

        CreditTransaction::create([
            'type' => 'add',
            'amount' => $amount,
            'balance_before' => $balanceBefore,
            'balance_after' => $balanceAfter,
            'transaction_type' => 'load',
            'description' => $description ?? "Credits loaded: {$amount}",
            'user_id' => $userId,
        ]);
    }

    /**
     * Subtract credits
     */
    public static function subtractCredits(
        int $amount,
        string $transactionType,
        string $description = null,
        $smsInboxId = null
    ): void {
        $credit = static::firstOrCreate([], ['balance' => 0]);
        $balanceBefore = $credit->balance;
        $credit->decrement('balance', $amount);
        $balanceAfter = $credit->fresh()->balance;

        CreditTransaction::create([
            'type' => 'subtract',
            'amount' => $amount,
            'balance_before' => $balanceBefore,
            'balance_after' => $balanceAfter,
            'transaction_type' => $transactionType,
            'description' => $description,
            'sms_inbox_id' => $smsInboxId,
        ]);
    }
}

