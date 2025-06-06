<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ChartofAccounts extends Model
{
    protected $table = 'chart_of_accounts';
    protected $fillable = [
        'name',
        'account_code',
        'account_type',
    ];

   
}
