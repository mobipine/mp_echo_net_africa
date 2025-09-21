# AccrueLoanInterest Command Documentation

## Overview

The `AccrueLoanInterest` command is a Laravel Artisan command that automatically calculates and records interest for active loans based on their configured interest cycles and accrual moments. This command ensures that interest is accrued at the correct intervals rather than all at once.

## Command Signature

```bash
php artisan loans:accrue-interest [--dry-run]
```

### Options

- `--dry-run`: Shows what would be done without executing the actual transactions

## How It Works

### 1. Loan Selection

The command identifies loans that require interest accrual by:

- **Status Filter**: Only processes loans with status `'Approved'` or `'Active'`
- **Release Date**: Only processes loans that have been released (`release_date <= now()`)
- **Due Date**: Only processes loans that are not yet due (`due_at > now()`)
- **Cycle Check**: Verifies if the loan is due for the next interest accrual cycle

### 2. Interest Accrual Logic

The command respects two key loan product attributes:

#### A. Interest Accrual Moment
- **`'Loan issue'`**: Interest accrues immediately when the loan is approved
- **`'After First Cycle'`**: Interest accrues only after the first cycle period has passed

#### B. Interest Cycle
- **`'Daily'`**: Interest accrues every day
- **`'Weekly'`**: Interest accrues every week (7 days)
- **`'Monthly'`**: Interest accrues every month (30 days)
- **`'Yearly'`**: Interest accrues every year (365 days)

### 3. Cycle-Based Calculation

The command calculates interest for **exactly one cycle period**, not for the entire time since loan release:

#### First Accrual (After First Cycle)
- **Period**: From loan release date to first cycle date
- **Example**: Loan released on Aug 17, monthly cycle → accrues interest for Aug 17 to Sep 17 (31 days)

#### Subsequent Accruals
- **Period**: Exactly one cycle period
- **Example**: Monthly cycle → accrues interest for exactly 30 days

### 4. Interest Calculation Methods

The command supports three interest calculation methods:

#### Simple Interest
```php
Interest = (Principal × Rate × Days) / (365 × 100)
```

#### Flat Interest
```php
Interest = (Principal × Rate × Days) / (365 × 100)
```

#### Reducing Balance Interest
```php
Interest = (Remaining Balance × Rate × Days) / (365 × 100)
```

## Example Scenarios

### Scenario 1: Monthly Cycle with "After First Cycle"

**Loan Details:**
- Principal: KES 100,000
- Interest Rate: 12% per annum
- Cycle: Monthly
- Accrual Moment: After First Cycle
- Release Date: August 17, 2025

**Timeline:**
- **August 17**: Loan released, no interest accrued
- **September 17**: First accrual due (31 days later)
  - Interest: KES 100,000 × 12% × 31 days ÷ 365 = KES 1,019.18
- **October 17**: Second accrual due (30 days later)
  - Interest: KES 100,000 × 12% × 30 days ÷ 365 = KES 986.30
- **November 17**: Third accrual due (30 days later)
  - Interest: KES 100,000 × 12% × 30 days ÷ 365 = KES 986.30

### Scenario 2: Daily Cycle with "Loan Issue"

**Loan Details:**
- Principal: KES 100,000
- Interest Rate: 12% per annum
- Cycle: Daily
- Accrual Moment: Loan Issue
- Release Date: August 17, 2025

**Timeline:**
- **August 17**: Loan released, immediate accrual
  - Interest: KES 100,000 × 12% × 1 day ÷ 365 = KES 32.88
- **August 18**: Second accrual due
  - Interest: KES 100,000 × 12% × 1 day ÷ 365 = KES 32.88
- **August 19**: Third accrual due
  - Interest: KES 100,000 × 12% × 1 day ÷ 365 = KES 32.88

### Scenario 3: Weekly Cycle with "After First Cycle"

**Loan Details:**
- Principal: KES 100,000
- Interest Rate: 12% per annum
- Cycle: Weekly
- Accrual Moment: After First Cycle
- Release Date: August 17, 2025

**Timeline:**
- **August 17**: Loan released, no interest accrued
- **August 24**: First accrual due (7 days later)
  - Interest: KES 100,000 × 12% × 7 days ÷ 365 = KES 230.14
- **August 31**: Second accrual due (7 days later)
  - Interest: KES 100,000 × 12% × 7 days ÷ 365 = KES 230.14

## Transaction Creation

For each interest accrual, the command creates two double-entry transactions:

### 1. Debit Transaction (Interest Receivable)
```php
Transaction::create([
    'account_name' => 'Interest Receivable', // From loan product or config
    'account_number' => '1201', // From loan product or null
    'loan_id' => $loan->id,
    'member_id' => $loan->member_id,
    'transaction_type' => 'interest_accrual',
    'dr_cr' => 'dr',
    'amount' => $interestAmount,
    'transaction_date' => now(),
    'description' => "Interest accrued for loan #{$loan->loan_number} - {$loan->member->name}",
]);
```

### 2. Credit Transaction (Interest Income)
```php
Transaction::create([
    'account_name' => 'Interest Income', // From loan product or config
    'account_number' => '4101', // From loan product or null
    'loan_id' => $loan->id,
    'member_id' => $loan->member_id,
    'transaction_type' => 'interest_accrual',
    'dr_cr' => 'cr',
    'amount' => $interestAmount,
    'transaction_date' => now(),
    'description' => "Interest income earned from loan #{$loan->loan_number} - {$loan->member->name}",
]);
```

## Key Methods

### `getLoansForInterestAccrual()`
- Retrieves all active loans that need interest accrual
- Applies status, date, and cycle-based filters

### `shouldAccrueInterest(Loan $loan)`
- Determines if a specific loan should accrue interest
- Checks accrual moment and cycle timing

### `isDueForNextCycle(Loan $loan, string $cycle)`
- Checks if the loan is due for the next interest accrual cycle
- Compares current date with next cycle date

### `getNextCycleDate(Carbon $referenceDate, string $cycle)`
- Calculates the next cycle date based on cycle type
- Handles Daily, Weekly, Monthly, and Yearly cycles

### `calculateInterestForLoan(Loan $loan)`
- Calculates interest for exactly one cycle period
- Handles first accrual vs subsequent accruals differently

### `getCyclePeriodInDays(string $cycle)`
- Returns the number of days in a cycle period
- Daily: 1, Weekly: 7, Monthly: 30, Yearly: 365

## Configuration

The command uses dynamic account mapping from loan products with fallbacks to config:

```php
// From config/repayment_priority.php
'accounts' => [
    'interest_receivable' => 'Interest Receivable',
    'interest_income' => 'Interest Income',
    // ... other accounts
]
```

## Testing

### Manual Testing
```bash
# Test with dry run
php artisan loans:accrue-interest --dry-run

# Execute interest accrual
php artisan loans:accrue-interest

# Test with test data
php artisan test:interest-accrual --setup
php artisan test:interest-accrual --run
php artisan test:interest-accrual --cleanup
```

### Test Results Verification
- Check that interest is accrued for exactly one cycle period
- Verify that transactions are created with correct account names/numbers
- Confirm that loan's `interest_amount` field is updated
- Ensure no duplicate accruals for the same period

## Scheduling

The command should be scheduled to run at appropriate intervals:

```php
// In app/Console/Kernel.php
protected function schedule(Schedule $schedule)
{
    // Run daily for daily cycles
    $schedule->command('loans:accrue-interest')->daily();
    
    // Or run multiple times per day for more frequent cycles
    $schedule->command('loans:accrue-interest')->hourly();
}
```

## Error Handling

The command includes comprehensive error handling:

- **Individual Loan Errors**: If one loan fails, others continue processing
- **Logging**: All errors are logged with loan ID and error details
- **Graceful Degradation**: Missing loan attributes fall back to defaults
- **Transaction Safety**: Uses database transactions to ensure data integrity

## Performance Considerations

- **Batch Processing**: Processes multiple loans in a single run
- **Efficient Queries**: Uses eager loading for related models
- **Index Optimization**: Ensure proper database indexes on loan status, dates, and transaction types
- **Memory Management**: Processes loans one at a time to avoid memory issues

## Troubleshooting

### Common Issues

1. **No loans processed**: Check loan status, release dates, and cycle timing
2. **Incorrect interest amounts**: Verify interest rate and cycle configuration
3. **Missing transactions**: Check account mapping in loan products
4. **Duplicate accruals**: Verify last accrual date logic

### Debug Commands

```bash
# Check loan attributes
php artisan tinker --execute="
\$loan = App\Models\Loan::find(LOAN_ID);
print_r(\$loan->all_attributes);
"

# Check last accrual date
php artisan tinker --execute="
\$loan = App\Models\Loan::find(LOAN_ID);
\$lastAccrual = App\Models\Transaction::where('loan_id', \$loan->id)
    ->where('transaction_type', 'interest_accrual')
    ->orderBy('transaction_date', 'desc')
    ->first();
echo \$lastAccrual ? \$lastAccrual->transaction_date : 'No accrual found';
"
```

## Conclusion

The `AccrueLoanInterest` command provides a robust, cycle-based interest accrual system that:

- ✅ Respects loan product configurations
- ✅ Calculates interest for exact cycle periods
- ✅ Creates proper double-entry transactions
- ✅ Handles multiple interest types and cycles
- ✅ Includes comprehensive error handling
- ✅ Supports dry-run testing
- ✅ Uses dynamic account mapping

This ensures accurate, timely, and auditable interest accrual for all loan products in the system.
