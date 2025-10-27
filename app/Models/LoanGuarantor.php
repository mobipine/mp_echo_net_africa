<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LoanGuarantor extends Model
{
    protected $fillable = [
        'loan_id',
        'guarantor_member_id',
        'guaranteed_amount',
        'guarantor_savings_at_guarantee',
        'status',
        'approved_at',
        'approved_by',
        'rejection_reason',
    ];

    protected $casts = [
        'guaranteed_amount' => 'decimal:2',
        'guarantor_savings_at_guarantee' => 'decimal:2',
        'approved_at' => 'datetime',
    ];

    /**
     * Get the loan this guarantor is for
     */
    public function loan(): BelongsTo
    {
        return $this->belongsTo(Loan::class);
    }

    /**
     * Get the member who is the guarantor
     */
    public function guarantorMember(): BelongsTo
    {
        return $this->belongsTo(Member::class, 'guarantor_member_id');
    }

    /**
     * Get the user who approved this guarantor
     */
    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}
