<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Transaction extends Model
{
    protected $table = 'transactions';
    protected $fillable = [
        'chart_of_account_id',
        'transaction_type',
        'dr_cr',
        'amount',
        'transaction_date',
        'description',
    ];

    public function chartOfAccount()
    {
        return $this->belongsTo(ChartofAccounts::class);
    }
}