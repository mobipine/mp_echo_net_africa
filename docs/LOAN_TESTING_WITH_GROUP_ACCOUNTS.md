# Loan Testing with Group Accounts System

## Overview

This guide will walk you through testing the complete loan lifecycle with the new group accounts system, showing how transactions flow between the organization, groups, and members.

---

## Table of Contents

1. [Setup & Prerequisites](#setup--prerequisites)
2. [Understanding the Flow](#understanding-the-flow)
3. [Step 1: Capital Advance to Group](#step-1-capital-advance-to-group)
4. [Step 2: Create Loan Application](#step-2-create-loan-application)
5. [Step 3: Approve Loan](#step-3-approve-loan)
6. [Step 4: Disburse Loan](#step-4-disburse-loan)
7. [Step 5: Record Loan Repayment](#step-5-record-loan-repayment)
8. [Verification & Reporting](#verification--reporting)
9. [Expected Transaction Flows](#expected-transaction-flows)
10. [Troubleshooting](#troubleshooting)

---

## Setup & Prerequisites

### 1. Run the Seeders

First, ensure your database is set up with loan attributes and products:

```bash
# Seed loan attributes
php artisan db:seed --class=LoanAttributesSeeder

# Seed test loan product (0% interest)
php artisan db:seed --class=TestLoanProductSeeder
```

### 2. Verify Data

Check that you have:
- ✅ 3 Groups (Imani, Jamii, Tumaini)
- ✅ Members in each group
- ✅ Group accounts created automatically
- ✅ At least one loan product available
- ✅ Organization bank account has sufficient balance (or don't worry about this for testing)

### 3. Login to Admin Panel

```
URL: http://your-domain.com/admin
Email: admin@example.com
Password: password
```

---

## Understanding the Flow

### Traditional 2-Tier System (OLD)
```
Organization Bank ↔ Member
```

### New 3-Tier System with Group Accounts
```
Organization Bank ↔ Group Bank ↔ Member
```

### Key Accounts Involved

**Organization Level:**
- `1000` - Organization Bank Account
- `1200` - Capital Advanced to Groups (Asset)

**Group Level (e.g., G1 for Imani Women Group):**
- `G1-1001` - Group Bank Account (Asset)
- `G1-1101` - Group Loans Receivable (Asset)
- `G1-1102` - Group Interest Receivable (Asset)
- `G1-2301` - Group Capital Payable (Liability to Organization)

---

## Step 1: Capital Advance to Group

Before a group can issue loans, it needs capital from the organization.

### Action Steps:

1. **Navigate to**: `Group Management → Capital Transfers → Create`

2. **Fill in the form**:
   ```
   Group: Imani Women Group
   Transfer Type: Capital Advance (Org to Group)
   Amount: 500,000
   Date: [Today's date]
   Reference Number: CAP-ADV-001 (optional)
   Purpose: Initial capital for member loans
   ```

3. **Click Create**

### Expected Transactions:

**Organization's Perspective (2 transactions):**
```
Dr  1200  Capital Advanced to Groups         500,000
Cr  1000  Organization Bank Account          500,000
```

**Group's Perspective (2 transactions):**
```
Dr  G1-1001  Group Bank Account               500,000
Cr  G1-2301  Group Capital Payable            500,000
```

### Verification:

Navigate to `Group Management → Groups → View Imani Women Group`:

- **Financial Overview** should show:
  - Total Assets: KES 500,000
  - Total Liabilities: KES 500,000
  - Bank Balance: KES 500,000
  - Capital Advanced: KES 500,000

- **Capital Transfers tab** should show the advance

- **Transactions tab** should show 2 entries

---

## Step 2: Create Loan Application

Now that the group has funds, a member can apply for a loan.

### Action Steps:

1. **Navigate to**: `Loans → Create`

2. **Fill in the loan application**:
   ```
   Member: Member 1 Imani (from Imani Women Group)
   Loan Product: Emergency Loan (0% Interest)
   Principal Amount: 10,000
   Duration: 6 (months)
   Application Date: [Today's date]
   Purpose: Business expansion
   ```

3. **Click Create**

### What Happens:

- Loan is created with status: **"Pending Approval"**
- No transactions are created yet
- Loan is visible in the Loans list

### Verification:

- **Navigate to**: `Loans` 
- You should see the loan with status badge: **"Pending Approval"**
- Click the eye icon to view loan details

---

## Step 3: Approve Loan

The loan needs to be approved before it can be disbursed.

### Action Steps:

1. **Navigate to**: `Loans` → Find the pending loan

2. **Click the "Approve" action** (usually in the table actions or on the view page)

3. **Confirm the approval**

### What Happens:

- Loan status changes to: **"Approved"**
- Amortization schedule is generated (if configured)
- Still no financial transactions yet
- Loan is ready for disbursement

### Verification:

- Loan status should now show: **"Approved"**
- Navigate to loan view page → **Amortization Schedule tab** should show the repayment schedule

---

## Step 4: Disburse Loan

This is where the money actually moves from the group to the member.

### Action Steps:

1. **Navigate to**: `Loans` → Find the approved loan

2. **Click the "Disburse" action**

3. **Confirm disbursement**
   ```
   Disbursement Date: [Today's date]
   ```

4. **Click Disburse**

### Expected Transactions:

**Group Level (4 transactions):**

```
# Loan Principal Disbursement
Dr  G1-1101  Group Loans Receivable           10,000
Cr  G1-1001  Group Bank Account               10,000

# Processing Fee (if applicable - 0 in this case)
Dr  G1-1103  Group Loan Charges Receivable    0
Cr  G1-4102  Group Loan Charges Income        0
```

**Member Level:**
- Member now owes the group KES 10,000
- Loan status changes to: **"Disbursed"**

### Verification:

**Check Group Accounts:**

Navigate to `Group Management → Groups → View Imani Women Group`:

- **Financial Overview**:
  - Total Assets: KES 500,000 (still same - just moved from bank to loans receivable)
  - Bank Balance: KES 490,000 (decreased by loan amount)
  
- **Group Accounts tab**:
  - `G1-1001 Bank Account`: KES 490,000
  - `G1-1101 Loans Receivable`: KES 10,000
  
- **Loans tab**:
  - Should show the loan with status: **"Disbursed"**
  
- **Transactions tab**:
  - Should show 2 new transactions (DR Loans Receivable, CR Bank)

**Check Loan:**

Navigate to `Loans → View Loan`:
- Status: **"Disbursed"**
- Release Date: Today
- Outstanding Balance: KES 10,000

---

## Step 5: Record Loan Repayment

Now the member makes a repayment.

### Action Steps:

1. **Navigate to**: `Loans → View Loan`

2. **Go to the "Loan Repayments" tab**

3. **Click "Record Repayment"** (or similar action)

4. **Fill in repayment details**:
   ```
   Amount: 2,000
   Payment Date: [Today's date]
   Payment Method: Cash / M-Pesa / Bank Transfer
   Reference Number: REF-001 (optional)
   ```

5. **Click Save**

### Expected Transactions:

**Group Level (2 transactions):**

```
# Principal Repayment
Dr  G1-1001  Group Bank Account               2,000
Cr  G1-1101  Group Loans Receivable           2,000

# Interest Repayment (0 in this case)
Dr  G1-1001  Group Bank Account               0
Cr  G1-1102  Group Interest Receivable        0
```

### Verification:

**Check Group Accounts:**

Navigate to `Group Management → Groups → View Imani Women Group`:

- **Financial Overview**:
  - Bank Balance: KES 492,000 (increased by repayment)
  
- **Group Accounts tab**:
  - `G1-1001 Bank Account`: KES 492,000
  - `G1-1101 Loans Receivable`: KES 8,000 (decreased)
  
- **Transactions tab**:
  - Should show 2 new transactions

**Check Loan:**

Navigate to `Loans → View Loan`:
- Outstanding Balance: KES 8,000
- Total Paid: KES 2,000
- **Loan Repayments tab**: Shows the repayment record

---

## Verification & Reporting

### 1. Group Dashboard

Navigate to `Group Management → Groups → View [Group Name]`:

**You can see:**
- Real-time bank balance
- Total loans issued (in Loans tab)
- All transactions (in Transactions tab)
- Capital position (capital advanced vs returned)
- Financial health metrics

### 2. Double-Entry Verification

For any group, you can verify double-entry integrity:

```bash
php artisan tinker
```

```php
$group = App\Models\Group::find(1); // Imani Women Group

// Get all transactions for this group
$transactions = App\Models\Transaction::where('group_id', $group->id)->get();

// Calculate totals
$totalDebits = $transactions->where('dr_cr', 'dr')->sum('amount');
$totalCredits = $transactions->where('dr_cr', 'cr')->sum('amount');

echo "Total Debits: " . number_format($totalDebits, 2) . "\n";
echo "Total Credits: " . number_format($totalCredits, 2) . "\n";
echo "Difference: " . number_format($totalDebits - $totalCredits, 2) . "\n";

// Should be 0.00 or very close (allowing for rounding)
```

### 3. Account Balance Check

```php
$group = App\Models\Group::find(1);

// Get specific account balances
$bankAccount = $group->getAccount('group_bank');
$loansReceivable = $group->getAccount('group_loans_receivable');

echo "Bank Balance: KES " . number_format($bankAccount->balance, 2) . "\n";
echo "Loans Receivable: KES " . number_format($loansReceivable->balance, 2) . "\n";
```

---

## Expected Transaction Flows

### Complete Loan Lifecycle Summary

**Starting State:**
- Organization Bank: KES [Original Amount]
- Group Bank: KES 0
- Member Loan: KES 0

**After Capital Advance (KES 500,000):**
```
Organization Bank:                 -500,000
Organization Capital Advanced:     +500,000
Group Bank:                        +500,000
Group Capital Payable:             +500,000
```

**After Loan Disbursement (KES 10,000):**
```
Group Bank:                        -10,000  (now 490,000)
Group Loans Receivable:            +10,000
Member Owes:                       +10,000
```

**After Repayment (KES 2,000):**
```
Group Bank:                        +2,000   (now 492,000)
Group Loans Receivable:            -2,000   (now 8,000)
Member Owes:                       -2,000   (now 8,000)
```

### Key Principles:

1. **Assets = Liabilities + Equity** (always balanced)
2. **Every debit has a corresponding credit**
3. **Group bank never goes negative** (validation should prevent this)
4. **Organization tracks capital advanced** (as an asset)
5. **Group tracks capital payable** (as a liability)

---

## Advanced Testing Scenarios

### Scenario 1: Multiple Loans in Same Group

1. Advance KES 500,000 to Imani Group
2. Member 1 takes KES 10,000 loan
3. Member 2 takes KES 15,000 loan
4. Member 3 takes KES 20,000 loan

**Expected Group State:**
- Bank Balance: KES 455,000
- Loans Receivable: KES 45,000
- Total Assets: Still KES 500,000

### Scenario 2: Capital Return

After some loans are repaid and the group has excess cash:

1. Navigate to `Capital Transfers → Create`
2. Select **"Capital Return (Group to Org)"**
3. Amount: KES 100,000
4. Submit

**Expected Transactions:**

**Organization:**
```
Dr  1000  Organization Bank                  100,000
Cr  1200  Capital Advanced to Groups         100,000
```

**Group:**
```
Dr  G1-2301  Group Capital Payable            100,000
Cr  G1-1001  Group Bank Account               100,000
```

**Verification:**
- Group bank balance decreases by 100,000
- Net capital outstanding decreases by 100,000

### Scenario 3: Loan Default / Write-off

(To be implemented - this would involve writing off bad debts)

---

## Troubleshooting

### Issue 1: "Insufficient funds in group bank account"

**Cause:** Group doesn't have enough money to disburse the loan

**Solution:** 
1. Check group bank balance
2. Advance more capital from organization
3. Or wait for loan repayments to increase balance

### Issue 2: "Account not found" error

**Cause:** Group accounts weren't created automatically

**Solution:**
```bash
php artisan tinker
```
```php
$group = App\Models\Group::find(1);
// Manually trigger account creation
event(new \App\Events\GroupCreated($group));
```

Or ensure the `GroupObserver` is properly registered.

### Issue 3: Transactions not showing in Group view

**Cause:** `group_id` might not be set on transactions

**Solution:** Check that the `CapitalTransferService` and loan disbursement code properly sets `group_id` on all transactions.

### Issue 4: Balance doesn't match

**Cause:** Double-entry not balanced

**Solution:** Run validation:
```php
$service = app(\App\Services\GroupTransactionService::class);
$result = $service->validateGroupDoubleEntry($group);
dd($result);
```

---

## Database Queries for Manual Verification

### View All Transactions for a Group

```sql
SELECT 
    id,
    transaction_date,
    account_name,
    account_number,
    dr_cr,
    amount,
    transaction_type,
    description
FROM transactions
WHERE group_id = 1  -- Imani Women Group
ORDER BY transaction_date DESC, id DESC;
```

### View Group Account Balances

```sql
SELECT 
    ga.account_code,
    ga.account_name,
    ga.account_nature,
    SUM(CASE WHEN t.dr_cr = 'dr' THEN t.amount ELSE 0 END) as total_debits,
    SUM(CASE WHEN t.dr_cr = 'cr' THEN t.amount ELSE 0 END) as total_credits,
    CASE 
        WHEN ga.account_nature IN ('asset', 'expense') 
        THEN SUM(CASE WHEN t.dr_cr = 'dr' THEN t.amount ELSE 0 END) - 
             SUM(CASE WHEN t.dr_cr = 'cr' THEN t.amount ELSE 0 END)
        ELSE 
             SUM(CASE WHEN t.dr_cr = 'cr' THEN t.amount ELSE 0 END) - 
             SUM(CASE WHEN t.dr_cr = 'dr' THEN t.amount ELSE 0 END)
    END as balance
FROM group_accounts ga
LEFT JOIN transactions t ON t.account_number = ga.account_code
WHERE ga.group_id = 1
GROUP BY ga.id, ga.account_code, ga.account_name, ga.account_nature
ORDER BY ga.account_code;
```

### View All Loans for a Group

```sql
SELECT 
    l.id,
    l.loan_number,
    m.name as member_name,
    l.principal_amount,
    l.remaining_balance,
    l.status,
    l.release_date
FROM loans l
JOIN members m ON l.member_id = m.id
WHERE m.group_id = 1
ORDER BY l.created_at DESC;
```

---

## Next Steps

1. ✅ Test the complete loan lifecycle with 0% interest
2. ⏭️ Create a loan product with interest and test interest calculations
3. ⏭️ Test multiple concurrent loans in different groups
4. ⏭️ Test capital returns from groups to organization
5. ⏭️ Implement loan default/write-off functionality
6. ⏭️ Build reporting dashboards for group performance
7. ⏭️ Add automated interest accrual
8. ⏭️ Implement loan restructuring

---

## Support

If you encounter any issues:

1. Check the Laravel logs: `storage/logs/laravel.log`
2. Verify database integrity with the SQL queries above
3. Use `php artisan tinker` to inspect model states
4. Check that all seeders have run successfully

---

**Happy Testing! 🚀**

