<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Services\SavingsService;

class MemberSavingsAccount extends Model
{
    protected $fillable = [
        'member_id',
        'sacco_product_id',
        'account_number',
        'opening_date',
        'status',
        'closed_date',
        'notes',
    ];

    protected $casts = [
        'opening_date' => 'date',
        'closed_date' => 'date',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    protected $appends = ['balance'];

    /**
     * Get the member
     */
    public function member()
    {
        return $this->belongsTo(Member::class);
    }

    /**
     * Get the product
     */
    public function product()
    {
        return $this->belongsTo(SaccoProduct::class, 'sacco_product_id');
    }

    /**
     * Get all transactions
     */
    public function transactions()
    {
        return $this->hasMany(Transaction::class, 'savings_account_id');
    }

    /**
     * Get current balance (calculated from transactions)
     */
    public function getBalanceAttribute(): float
    {
        // Only calculate if service is available
        if (app()->bound(SavingsService::class)) {
            return app(SavingsService::class)->getBalance($this);
        }
        
        // Fallback: calculate inline
        $savingsAccountName = $this->product->getAccountName('savings_account');
        if (!$savingsAccountName) {
            return 0;
        }
        
        $deposits = $this->transactions()
            ->where('account_name', $savingsAccountName)
            ->where('dr_cr', 'cr')
            ->sum('amount');
        
        $withdrawals = $this->transactions()
            ->where('account_name', $savingsAccountName)
            ->where('dr_cr', 'dr')
            ->sum('amount');
        
        return max(0, $deposits - $withdrawals);
    }

    /**
     * Scope to get only active accounts
     */
    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }
}
