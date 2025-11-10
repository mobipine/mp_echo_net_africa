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
        'gender', 'dob', 'marital_status', 'profile_picture','is_active',
        'member_since', 'membership_status','stage'
    ];

    protected $casts = [
        'dob' => 'date',
        'is_active' => 'boolean',
        'member_since' => 'date',
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

    /**
     * Get the user account associated with this member.
     */
    public function user()
    {
        return $this->hasOne(User::class);
    }

    /**
     * Check if this member has a user account.
     */
    public function hasUserAccount(): bool
    {
        return !is_null($this->user);
    }

    /**
     * Get member's savings accounts
     */
    public function savingsAccounts()
    {
        return $this->hasMany(MemberSavingsAccount::class);
    }

    /**
     * Get member's product subscriptions
     */
    public function productSubscriptions()
    {
        return $this->hasMany(MemberProductSubscription::class);
    }

    /**
     * Get member's fee obligations
     */
    public function feeObligations()
    {
        return $this->hasMany(MemberFeeObligation::class);
    }

    /**
     * Get total savings across all accounts
     */
    public function getTotalSavingsAttribute(): float
    {
        // Check if SavingsService is available
        if (app()->bound(\App\Services\SavingsService::class)) {
            return app(\App\Services\SavingsService::class)->getCumulativeSavings($this);
        }
        
        // Fallback: sum all savings account balances
        return $this->savingsAccounts->sum('balance');
    }

    //create a boot function that will create a unique account number for each member on creation
    //the account number should be in the format ACC-0001, ACC-0002, etc.
    protected static function boot()
    {
        parent::boot();

        static::creating(function ($member) {
            // Temporarily assign a placeholder (required if non-nullable)
            $member->account_number = 'PENDING';
        });

        static::created(function ($member) {
            // Use the actual DB ID to guarantee uniqueness
            $member->account_number = 'ACC-' . str_pad($member->id, 4, '0', STR_PAD_LEFT);
            $member->saveQuietly();
        });
    }

    public function surveyProgresses()
    {
        return $this->hasMany(\App\Models\SurveyProgress::class, 'member_id');
    }
    public function surveyResponses()
    {
        return $this->hasMany(\App\Models\SurveyResponse::class, 'msisdn', 'phone');
    }

}
