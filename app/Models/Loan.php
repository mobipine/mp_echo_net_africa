<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Loan extends Model
{
    use HasFactory;

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
        'wizard_data',
        'amortization_schedule',
        'is_completed',
        'submitted_at',
        'approved_at',
        'approved_by',
    ];

    // Cast the JSON fields
    protected $casts = [
        'wizard_data' => 'array',
        'amortization_schedule' => 'array',
        'release_date' => 'date',
        'due_at' => 'datetime',
    ];

    public function member()
    {
        return $this->belongsTo(Member::class);
    }

    public function loanProduct()
    {
        return $this->belongsTo(LoanProduct::class);
    }

    public function calculateLoanDetails()
    {
        // Placeholder for loan calculation logic based on attributes
    }
}
