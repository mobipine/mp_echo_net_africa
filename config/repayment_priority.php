<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Repayment Priority Configuration
    |--------------------------------------------------------------------------
    |
    | This configuration determines how loan repayments are allocated when
    | there are both principal and interest amounts outstanding.
    |
    | Options:
    | - 'interest': Interest is paid off first, then principal
    | - 'principal': Principal is paid off first, then interest  
    | - 'interest+principal': Amount is divided proportionally between both
    |
    */

    'priority' => env('REPAYMENT_PRIORITY', 'interest'),

    /*
    |--------------------------------------------------------------------------
    | Interest Calculation Settings
    |--------------------------------------------------------------------------
    |
    | Configuration for interest calculation and accrual
    |
    */

    'interest' => [
        // Minimum interest amount to accrue (prevents dust amounts)
        'minimum_amount' => 0.01,
        
        // Maximum interest accrual per day (safety limit)
        'daily_maximum' => 10000.00,
        
        // Grace period in days before interest starts accruing
        'grace_period_days' => 0,
    ],

    /*
    |--------------------------------------------------------------------------
    | Loan Charges Configuration
    |--------------------------------------------------------------------------
    |
    | Settings for loan charges and fees
    |
    */

    'charges' => [
        // Whether to apply loan charges on loan issuance
        'apply_on_issuance' => true,
        
        // Whether charges are deducted from principal or added to total
        'deduct_from_principal' => true,
        
        // Minimum charge amount
        'minimum_amount' => 0.00,
        
        // Maximum charge amount (as percentage of principal)
        'maximum_percentage' => 10.0,
    ],

    /*
    |--------------------------------------------------------------------------
    | Transaction Account Names
    |--------------------------------------------------------------------------
    |
    | Account names used in double-entry transactions
    |
    */

    'accounts' => [
        'bank' => 'Bank Account',
        'cash' => 'Cash Account',
        'mobile_money' => 'Mobile Money Account',
        'loans_receivable' => 'Loans Receivable',
        'interest_receivable' => 'Interest Receivable',
        'interest_income' => 'Interest Income',
        'loan_charges_receivable' => 'Loan Charges Receivable',
        'loan_charges_income' => 'Loan Charges Income',
    ],
];
