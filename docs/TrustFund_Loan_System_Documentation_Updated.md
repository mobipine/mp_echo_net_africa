# TrustFund Loan Management System - Updated Documentation

## Table of Contents
1. [System Overview](#system-overview)
2. [Loan Application Process](#loan-application-process)
3. [Loan Approval Process](#loan-approval-process)
4. [Transaction Creation](#transaction-creation)
5. [Chart of Accounts System](#chart-of-accounts-system)
6. [Interest Accrual System](#interest-accrual-system)
7. [Repayment Process](#repayment-process)
8. [Configuration Settings](#configuration-settings)
9. [Database Schema](#database-schema)
10. [Key Models and Relationships](#key-models-and-relationships)
11. [Testing Framework](#testing-framework)
12. [Troubleshooting](#troubleshooting)

---

## System Overview

The TrustFund Loan Management System is a comprehensive Laravel-based application that handles the complete loan lifecycle from application to repayment. The system uses Filament for the admin interface and implements a double-entry accounting system with dynamic chart of accounts.

### Key Features:
- **Dynamic Loan Attributes**: Configurable loan attributes per product
- **Chart of Accounts**: Assignable accounts per loan product
- **Double-Entry Accounting**: Proper debit/credit transactions
- **Repayment Allocation**: Configurable repayment priority
- **Amortization Schedules**: Automatic schedule generation
- **Interest Accrual**: Cycle-based interest calculation and accrual
- **Transaction Management**: Comprehensive transaction tracking with reversals
- **Testing Framework**: Built-in testing commands for development

---

## Interest Accrual System

### 1. AccrueLoanInterest Command
**File**: `app/Console/Commands/AccrueLoanInterest.php`

The system includes a sophisticated interest accrual system that calculates and records interest based on loan product configurations.

#### Key Features:
- **Cycle-Based Accrual**: Supports Daily, Weekly, Monthly, and Yearly cycles
- **Accrual Moments**: "Loan Issue" (immediate) or "After First Cycle"
- **Interest Types**: Simple, Flat, and Reducing Balance
- **Dynamic Account Mapping**: Uses loan product chart of accounts

#### Command Usage:
```bash
# Execute interest accrual
php artisan loans:accrue-interest

# Dry run (show what would be done)
php artisan loans:accrue-interest --dry-run
```

#### Interest Calculation:
```php
// Simple Interest Formula
Interest = (Principal × Rate × Days) / (365 × 100)

// Example: KES 100,000 at 12% annual, monthly cycle
// First month (31 days): KES 100,000 × 12% × 31 ÷ 365 = KES 1,019.18
```

#### Cycle Logic:
- **Daily**: Accrues every day (1 day period)
- **Weekly**: Accrues every 7 days
- **Monthly**: Accrues every 30 days
- **Yearly**: Accrues every 365 days

### 2. Interest Accrual Process

#### A. Loan Selection
The command identifies loans requiring interest accrual:
- Status: `'Approved'` or `'Active'`
- Release date: `<= now()`
- Due date: `> now()`
- Cycle timing: Due for next accrual

#### B. Transaction Creation
For each interest accrual, creates double-entry transactions:

**Debit Transaction (Interest Receivable):**
```php
Transaction::create([
    'account_name' => 'Interest Receivable',
    'account_number' => '1201',
    'transaction_type' => 'interest_accrual',
    'dr_cr' => 'dr',
    'amount' => $interestAmount,
    'description' => "Interest accrued for loan #{$loan->loan_number}",
]);
```

**Credit Transaction (Interest Income):**
```php
Transaction::create([
    'account_name' => 'Interest Income',
    'account_number' => '4101',
    'transaction_type' => 'interest_accrual',
    'dr_cr' => 'cr',
    'amount' => $interestAmount,
    'description' => "Interest income earned from loan #{$loan->loan_number}",
]);
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

### 2. Transaction Types

The system supports the following transaction types:

#### Core Transaction Types:
- `loan_issue` - Loan disbursement
- `principal_payment` - Principal repayment
- `interest_payment` - Interest payment
- `charges_payment` - Loan charges payment
- `interest_accrual` - Interest accrual

#### Reversal Transaction Types:
- `principal_payment_reversal` - Principal payment reversal
- `interest_payment_reversal` - Interest payment reversal
- `charges_payment_reversal` - Charges payment reversal

#### Additional Types:
- `penalty` - Penalty charges
- `adjustment` - Manual adjustments

### 3. Account Resolution
The system uses loan product chart of accounts with fallback to config:

```php
private static function getAccountNameFromLoanProduct(Loan $loan, string $accountType): ?string
{
    return $loan->loanProduct->getAccountName($accountType) ?? config('repayment_priority.accounts.' . $accountType);
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
- `interest_receivable` - Interest Receivable
- `interest_income` - Interest Income
- `loan_charges_receivable` - Loan Charges Receivable
- `loan_charges_income` - Loan Charges Income

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

## Testing Framework

### 1. Interest Accrual Testing
**File**: `app/Console/Commands/TestInterestAccrual.php`

Comprehensive testing command for interest accrual:

```bash
# Setup test data
php artisan test:interest-accrual --setup

# Run interest accrual test
php artisan test:interest-accrual --run

# Cleanup test data
php artisan test:interest-accrual --cleanup
```

#### Test Features:
- Creates test member with unique identifiers
- Sets up chart of accounts
- Creates loan product with attributes
- Simulates complete loan approval process
- Tests interest accrual with different cycles
- Provides detailed output and verification

### 2. Repayment Testing
**File**: `app/Console/Commands/TestRepaymentWithInterest.php`

Tests repayment allocation with interest:

```bash
php artisan test:repayment-interest {loan_id} {amount} [--method=payment_method]
```

#### Test Features:
- Shows loan details and outstanding amounts
- Displays repayment allocation breakdown
- Creates actual repayment transactions
- Verifies transaction creation

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
        'deduct_from_principal' => false,
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
'deduct_from_principal' => false
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
- session_data (json)
- is_completed (boolean)
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
- type (string)
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
- account_number (foreign key to chart_of_accounts.account_code)
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

#### C. Interest Not Accruing
**Problem**: Interest not accruing on scheduled basis
**Solution**: Check interest accrual command scheduling and loan attributes

#### D. Charges Not Deducted from Principal
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

#### D. Test Interest Accrual
```bash
# Test interest accrual with dry run
php artisan loans:accrue-interest --dry-run

# Run actual interest accrual
php artisan loans:accrue-interest

# Test with test data
php artisan test:interest-accrual --setup
php artisan test:interest-accrual --run
```

### 3. Configuration Verification

#### A. Check Repayment Priority Config
```php
// In config/repayment_priority.php
'priority' => 'interest',  // or 'principal' or 'interest+principal'
'deduct_from_principal' => false,  // or true
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

### 3. Interest Accrual
1. Scheduled command runs based on loan cycles
2. Calculates interest for current cycle period
3. Creates double-entry transactions
4. Updates loan interest amount

### 4. Repayment Processing
1. User selects loan and enters repayment amount
2. RepaymentAllocationService allocates amount based on priority
3. Transactions created for each allocation
4. Loan status updated if fully repaid

### 5. Account Resolution
1. System checks loan product chart of accounts first
2. Falls back to config file if not found
3. Creates transactions with both account_name and account_number

This system provides a robust, scalable loan management solution with proper accounting integration, flexible configuration options, and comprehensive testing capabilities.
