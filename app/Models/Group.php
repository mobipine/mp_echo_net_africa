<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Models\Transaction;

class Group extends Model
{
    protected $fillable = ['name', 'email', 'phone_number', 'county', 'sub_county', 'address', 'township','group_certificate','ward','local_implementing_partner_id','county_ENA_staff_id','formation_date','registration_number'];

    protected $casts = [
        'formation_date' => 'date',
    ];

    public function members()
    {
        return $this->hasMany(\App\Models\Member::class);
    }
    public function localImplementingPartner()
    {
        return $this->belongsTo(\App\Models\LocalImplementingPartner::class,'local_implementing_partner_id');
    }
    public function CountyENAStaff(){
        return $this->belongsTo(\App\Models\CountyENAStaff::class,'county_ENA_staff_id');   
    }
    
    public function surveys()
    {
        return $this->belongsToMany(Survey::class, 'group_survey');
    }

    public function officials(): HasMany
    {
        return $this->hasMany(Official::class);
    }

    /**
     * Get the group's accounts
     */
    public function groupAccounts(): HasMany
    {
        return $this->hasMany(GroupAccount::class);
    }

    /**
     * Get capital transfers for this group
     */
    public function capitalTransfers(): HasMany
    {
        return $this->hasMany(OrganizationGroupCapitalTransfer::class);
    }

    /**
     * Get all loans taken by members of this group
     */
    public function loans()
    {
        return $this->hasManyThrough(Loan::class, Member::class);
    }

    /**
     * Get all transactions for this group
     */
    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    /**
     * Get a specific group account by type
     */
    public function getAccount(string $accountType): ?GroupAccount
    {
        return $this->groupAccounts()->where('account_type', $accountType)->first();
    }

    /**
     * Get group's bank account balance
     */
    public function getBankBalanceAttribute(): float
    {
        $bankAccount = $this->getAccount('group_bank');
        return $bankAccount ? $bankAccount->balance : 0;
    }

    /**
     * Get total capital advanced to this group
     */
    public function getTotalCapitalAdvancedAttribute(): float
    {
        return $this->capitalTransfers()
            ->where('transfer_type', 'advance')
            ->where('status', 'completed')
            ->sum('amount');
    }

    /**
     * Get total capital returned from this group
     */
    public function getTotalCapitalReturnedAttribute(): float
    {
        return $this->capitalTransfers()
            ->where('transfer_type', 'return')
            ->where('status', 'completed')
            ->sum('amount');
    }

    /**
     * Get net capital outstanding (advanced - returned)
     */
    public function getNetCapitalOutstandingAttribute(): float
    {
        return $this->total_capital_advanced - $this->total_capital_returned;
    }
}
