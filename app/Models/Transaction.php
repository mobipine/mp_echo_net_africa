<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Transaction extends Model
{
    use SoftDeletes;

    protected $table = 'transactions';
    protected $fillable = [
        'account_name',
        'account_number',
        'loan_id',
        'member_id',
        'repayment_id',
        'transaction_type',
        'dr_cr',
        'amount',
        'transaction_date',
        'description',
    ];

    protected $casts = [
        'transaction_date' => 'date',
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

    public function repayment()
    {
        return $this->belongsTo(LoanRepayment::class);
    }

    public function chartOfAccount()
    {
        return $this->belongsTo(ChartofAccounts::class, 'account_number', 'account_code');
    }
}