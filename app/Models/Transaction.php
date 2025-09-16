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
        'loan_id',
        'member_id',
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
}