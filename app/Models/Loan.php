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
        'rejected_at' => 'datetime',
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

    public function rejectedBy()
    {
        return $this->belongsTo(User::class, 'rejected_by');
    }

    public function guarantors()
    {
        return $this->hasMany(LoanGuarantor::class);
    }

    public function collateralAttachments()
    {
        return $this->hasMany(LoanCollateralAttachment::class);
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
     * Calculate the current amount owed for this loan (including charges and accrued interest)
     */
    public function getRemainingBalanceAttribute()
    {
        // Get outstanding loan charges
        $outstandingCharges = $this->getOutstandingLoanCharges();

        // Get outstanding interest
        $outstandingInterest = $this->getOutstandingInterest();

        // Get outstanding principal
        $outstandingPrincipal = $this->getOutstandingPrincipal();

        return $outstandingCharges + $outstandingInterest + $outstandingPrincipal;
    }

    /**
     * Get outstanding loan charges
     */
    public function getOutstandingLoanCharges(): float
    {
        // Outstanding charges should be based on the balance of Loan Charges Receivable
        // rather than the income entry. This ensures that when charges are deducted
        // from principal on issuance (no receivable created), outstanding charges are zero.

        // Get account name from loan product, fallback to config
        $receivableAccountName = $this->loanProduct->getAccountName('loan_charges_receivable') ?? config('repayment_priority.accounts.loan_charges_receivable');

        // Sum debits and credits on the receivable account for this loan
        $debitedToReceivable = $this->transactions()
            ->where('account_name', $receivableAccountName)
            ->where('dr_cr', 'dr')
            ->sum('amount');

        $creditedToReceivable = $this->transactions()
            ->where('account_name', $receivableAccountName)
            ->where('dr_cr', 'cr')
            ->sum('amount');

        // Add charges payment reversals (which increase outstanding balance)
        $totalChargesReversals = $this->transactions()
            ->where('transaction_type', 'charges_payment_reversal')
            ->where('dr_cr', 'dr')
            ->sum('amount');

        return max(0, $debitedToReceivable - $creditedToReceivable + $totalChargesReversals);
    }

    /**
     * Get outstanding interest
     */
    public function getOutstandingInterest(): float
    {
        // Get total interest accrued
        $totalInterestAccrued = $this->transactions()
            ->where('transaction_type', 'interest_accrual')
            ->where('dr_cr', 'dr')
            ->sum('amount');

        // Get total interest paid
        $totalInterestPaid = $this->transactions()
            ->where('transaction_type', 'interest_payment')
            ->where('dr_cr', 'cr')
            ->sum('amount');

        // Add interest payment reversals (which increase outstanding balance)
        $totalInterestReversals = $this->transactions()
            ->where('transaction_type', 'interest_payment_reversal')
            ->where('dr_cr', 'dr')
            ->sum('amount');

        return max(0, $totalInterestAccrued - $totalInterestPaid + $totalInterestReversals);
    }

    /**
     * Get outstanding principal
     */
    public function getOutstandingPrincipal(): float
    {
        // Get total principal disbursed
        $totalPrincipalDisbursed = $this->transactions()
            ->where('transaction_type', 'loan_issue')
            ->where('dr_cr', 'dr')
            ->sum('amount');

        // Get total principal repaid (including reversals)
        $totalPrincipalRepaid = $this->transactions()
            ->where('transaction_type', 'principal_payment')
            ->where('dr_cr', 'cr')
            ->sum('amount');

        // Subtract principal payment reversals (which increase outstanding balance)
        $totalPrincipalReversals = $this->transactions()
            ->where('transaction_type', 'principal_payment_reversal')
            ->where('dr_cr', 'dr')
            ->sum('amount');

        return max(0, $totalPrincipalDisbursed - $totalPrincipalRepaid + $totalPrincipalReversals);
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
