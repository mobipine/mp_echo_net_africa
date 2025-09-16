<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Loan extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'member_id',
        'loan_product_id',
        'loan_number',
        'status',
        'principal_amount',
        'interest_rate',
        'interest_cycle',
        'repayment_amount',
        'interest_amount',
        'release_date',
        'due_date',
        'loan_duration',
        'loan_purpose',
        'repayment_schedule',
        'approved_by',
        'approved_at',
        'session_data',
        'is_completed',
    ];

    protected $casts = [
        'session_data' => 'array',
        'approved_at' => 'datetime',
        'release_date' => 'date',
        'due_date' => 'date',
        'principal_amount' => 'decimal:2',
        'interest_rate' => 'decimal:2',
        'interest_amount' => 'decimal:2',
        'repayment_amount' => 'decimal:2',
        'is_completed' => 'boolean',
    ];

    public function member()
    {
        return $this->belongsTo(Member::class);
    }

    public function loanProduct()
    {
        return $this->belongsTo(LoanProduct::class);
    }

    public function approvedBy()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function repayments()
    {
        return $this->hasMany(LoanRepayment::class);
    }

    public function amortizationSchedules()
    {
        return $this->hasMany(LoanAmortizationSchedule::class);
    }

    /**
     * Calculate the remaining balance for this loan
     */
    public function getRemainingBalanceAttribute()
    {
        $totalRepaid = $this->repayments()->sum('amount');
        return $this->repayment_amount - $totalRepaid;
    }

    /**
     * Get total repaid amount for this loan
     */
    public function getTotalRepaidAttribute()
    {
        return $this->repayments()->sum('amount');
    }

    /**
     * Check if loan is fully repaid
     */
    public function getIsFullyRepaidAttribute()
    {
        return $this->remaining_balance <= 0;
    }

    /**
     * Check if loan application is incomplete (has session data)
     */
    public function getIsIncompleteApplicationAttribute()
    {
        return $this->is_completed;
    }

    public function calculateLoanDetails()
    {
        // Placeholder for loan calculation logic based on attributes
    }
}
