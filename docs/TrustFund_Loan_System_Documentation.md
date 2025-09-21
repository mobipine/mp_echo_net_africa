# TrustFund Loan Management System - Complete Documentation

## Table of Contents
1. [System Overview](#system-overview)
2. [Loan Application Process](#loan-application-process)
3. [Loan Approval Process](#loan-approval-process)
4. [Transaction Creation](#transaction-creation)
5. [Chart of Accounts System](#chart-of-accounts-system)
6. [Repayment Process](#repayment-process)
7. [Configuration Settings](#configuration-settings)
8. [Database Schema](#database-schema)
9. [Key Models and Relationships](#key-models-and-relationships)
10. [Troubleshooting](#troubleshooting)

---

## System Overview

The TrustFund Loan Management System is a comprehensive Laravel-based application that handles the complete loan lifecycle from application to repayment. The system uses Filament for the admin interface and implements a double-entry accounting system with dynamic chart of accounts.

### Key Features:
- **Dynamic Loan Attributes**: Configurable loan attributes per product
- **Chart of Accounts**: Assignable accounts per loan product
- **Double-Entry Accounting**: Proper debit/credit transactions
- **Repayment Allocation**: Configurable repayment priority
- **Amortization Schedules**: Automatic schedule generation

---

## Loan Application Process

### 1. Application Entry
**File**: `app/Filament/Pages/LoanApplication.php`

The loan application process follows these steps:

1. **Member Selection**: User selects an existing member
2. **Loan Product Selection**: Choose from available loan products
3. **Dynamic Form Generation**: Form fields are generated based on loan product attributes
4. **Data Collection**: 
   - Basic loan details (amount, duration, purpose)
   - Guarantor information
   - Collateral details
   - Additional notes

### 2. Application States
- **Incomplete Application**: Draft saved with session data
- **Pending Approval**: Complete application submitted
- **Approved**: Loan approved and transactions created
- **Rejected**: Application rejected
- **Fully Repaid**: Loan completely paid off

### 3. Key Methods
```php
// Generate unique loan number
private function generateLoanNumber($memberId): string

// Save application data
public function submit(): void

// Save as draft
public function saveDraft(): void
```

---

## Loan Approval Process

### 1. Approval Workflow
**File**: `app/Filament/Resources/LoanResource.php`

When a loan is approved:

1. **Status Update**: Changes status to "Approved"
2. **Approval Tracking**: Records approver and timestamp
3. **Transaction Creation**: Creates accounting transactions
4. **Amortization Schedule**: Generates repayment schedule

### 2. Approval Action
```php
Action::make('approve')
    ->action(function (Loan $record) {
        LoanAmortizationSchedule::generateSchedule($record);
        $record->update([
            'status' => 'Approved',
            'approved_by' => Auth::id(),
            'approved_at' => now(),
        ]);
        
        // Create transactions
        static::createLoanTransactions($record);
    })
```

---

## Transaction Creation

### 1. Loan Approval Transactions
**File**: `app/Filament/Resources/LoanResource.php` - `createLoanTransactions()`

When a loan is approved, the following transactions are created:

#### A. Loan Receivable Transaction
```php
Transaction::create([
    'account_name' => $loansReceivableName,
    'account_number' => $loansReceivableNumber,
    'loan_id' => $loan->id,
    'member_id' => $loan->member_id,
    'transaction_type' => 'loan_issue',
    'dr_cr' => 'dr',  // Debit
    'amount' => $loan->principal_amount,
    'transaction_date' => $loan->release_date,
    'description' => "Loan issued to member {$loan->member->name}",
]);
```

#### B. Bank Disbursement Transaction
```php
Transaction::create([
    'account_name' => $bankAccountName,
    'account_number' => $bankAccountNumber,
    'loan_id' => $loan->id,
    'member_id' => $loan->member_id,
    'transaction_type' => 'loan_issue',
    'dr_cr' => 'cr',  // Credit
    'amount' => $netDisbursement,
    'transaction_date' => $loan->release_date,
    'description' => "Bank disbursement for loan issued to member {$loan->member->name}",
]);
```

#### C. Loan Charges Transactions (if applicable)
If loan charges are configured:

**Income Transaction:**
```php
Transaction::create([
    'account_name' => $chargesIncomeName,
    'account_number' => $chargesIncomeNumber,
    'transaction_type' => 'loan_charges',
    'dr_cr' => 'cr',  // Credit
    'amount' => $loanCharges,
]);
```

**Receivable Transaction (if not deducted from principal):**
```php
Transaction::create([
    'account_name' => $chargesReceivableName,
    'account_number' => $chargesReceivableNumber,
    'transaction_type' => 'loan_charges',
    'dr_cr' => 'dr',  // Debit
    'amount' => $loanCharges,
]);
```

### 2. Account Resolution
The system uses loan product chart of accounts with fallback to config:

```php
private static function getAccountNameFromLoanProduct(Loan $loan, string $accountType): ?string
{
    return $loan->loanProduct->getAccountName($accountType);
}

private static function getAccountNumberFromLoanProduct(Loan $loan, string $accountType): ?string
{
    return $loan->loanProduct->getAccountNumber($accountType);
}
```

---

## Chart of Accounts System

### 1. Chart of Accounts Management
**File**: `app/Filament/Resources/ChartofAccountsResource.php`

The system maintains a master chart of accounts with:
- **Account Code**: Unique identifier for external systems
- **Account Name**: Human-readable name
- **Account Type**: Asset, Liability, Equity, Revenue, Expense
- **Slug**: URL-friendly identifier

### 2. Loan Product Account Assignment
**File**: `app/Filament/Resources/LoanProductResource/RelationManagers/ChartOfAccountsRelationManager.php`

Each loan product can assign specific accounts to account types:

#### Standard Account Types:
- `bank` - Bank Account
- `cash` - Cash Account
- `mobile_money` - Mobile Money Account
- `loans_receivable` - Loans Receivable
- `interest_income` - Interest Income

#### Dynamic Account Types:
Based on loan attributes, the system can create:
- `loan_charges_receivable` - Loan Charges Receivable
- `loan_charges_income` - Loan Charges Income
- `loan_penalty_receivable` - Loan Penalty Receivable
- `loan_penalty_income` - Loan Penalty Income
- And more...

### 3. Account Resolution Logic
**File**: `app/Models/LoanProduct.php`

```php
public function getAccountNumber(string $accountType): ?string
{
    $account = $this->chartOfAccounts()->where('account_type', $accountType)->first();
    return $account?->account_number;
}

public function getAccountName(string $accountType): ?string
{
    $account = $this->chartOfAccounts()->where('account_type', $accountType)->first();
    return $account?->chartOfAccount?->name;
}
```

---

## Repayment Process

### 1. Repayment Entry
**File**: `app/Filament/Pages/LoanRepaymentPage.php`

The repayment process:

1. **Member Selection**: Choose member
2. **Loan Selection**: Select loan with outstanding balance
3. **Amount Entry**: Enter repayment amount
4. **Payment Details**: Payment method, date, reference
5. **Transaction Creation**: Uses RepaymentAllocationService

### 2. Outstanding Balance Calculation
**File**: `app/Models/Loan.php`

```php
public function getRemainingBalanceAttribute()
{
    $outstandingCharges = $this->getOutstandingLoanCharges();
    $outstandingInterest = $this->getOutstandingInterest();
    $outstandingPrincipal = $this->getOutstandingPrincipal();
    
    return $outstandingCharges + $outstandingInterest + $outstandingPrincipal;
}
```

### 3. Repayment Allocation Service
**File**: `app/Services/RepaymentAllocationService.php`

The service handles repayment allocation based on configuration:

#### A. Allocation Priority
```php
public function allocateRepayment(Loan $loan, float $repaymentAmount): array
{
    $priority = config('repayment_priority.priority', 'interest');
    
    // Get outstanding amounts
    $outstandingCharges = $this->getOutstandingLoanCharges($loan);
    $outstandingInterest = $this->getOutstandingInterest($loan);
    $outstandingPrincipal = $this->getOutstandingPrincipal($loan);
    
    // Allocate based on priority
    // 1. Charges first (always)
    // 2. Then interest or principal based on config
}
```

#### B. Transaction Creation
For each allocated amount, creates paired transactions:

**Charges Payment:**
```php
// Debit: Bank Account
[
    'account_name' => $bankAccountName,
    'account_number' => $bankAccountNumber,
    'transaction_type' => 'charges_payment',
    'dr_cr' => 'dr',
    'amount' => $amount,
]

// Credit: Loan Charges Receivable
[
    'account_name' => $chargesReceivableName,
    'account_number' => $chargesReceivableNumber,
    'transaction_type' => 'charges_payment',
    'dr_cr' => 'cr',
    'amount' => $amount,
]
```

### 4. Repayment Priority Configuration
**File**: `config/repayment_priority.php`

```php
'priority' => env('REPAYMENT_PRIORITY', 'interest'),

// Options:
// - 'interest': Interest first, then principal
// - 'principal': Principal first, then interest
// - 'interest+principal': Proportional allocation
```

---

## Configuration Settings

### 1. Repayment Priority Config
**File**: `config/repayment_priority.php`

```php
return [
    // Repayment allocation priority
    'priority' => env('REPAYMENT_PRIORITY', 'interest'),
    
    // Interest calculation settings
    'interest' => [
        'minimum_amount' => 0.01,
        'daily_maximum' => 10000.00,
        'grace_period_days' => 0,
    ],
    
    // Loan charges configuration
    'charges' => [
        'apply_on_issuance' => true,
        'deduct_from_principal' => true,
        'minimum_amount' => 0.00,
        'maximum_percentage' => 10.0,
    ],
    
    // Default account names (fallback)
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
```

### 2. Key Configuration Options

#### A. Charge Deduction from Principal
```php
'deduct_from_principal' => true
```
- **true**: Charges deducted from disbursement amount
- **false**: Charges added to total loan amount

#### B. Repayment Priority
```php
'priority' => 'interest'
```
- **interest**: Pay interest first, then principal
- **principal**: Pay principal first, then interest
- **interest+principal**: Proportional allocation

---

## Database Schema

### 1. Core Tables

#### A. loans
```sql
- id (primary key)
- member_id (foreign key)
- loan_product_id (foreign key)
- loan_number (unique)
- principal_amount (decimal)
- interest_rate (decimal)
- interest_amount (decimal)
- repayment_amount (decimal)
- release_date (date)
- due_at (datetime)
- loan_duration (integer)
- status (enum)
- approved_by (foreign key)
- approved_at (datetime)
- created_at, updated_at, deleted_at
```

#### B. loan_products
```sql
- id (primary key)
- name (string)
- description (text)
- is_active (boolean)
- created_at, updated_at
```

#### C. loan_attributes
```sql
- id (primary key)
- name (string)
- slug (string)
- type (enum: string, integer, decimal, boolean, date, file, select, multiselect)
- options (text)
- is_required (boolean)
- created_at, updated_at
```

#### D. loan_product_attributes
```sql
- id (primary key)
- loan_product_id (foreign key)
- loan_attribute_id (foreign key)
- value (string)
- order (integer)
- created_at, updated_at
```

### 2. Chart of Accounts Tables

#### A. chart_of_accounts
```sql
- id (primary key)
- name (string)
- slug (string)
- account_code (string, unique)
- account_type (string)
- created_at, updated_at
```

#### B. loan_product_chart_of_accounts
```sql
- id (primary key)
- loan_product_id (foreign key)
- account_type (string)
- account_number (foreign key to chart_of_accounts.account_code)
- created_at, updated_at
- unique(loan_product_id, account_type)
```

### 3. Transaction Tables

#### A. transactions
```sql
- id (primary key)
- account_name (string)
- account_number (string, foreign key)
- loan_id (foreign key)
- member_id (foreign key)
- repayment_id (foreign key)
- transaction_type (string)
- dr_cr (enum: dr, cr)
- amount (decimal)
- transaction_date (date)
- description (text)
- created_at, updated_at, deleted_at
```

#### B. loan_repayments
```sql
- id (primary key)
- loan_id (foreign key)
- member_id (foreign key)
- amount (decimal)
- repayment_date (date)
- payment_method (string)
- reference_number (string)
- notes (text)
- recorded_by (foreign key)
- created_at, updated_at
```

---

## Key Models and Relationships

### 1. Loan Model
**File**: `app/Models/Loan.php`

#### Key Relationships:
```php
public function member()
{
    return $this->belongsTo(Member::class, 'member_id');
}

public function loanProduct()
{
    return $this->belongsTo(LoanProduct::class);
}

public function repayments()
{
    return $this->hasMany(LoanRepayment::class);
}

public function transactions()
{
    return $this->hasMany(Transaction::class);
}
```

#### Key Methods:
```php
// Calculate remaining balance
public function getRemainingBalanceAttribute()

// Get outstanding charges
public function getOutstandingLoanCharges(): float

// Get outstanding interest
public function getOutstandingInterest(): float

// Get outstanding principal
public function getOutstandingPrincipal(): float

// Get all loan attributes
public function getAllAttributesAttribute()
```

### 2. LoanProduct Model
**File**: `app/Models/LoanProduct.php`

#### Key Relationships:
```php
public function LoanProductAttributes()
{
    return $this->hasMany(LoanProductAttribute::class, 'loan_product_id', 'id');
}

public function chartOfAccounts()
{
    return $this->hasMany(LoanProductChartOfAccount::class);
}
```

#### Key Methods:
```php
// Get account number for account type
public function getAccountNumber(string $accountType): ?string

// Get account name for account type
public function getAccountName(string $accountType): ?string
```

### 3. Transaction Model
**File**: `app/Models/Transaction.php`

#### Key Relationships:
```php
public function loan()
{
    return $this->belongsTo(Loan::class);
}

public function member()
{
    return $this->belongsTo(Member::class);
}

public function repayment()
{
    return $this->belongsTo(LoanRepayment::class);
}

public function chartOfAccount()
{
    return $this->belongsTo(ChartofAccounts::class, 'account_number', 'account_code');
}
```

---

## Troubleshooting

### 1. Common Issues

#### A. Outstanding Balance Shows Incorrect Amount
**Problem**: Loan repayment dropdown shows wrong amount
**Solution**: Check if loan product has proper chart of accounts assigned

#### B. Transactions Not Using Correct Accounts
**Problem**: Transactions use config accounts instead of loan product accounts
**Solution**: Ensure loan product has chart of accounts configured

#### C. Charges Not Deducted from Principal
**Problem**: Charges appear as outstanding when `deduct_from_principal = true`
**Solution**: Check `getOutstandingLoanCharges()` method uses receivable account balance

### 2. Debugging Steps

#### A. Check Loan Product Configuration
```php
$loan = Loan::find($id);
$loanProduct = $loan->loanProduct;

// Check if chart of accounts are assigned
$accounts = $loanProduct->chartOfAccounts;
foreach($accounts as $account) {
    echo $account->account_type . ': ' . $account->chartOfAccount->name;
}
```

#### B. Verify Transaction Creation
```php
// Check transactions for a loan
$transactions = Transaction::where('loan_id', $loanId)->get();
foreach($transactions as $transaction) {
    echo $transaction->transaction_type . ': ' . $transaction->account_name . ' (' . $transaction->dr_cr . ') ' . $transaction->amount;
}
```

#### C. Check Outstanding Balances
```php
$loan = Loan::find($id);
echo 'Charges: ' . $loan->getOutstandingLoanCharges();
echo 'Interest: ' . $loan->getOutstandingInterest();
echo 'Principal: ' . $loan->getOutstandingPrincipal();
echo 'Total: ' . $loan->remaining_balance;
```

### 3. Configuration Verification

#### A. Check Repayment Priority Config
```php
// In config/repayment_priority.php
'priority' => 'interest',  // or 'principal' or 'interest+principal'
'deduct_from_principal' => true,  // or false
```

#### B. Verify Account Names
```php
// Check if account names match between config and chart of accounts
$configAccounts = config('repayment_priority.accounts');
$chartAccounts = ChartofAccounts::pluck('name', 'account_code');
```

---

## System Flow Summary

### 1. Loan Application
1. Member selects loan product
2. Dynamic form generated from loan attributes
3. Application saved as "Pending Approval"

### 2. Loan Approval
1. Admin approves loan
2. Status changed to "Approved"
3. Transactions created using loan product chart of accounts
4. Amortization schedule generated

### 3. Repayment Processing
1. User selects loan and enters repayment amount
2. RepaymentAllocationService allocates amount based on priority
3. Transactions created for each allocation
4. Loan status updated if fully repaid

### 4. Account Resolution
1. System checks loan product chart of accounts first
2. Falls back to config file if not found
3. Creates transactions with both account_name and account_number

This system provides a robust, scalable loan management solution with proper accounting integration and flexible configuration options.
