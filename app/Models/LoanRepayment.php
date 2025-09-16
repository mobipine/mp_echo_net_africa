<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class LoanRepayment extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'loan_id',
        'member_id',
        'amount',
        'repayment_date',
        'payment_method',
        'reference_number',
        'notes',
        'recorded_by',
    ];

    protected $casts = [
        'repayment_date' => 'date',
        'amount' => 'decimal:2',
    ];

    public function loan()
    {
        return $this->belongsTo(Loan::class);
    }

    public function member()
    {
        return $this->belongsTo(Member::class);
    }

    public function recordedBy()
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    public function transactions()
    {
        return $this->hasMany(Transaction::class);
    }

    /**
     * Calculate the remaining balance for a loan
     */
    public static function calculateRemainingBalance($loanId)
    {
        $loan = Loan::find($loanId);
        if (!$loan) {
            return 0;
        }

        $totalRepaid = self::where('loan_id', $loanId)->sum('amount');
        return $loan->repayment_amount - $totalRepaid;
    }

    /**
     * Get total repaid amount for a loan
     */
    public static function getTotalRepaid($loanId)
    {
        return self::where('loan_id', $loanId)->sum('amount');
    }
}