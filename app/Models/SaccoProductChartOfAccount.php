<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SaccoProductChartOfAccount extends Model
{
    protected $fillable = [
        'sacco_product_id',
        'account_type',
        'account_number',
    ];

    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Get the product this chart of account belongs to
     */
    public function product()
    {
        return $this->belongsTo(SaccoProduct::class, 'sacco_product_id');
    }

    /**
     * Get the chart of account
     */
    public function chartOfAccount()
    {
        return $this->belongsTo(ChartofAccounts::class, 'account_number', 'account_code');
    }
}
