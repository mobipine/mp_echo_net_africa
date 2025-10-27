<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MemberProductSubscription extends Model
{
    protected $fillable = [
        'member_id',
        'sacco_product_id',
        'subscription_date',
        'start_date',
        'end_date',
        'status',
        'total_paid',
        'total_expected',
        'payment_count',
        'last_payment_date',
        'next_payment_date',
        'notes',
    ];

    protected $casts = [
        'subscription_date' => 'date',
        'start_date' => 'date',
        'end_date' => 'date',
        'last_payment_date' => 'date',
        'next_payment_date' => 'date',
        'total_paid' => 'decimal:2',
        'total_expected' => 'decimal:2',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    protected $appends = ['outstanding_amount', 'is_completed'];

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
     * Alias for product relationship
     */
    public function saccoProduct()
    {
        return $this->belongsTo(SaccoProduct::class, 'sacco_product_id');
    }

    /**
     * Get all transactions
     */
    public function transactions()
    {
        return $this->hasMany(Transaction::class, 'product_subscription_id');
    }

    /**
     * Get outstanding amount
     */
    public function getOutstandingAmountAttribute(): float
    {
        return max(0, ($this->total_expected ?? 0) - $this->total_paid);
    }

    /**
     * Check if subscription is completed
     */
    public function getIsCompletedAttribute(): bool
    {
        return $this->total_paid >= ($this->total_expected ?? 0);
    }

    /**
     * Scope to get only active subscriptions
     */
    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    /**
     * Scope to get overdue subscriptions
     */
    public function scopeOverdue($query)
    {
        return $query->where('status', 'active')
            ->where('next_payment_date', '<', now());
    }
}
