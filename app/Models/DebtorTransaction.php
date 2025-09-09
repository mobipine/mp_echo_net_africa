<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DebtorTransaction extends Model
{
    protected $table = 'debtor_transactions';
    protected $fillable = [
        'member_id',
        'chart_of_account_id',
        'transaction_type',
        'dr_cr',
        'amount',
        'transaction_date',
        'description',
    ];

    public function member()
    {
        return $this->belongsTo(Member::class);
    }

    public function chartOfAccount()
    {
        return $this->belongsTo(ChartofAccounts::class);
    }
}
