<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Loan extends Model
{
    use HasFactory, SoftDeletes;

    protected $guarded = ['id', 'created_at', 'updated_at', 'deleted_at'];

    protected $casts = [
        'session_data' => 'array',
        'approved_at' => 'datetime',
        'release_date' => 'date',
        'due_date' => 'date',
        'principal_amount' => 'decimal:2',
        'interest_rate' => 'decimal:2',
        'interest_amount' => 'decimal:2',
        'repayment_amount' => 'decimal:2',
        'is_completed' => 'boolean',
        'loan_charges' => 'decimal:2',
        'due_at' => 'datetime',
    ];

    protected $appends = [
        'remaining_balance',
        'total_repaid',
        'is_fully_repaid',
        'is_incomplete_application',
        'member_name',
        'member_email',
        'member_phone',
        'member_national_id',
        'member_gender',
        'member_marital_status',
        'loan_product_name',
        'approved_by_name',
        'all_attributes',
    ];

    public function member()
    {
        return $this->belongsTo(Member::class, 'member_id');
    }

    public function loanProduct()
    {
        return $this->belongsTo(LoanProduct::class);
    }

    public function approvedBy()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function repayments()
    {
        return $this->hasMany(LoanRepayment::class);
    }

    public function amortizationSchedules()
    {
        return $this->hasMany(LoanAmortizationSchedule::class);
    }

    public function transactions()
    {
        return $this->hasMany(Transaction::class);
    }

    /**
     * Calculate the remaining balance for this loan
     */
    public function getRemainingBalanceAttribute()
    {
        $totalRepaid = $this->repayments()->sum('amount');
        return $this->repayment_amount - $totalRepaid;
    }

    /**
     * Get total repaid amount for this loan
     */
    public function getTotalRepaidAttribute()
    {
        return $this->repayments()->sum('amount');
    }

    /**
     * Check if loan is fully repaid
     */
    public function getIsFullyRepaidAttribute()
    {
        return $this->remaining_balance <= 0;
    }

    /**
     * Check if loan application is incomplete (has session data)
     */
    public function getIsIncompleteApplicationAttribute()
    {
        return $this->is_completed;
    }

    /**
     * Member accessors for form display
     */
    public function getMemberNameAttribute()
    {
        return $this->member?->name;
    }

    public function getMemberEmailAttribute()
    {
        return $this->member?->email;
    }

    public function getMemberPhoneAttribute()
    {
        return $this->member?->phone;
    }

    public function getMemberNationalIdAttribute()
    {
        return $this->member?->national_id;
    }

    public function getMemberGenderAttribute()
    {
        return $this->member?->gender;
    }

    public function getMemberMaritalStatusAttribute()
    {
        return $this->member?->marital_status;
    }

    /**
     * Loan product accessor for form display
     */
    public function getLoanProductNameAttribute()
    {
        return $this->loanProduct?->name;
    }

    /**
     * Approval accessor for form display
     */
    public function getApprovedByNameAttribute()
    {
        return $this->approvedBy?->name;
    }
    

    public function getAllAttributesAttribute()
    {
        //get the loan product for particular loan
        $loanProduct = $this->loanProduct;
        $allAttributes = $loanProduct->LoanProductAttributes->mapWithKeys(function ($item) {
            return [$item->loanAttribute->slug => [
                "name" => $item->loanAttribute->name,
                "value" => $item->value,
                "slug" => $item->loanAttribute->slug,
                "id" => $item->loanAttribute->id,
            ]];
        });

        // dd($allAttributes);
        return $allAttributes;
    }
}
