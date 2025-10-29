<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MemberFeeObligation extends Model
{
    protected $fillable = [
        'member_id',
        'sacco_product_id',
        'amount_due',
        'amount_paid',
        'due_date',
        'status',
        'description',
        'notes',
    ];

    protected $casts = [
        'amount_due' => 'decimal:2',
        'amount_paid' => 'decimal:2',
        'due_date' => 'date',
    ];

    protected $appends = ['balance_due'];

    /**
     * Get the member
     */
    public function member()
    {
        return $this->belongsTo(Member::class);
    }

    /**
     * Get the SACCO product (fee)
     */
    public function saccoProduct()
    {
        return $this->belongsTo(SaccoProduct::class, 'sacco_product_id');
    }
    
    /**
     * Alias for saccoProduct
     */
    public function product()
    {
        return $this->belongsTo(SaccoProduct::class, 'sacco_product_id');
    }

    /**
     * Get transactions for this obligation
     */
    public function transactions()
    {
        return Transaction::where('member_id', $this->member_id)
            ->where('transaction_type', 'fee_payment')
            ->where('metadata->product_code', $this->saccoProduct->code);
    }

    /**
     * Get balance due
     */
    public function getBalanceDueAttribute(): float
    {
        return max(0, $this->amount_due - $this->amount_paid);
    }

    /**
     * Update status based on payments
     */
    public function updateStatus(): void
    {
        if ($this->amount_paid >= $this->amount_due) {
            $this->status = 'paid';
        } elseif ($this->amount_paid > 0) {
            $this->status = 'partially_paid';
        } else {
            $this->status = 'pending';
        }
        $this->save();
    }

    /**
     * Scope to get pending obligations
     */
    public function scopePending($query)
    {
        return $query->whereIn('status', ['pending', 'partially_paid']);
    }

    /**
     * Scope to get paid obligations
     */
    public function scopePaid($query)
    {
        return $query->where('status', 'paid');
    }

    /**
     * Scope to get overdue obligations
     */
    public function scopeOverdue($query)
    {
        return $query->whereIn('status', ['pending', 'partially_paid'])
            ->where('due_date', '<', now());
    }
}

