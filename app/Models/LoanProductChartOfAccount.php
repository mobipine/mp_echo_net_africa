<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class LoanProductChartOfAccount extends Model
{
    use HasFactory;

    protected $table = 'loan_product_chart_of_accounts';

    protected $fillable = [
        'loan_product_id',
        'account_type',
        'account_number',
    ];

    public function loanProduct()
    {
        return $this->belongsTo(LoanProduct::class);
    }

    public function chartOfAccount()
    {
        return $this->belongsTo(ChartofAccounts::class, 'account_number', 'account_code');
    }
}