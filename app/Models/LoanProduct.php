<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LoanProduct extends Model
{
    protected $fillable = ['name', 'description', 'is_active'];

    public function LoanProductAttributes()
    {
        return $this->hasMany(LoanProductAttribute::class, 'loan_product_id', 'id');
    }

    public function chartOfAccounts()
    {
        return $this->hasMany(LoanProductChartOfAccount::class);
    }
    
    /**
     * Get account number for a specific account type
     */
    public function getAccountNumber(string $accountType): ?string
    {
        $account = $this->chartOfAccounts()->where('account_type', $accountType)->first();
        return $account?->account_number;
    }
    
    /**
     * Get account name for a specific account type
     */
    public function getAccountName(string $accountType): ?string
    {
        $account = $this->chartOfAccounts()->where('account_type', $accountType)->first();
        return $account?->chartOfAccount?->name;
    }
}
