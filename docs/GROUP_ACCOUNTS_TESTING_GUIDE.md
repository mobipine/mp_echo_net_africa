# Group Accounts Implementation - Testing Guide

**Date:** October 20, 2025  
**Phase:** Phase 1 & 2 (Foundation + Capital Management)  
**Status:** Ready for Testing

---

## Prerequisites

1. PHP 8.1+
2. Laravel 10+
3. MySQL/PostgreSQL database
4. Composer installed
5. Node.js & NPM installed

---

## Step 1: Reset Database and Run Migrations

### 1.1 Clear Existing Data

Run the custom reset command to clear all existing data:

```bash
cd /Users/muchiriandrew/Sites/forumkenya

php artisan db:reset-for-group-accounts --force
```

**Expected Output:**
```
Starting database reset...

1. Clearing transactions...
   ✓ Cleared: transactions
   ...
✓ Database reset complete!

Next steps:
  1. Run: php artisan db:seed --class=OrganizationChartOfAccountsSeeder
  2. Run: php artisan db:seed --class=GroupsAndMembersTestSeeder
```

### 1.2 Run Migrations

```bash
php artisan migrate
```

**Expected Output:**
```
...
2025_10_20_100001_create_group_accounts_table ................... DONE
2025_10_20_100002_create_organization_group_capital_transfers_table ... DONE
2025_10_20_100003_add_group_id_to_transactions_table .............. DONE
```

---

## Step 2: Seed Test Data

### 2.1 Seed Organization Chart of Accounts

```bash
php artisan db:seed --class=OrganizationChartOfAccountsSeeder
```

**Expected Output:**
```
Organization chart of accounts seeded successfully!
```

**Verification:**
```bash
php artisan tinker
```
```php
// In tinker
App\Models\ChartofAccounts::count();
// Should return: 18 or more

App\Models\ChartofAccounts::where('account_code', '1201')->first();
// Should show: "Capital Advances to Groups"

exit
```

### 2.2 Seed Groups and Members

```bash
php artisan db:seed --class=GroupsAndMembersTestSeeder
```

**Expected Output:**
```
Creating test groups and members...
✓ Created: Imani Women Group (ID: 1)
  ✓ Created 5 members for Imani Group
✓ Created: Jamii Traders Group (ID: 2)
  ✓ Created 6 members for Jamii Group
✓ Created: Tumaini Youth Group (ID: 3)
  ✓ Created 4 members for Tumaini Group

Summary:
  • Total Groups: 3
  • Total Members: 15
  • Total Group Accounts: 33

Note: Group accounts were automatically created by GroupObserver
```

**Verification:**
```bash
php artisan tinker
```
```php
// In tinker
App\Models\Group::count();
// Should return: 3

App\Models\Member::count();
// Should return: 15

App\Models\GroupAccount::count();
// Should return: 33 (11 accounts per group × 3 groups)

// Check accounts for group 1
App\Models\GroupAccount::where('group_id', 1)->get()->pluck('account_code');
// Should show: G1-1001, G1-1101, G1-1102, etc.

exit
```

---

## Step 3: Access Filament Admin Panel

### 3.1 Start Development Server

```bash
php artisan serve
```

### 3.2 Access Admin Panel

Open browser and go to: `http://localhost:8000/admin`

Login with your admin credentials.

---

## Step 4: Test Group Dashboard

### 4.1 Navigate to Group Dashboard

In Filament sidebar, click on:
- **Group Management** → **Group Dashboard**

### 4.2 Verify Display

**You should see:**
1. ✅ Group selector dropdown with 3 groups
2. ✅ Financial summary cards:
   - Total Assets: KES 0.00 (no transactions yet)
   - Total Liabilities: KES 0.00
   - Net Income: KES 0.00
3. ✅ Income Statement section
4. ✅ Balance Sheet section
5. ✅ Group Information (name, members, formation date, etc.)
6. ✅ Group Accounts table showing 11 accounts

### 4.3 Switch Between Groups

- Select **Imani Women Group** from dropdown
  - Verify members count shows 5
- Select **Jamii Traders Group**
  - Verify members count shows 6
- Select **Tumaini Youth Group**
  - Verify members count shows 4

---

## Step 5: Test Capital Transfer (Advance)

### 5.1 Navigate to Capital Transfers

In Filament sidebar, click on:
- **Group Management** → **Capital Transfers**

### 5.2 Create Capital Advance

1. Click **New Capital Transfer** button
2. Fill in the form:
   - **Group**: Select "Imani Women Group"
   - **Transfer Type**: Select "Capital Advance (Org → Group)"
   - **Amount**: Enter `500000`
   - **Transfer Date**: Select today's date
   - **Reference Number**: Leave empty (auto-generated)
   - **Purpose**: Enter "Initial loan fund for group"
3. Click **Create**

**Expected Result:**
- ✅ Success notification: "Capital Advanced Successfully - KES 500,000 has been advanced to Imani Women Group"
- ✅ Redirected to capital transfers list
- ✅ New transfer visible in table

### 5.3 Verify Capital Advance

**Option A: Via Tinker**
```bash
php artisan tinker
```
```php
// Check transfer record
$transfer = App\Models\OrganizationGroupCapitalTransfer::latest()->first();
$transfer->transfer_type; // Should be: "advance"
$transfer->amount; // Should be: 500000.00
$transfer->status; // Should be: "completed"

// Check transactions created (should be 4 total)
$transferId = $transfer->id;
App\Models\Transaction::where('metadata->transfer_id', $transferId)->count();
// Should return: 4 (2 org-level + 2 group-level)

// Check organization-level transactions
App\Models\Transaction::where('transaction_type', 'capital_advance')
    ->where('account_number', '1201')->first()->dr_cr;
// Should be: "dr" (debit to Capital Advances)

App\Models\Transaction::where('transaction_type', 'capital_advance')
    ->where('account_number', '1001')->first()->dr_cr;
// Should be: "cr" (credit to Org Bank)

// Check group-level transactions
App\Models\Transaction::where('transaction_type', 'capital_received')
    ->where('group_id', 1)
    ->where('account_number', 'G1-1001')->first()->dr_cr;
// Should be: "dr" (debit to Group Bank)

App\Models\Transaction::where('transaction_type', 'capital_received')
    ->where('group_id', 1)
    ->where('account_number', 'G1-2301')->first()->dr_cr;
// Should be: "cr" (credit to Group Capital Payable)

// Check group bank balance
$groupBankAccount = App\Models\GroupAccount::where('group_id', 1)
    ->where('account_type', 'group_bank')->first();
$groupBankAccount->balance;
// Should return: 500000.00

exit
```

**Option B: Via Group Dashboard**
1. Go to **Group Management** → **Group Dashboard**
2. Select "Imani Women Group"
3. Verify:
   - ✅ Total Assets: KES 500,000.00
   - ✅ Total Liabilities: KES 500,000.00 (Capital Payable)
   - ✅ Capital Advanced: KES 500,000.00
   - ✅ Net Capital Outstanding: KES 500,000.00
   - ✅ In the accounts table, **Imani Women Group - Bank Account** should show balance of KES 500,000.00
   - ✅ **Imani Women Group - Capital Payable to Organization** should show balance of KES 500,000.00

---

## Step 6: Test Multiple Capital Advances

### 6.1 Advance Capital to Second Group

1. Go to **Capital Transfers** → **New Capital Transfer**
2. Fill in:
   - **Group**: "Jamii Traders Group"
   - **Transfer Type**: "Capital Advance"
   - **Amount**: `750000`
   - **Purpose**: "Loan fund allocation"
3. Click **Create**

### 6.2 Advance More Capital to First Group

1. Create another transfer:
   - **Group**: "Imani Women Group"
   - **Transfer Type**: "Capital Advance"
   - **Amount**: `200000`
   - **Purpose**: "Additional working capital"
2. Click **Create**

### 6.3 Verify Total Balances

**Via Tinker:**
```bash
php artisan tinker
```
```php
// Imani Group should have 700,000 (500k + 200k)
$imani = App\Models\Group::find(1);
$imani->bank_balance; // Should be: 700000.00
$imani->total_capital_advanced; // Should be: 700000.00

// Jamii Group should have 750,000
$jamii = App\Models\Group::find(2);
$jamii->bank_balance; // Should be: 750000.00
$jamii->total_capital_advanced; // Should be: 750000.00

exit
```

---

## Step 7: Test Capital Return

### 7.1 Return Capital from Group

1. Go to **Capital Transfers** → **New Capital Transfer**
2. Fill in:
   - **Group**: "Imani Women Group"
   - **Transfer Type**: "Capital Return (Group → Org)"
   - **Amount**: `100000`
   - **Notes**: "Returning excess capital"
3. Click **Create**

**Expected Result:**
- ✅ Success notification: "Capital Returned Successfully - KES 100,000 has been returned from Imani Women Group"

### 7.2 Verify Capital Return

**Via Tinker:**
```bash
php artisan tinker
```
```php
$imani = App\Models\Group::find(1);
$imani->bank_balance; 
// Should be: 600000.00 (700k - 100k returned)

$imani->total_capital_advanced; // Should be: 700000.00
$imani->total_capital_returned; // Should be: 100000.00
$imani->net_capital_outstanding; // Should be: 600000.00

exit
```

**Via Group Dashboard:**
1. Go to Group Dashboard
2. Select "Imani Women Group"
3. Verify:
   - Total Assets: KES 600,000.00
   - Capital Advanced: KES 700,000.00
   - Capital Returned: KES 100,000.00
   - Net Capital Outstanding: KES 600,000.00

---

## Step 8: Test Insufficient Funds Scenario

### 8.1 Attempt to Return More Than Available

1. Go to **Capital Transfers** → **New Capital Transfer**
2. Fill in:
   - **Group**: "Tumaini Youth Group" (has 0 balance)
   - **Transfer Type**: "Capital Return"
   - **Amount**: `50000`
3. Click **Create**

**Expected Result:**
- ✅ Error notification: "Transfer Failed - Insufficient funds in group account. Available: 0, Requested: 50000"
- ✅ No transfer created
- ✅ No transactions recorded

---

## Step 9: Test Double-Entry Integrity

### 9.1 Verify Organization-Level Balance

**Via Tinker:**
```bash
php artisan tinker
```
```php
use App\Services\GroupTransactionService;

// Check double-entry at organization level
$allTx = App\Models\Transaction::all();
$totalDebits = $allTx->where('dr_cr', 'dr')->sum('amount');
$totalCredits = $allTx->where('dr_cr', 'cr')->sum('amount');

echo "Total Debits: " . $totalDebits . "\n";
echo "Total Credits: " . $totalCredits . "\n";
echo "Difference: " . ($totalDebits - $totalCredits) . "\n";
// Difference should be 0.00 or very close (< 0.01)

// Check each group's double-entry
$service = app(GroupTransactionService::class);

foreach (App\Models\Group::all() as $group) {
    $validation = $service->validateGroupDoubleEntry($group);
    echo "\n{$group->name}:\n";
    echo "  Debits: " . $validation['total_debits'] . "\n";
    echo "  Credits: " . $validation['total_credits'] . "\n";
    echo "  Balanced: " . ($validation['is_balanced'] ? 'YES' : 'NO') . "\n";
}

exit
```

**Expected Output:**
```
Total Debits: 1450000.00
Total Credits: 1450000.00
Difference: 0.00

Imani Women Group:
  Debits: 700000.00
  Credits: 700000.00
  Balanced: YES

Jamii Traders Group:
  Debits: 750000.00
  Credits: 750000.00
  Balanced: YES

Tumaini Youth Group:
  Debits: 0.00
  Credits: 0.00
  Balanced: YES
```

---

## Step 10: Comprehensive Validation Checklist

Run through this checklist to ensure everything is working:

### Database
- [ ] ✅ 3 groups created
- [ ] ✅ 15 members created
- [ ] ✅ 33 group accounts created (11 per group)
- [ ] ✅ 18+ organization accounts in chart_of_accounts
- [ ] ✅ Capital transfer records exist

### Group Accounts
- [ ] ✅ Each group has 11 accounts automatically created
- [ ] ✅ Account codes follow pattern: G{group_id}-{code}
- [ ] ✅ Account names include group name
- [ ] ✅ All accounts initially have 0 balance
- [ ] ✅ Accounts are marked as active

### Capital Transfers
- [ ] ✅ Can advance capital from organization to group
- [ ] ✅ Can return capital from group to organization
- [ ] ✅ Cannot return more than available balance
- [ ] ✅ Transfers create 4 transactions (2 org-level + 2 group-level)
- [ ] ✅ Reference numbers auto-generated if not provided

### Transactions
- [ ] ✅ All transactions have group_id populated
- [ ] ✅ Organization transactions use account codes (1001, 1201, etc.)
- [ ] ✅ Group transactions use group account codes (G1-1001, etc.)
- [ ] ✅ Every debit has a matching credit
- [ ] ✅ Transaction metadata includes transfer_id

### UI/Dashboard
- [ ] ✅ Group Dashboard displays correctly
- [ ] ✅ Can switch between groups
- [ ] ✅ Financial summary cards show correct values
- [ ] ✅ Account balances calculate correctly
- [ ] ✅ Capital Transfer resource accessible
- [ ] ✅ Forms validate properly
- [ ] ✅ Success/error notifications display

### Balance Calculations
- [ ] ✅ Group bank balance reflects capital advances/returns
- [ ] ✅ Group capital payable reflects liability to org
- [ ] ✅ Total assets = Total liabilities (if no revenue/expenses yet)
- [ ] ✅ Net capital outstanding = Advanced - Returned
- [ ] ✅ Double-entry integrity maintained

---

## Troubleshooting

### Issue: GroupObserver not creating accounts

**Solution:**
```bash
# Make sure observer is registered
php artisan tinker
```
```php
// Manually create accounts for a group
$group = App\Models\Group::find(1);
app(App\Observers\GroupObserver::class)->created($group);
exit
```

### Issue: Filament pages not showing

**Solution:**
```bash
# Clear cache
php artisan filament:cache-components
php artisan optimize:clear
php artisan config:clear
```

### Issue: Transactions not creating

**Solution:**
Check error logs:
```bash
tail -f storage/logs/laravel.log
```

### Issue: Balance calculation incorrect

**Solution:**
```bash
php artisan tinker
```
```php
// Recalculate manually
$account = App\Models\GroupAccount::find(1);
$debits = App\Models\Transaction::where('account_number', $account->account_code)
    ->where('dr_cr', 'dr')->sum('amount');
$credits = App\Models\Transaction::where('account_number', $account->account_code)
    ->where('dr_cr', 'cr')->sum('amount');
echo "Debits: $debits, Credits: $credits\n";
exit
```

---

## Next Steps

After successful testing of Phase 1 & 2:

1. **Phase 3**: Implement loan flows through group accounts
2. **Phase 4**: Implement savings flows through group accounts
3. **Phase 5**: Enhanced reporting and analytics
4. **Phase 6**: Data migration and production deployment

---

## Test Data Summary

| Group | Members | Formation Date | Initial Capital |
|-------|---------|----------------|-----------------|
| Imani Women Group | 5 | 6 months ago | 0 (to be allocated) |
| Jamii Traders Group | 6 | 12 months ago | 0 (to be allocated) |
| Tumaini Youth Group | 4 | 3 months ago | 0 (to be allocated) |

---

## Success Criteria

✅ **Phase 1 & 2 Complete When:**
- All database migrations run successfully
- Test data seeds without errors
- Group accounts auto-create on group creation
- Capital can be advanced from org to groups
- Capital can be returned from groups to org
- Double-entry bookkeeping integrity maintained
- Group Dashboard displays financial data correctly
- All 10 validation checklist items pass

---

**Testing completed by:** ________________  
**Date:** ________________  
**All tests passed:** [ ] YES  [ ] NO  
**Issues found:** ________________

