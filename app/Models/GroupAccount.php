<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class GroupAccount extends Model
{
    protected $fillable = [
        'group_id',
        'account_code',
        'account_name',
        'account_type',
        'account_nature',
        'parent_account_code',
        'is_active',
        'opening_balance',
        'opening_date',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'opening_balance' => 'decimal:2',
        'opening_date' => 'date',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Get the group this account belongs to
     */
    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class);
    }

    /**
     * Get the parent account from chart of accounts
     */
    public function parentAccount(): BelongsTo
    {
        return $this->belongsTo(ChartofAccounts::class, 'parent_account_code', 'account_code');
    }

    /**
     * Get all transactions for this account
     */
    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class, 'account_number', 'account_code');
    }

    /**
     * Get current balance (calculated from transactions)
     */
    public function getBalanceAttribute(): float
    {
        if (app()->bound(\App\Services\GroupTransactionService::class)) {
            return app(\App\Services\GroupTransactionService::class)->getGroupAccountBalance($this);
        }
        
        // Fallback calculation
        return $this->calculateBalance();
    }

    /**
     * Calculate balance directly from transactions
     */
    private function calculateBalance(): float
    {
        $debits = $this->transactions()->where('dr_cr', 'dr')->sum('amount');
        $credits = $this->transactions()->where('dr_cr', 'cr')->sum('amount');
        
        // For assets and expenses: debit increases, credit decreases
        // For liabilities, equity, and revenue: credit increases, debit decreases
        return match($this->account_nature) {
            'asset', 'expense' => $debits - $credits,
            'liability', 'equity', 'revenue' => $credits - $debits,
            default => 0,
        };
    }

    /**
     * Scope to get only active accounts
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope to filter by account type
     */
    public function scopeOfType($query, string $accountType)
    {
        return $query->where('account_type', $accountType);
    }

    /**
     * Scope to filter by account nature
     */
    public function scopeOfNature($query, string $nature)
    {
        return $query->where('account_nature', $nature);
    }
}

