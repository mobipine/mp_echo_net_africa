<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Member extends Model
{
    use SoftDeletes;
    protected $fillable = [
        'group_id', 'name', 'email', 'phone', 'national_id',
        'gender', 'dob', 'marital_status', 'profile_picture','is_active'
    ];

    protected $casts = [
        'dob' => 'date',
        'is_active' => 'boolean',
    ];

    public function group()
    {
        return $this->belongsTo(\App\Models\Group::class);
    }

    public function dependants()
    {
        return $this->hasMany(Dependant::class);
    }

    public function kycDocuments()
    {
        return $this->hasMany(KycDocument::class);
    }

    public function emailInboxes()
    {
        return $this->hasMany(EmailInbox::class);
    }

    public function smsInboxes()
    {
        return $this->hasMany(SMSInbox::class);
    }

    public function officials(): HasMany
    {
        return $this->hasMany(Official::class);
    }

    public function loans()
    {
        return $this->hasMany(Loan::class);
    }

    public function loanRepayments()
    {
        return $this->hasMany(LoanRepayment::class);
    }

    //create a boot function that will create a unique account number for each member on creation
    //the account number should be in the format ACC-0001, ACC-0002, etc.
    protected static function boot()
    {
        parent::boot();

        static::creating(function ($member) {
            $lastMember = self::latest()->first();
            $lastAccountNumber = $lastMember ? (int) str_replace('ACC-', '', $lastMember->account_number) : 0;
            $newAccountNumber = 'ACC-' . str_pad($lastAccountNumber + 1, 4, '0', STR_PAD_LEFT);
            $member->account_number = $newAccountNumber;
        });
    }
}
