# SACCO System - Architecture Visual Guide

**Visual reference for understanding the system architecture**

---

## 1. System Overview Diagram

```
┌─────────────────────────────────────────────────────────────────────────┐
│                        SACCO MANAGEMENT SYSTEM                          │
│                                                                         │
│  Frontend Layer (Filament Admin Panel)                                 │
│  ┌─────────────────────────────────────────────────────────────────┐  │
│  │  Product Mgmt  │  Savings  │  Loans  │  Reports  │  Members    │  │
│  └─────────────────────────────────────────────────────────────────┘  │
│                                 │                                       │
│  Service Layer                  ▼                                       │
│  ┌─────────────────────────────────────────────────────────────────┐  │
│  │  SavingsService  │  SubscriptionService  │  LoanEligibility     │  │
│  │  GuarantorSvc    │  FeeCalculation       │  TransactionService  │  │
│  └─────────────────────────────────────────────────────────────────┘  │
│                                 │                                       │
│  Model Layer                    ▼                                       │
│  ┌─────────────────────────────────────────────────────────────────┐  │
│  │  Member  │  SaccoProduct  │  Loan  │  Transaction  │  Group     │  │
│  └─────────────────────────────────────────────────────────────────┘  │
│                                 │                                       │
│  Database Layer                 ▼                                       │
│  ┌─────────────────────────────────────────────────────────────────┐  │
│  │  MySQL/PostgreSQL with Double-Entry Transaction Tracking         │  │
│  └─────────────────────────────────────────────────────────────────┘  │
│                                                                         │
└─────────────────────────────────────────────────────────────────────────┘
```

---

## 2. Database Entity Relationship Diagram

### Core Entities

```
┌─────────────┐         ┌──────────────────┐         ┌─────────────┐
│   groups    │◄────────│     members      │◄────────│    users    │
└─────────────┘ 1    ∞  └──────────────────┘  1   0,1└─────────────┘
                              │ 1                  
                              │                    
                              │ ∞                  
                    ┌─────────┴──────────┬─────────────────┐
                    │                    │                 │
                    ▼ ∞                  ▼ ∞               ▼ ∞
        ┌──────────────────────┐  ┌────────────────┐  ┌──────────┐
        │ member_savings_      │  │ member_product │  │  loans   │
        │   accounts           │  │ _subscriptions │  └──────────┘
        └──────────────────────┘  └────────────────┘       │ 1
                 │ ∞                      │ ∞               │
                 │                        │                 │ ∞
                 ▼                        ▼                 ▼
        ┌──────────────────┐    ┌──────────────┐    ┌─────────────┐
        │  transactions    │◄───│transactions  │    │    loan_    │
        │ (savings_acct_id)│    │(product_     │    │  guarantors │
        └──────────────────┘    │subscription) │    └─────────────┘
                                └──────────────┘
```

### Product Configuration

```
┌──────────────────────┐
│ sacco_product_types  │
│ ────────────────     │
│ • member-savings     │
│ • subscription       │
│ • one-time-fee       │
└──────────┬───────────┘
           │ 1
           │
           │ ∞
┌──────────▼───────────────────────────────────────────────────┐
│              sacco_products                                   │
│ ────────────────────────────────────────────────────────     │
│ Examples: Main Savings, Risk Fund, Registration Fee          │
└──────┬──────────────────────────┬────────────────────────────┘
       │ 1                        │ 1
       │                          │
       │ ∞                        │ ∞
       ▼                          ▼
┌────────────────────────┐  ┌──────────────────────────────┐
│ sacco_product_         │  │ sacco_product_chart_of_      │
│ attribute_values       │  │        accounts              │
│ ──────────────────     │  │ ───────────────────────      │
│ Key-Value pairs        │  │ Maps to GL accounts          │
│ (amount, frequency)    │  │ (bank, income, liability)    │
└────────────────────────┘  └──────────────────────────────┘
```

### Loan Product Rules

```
┌─────────────────┐
│  loan_products  │
└────────┬────────┘
         │ 1
         │
         │ ∞
┌────────▼─────────────────────────────────────────────────────┐
│                  loan_product_rules                          │
│ ───────────────────────────────────────────────────────      │
│ Rule Types:                                                  │
│  • eligibility (who can apply)                               │
│  • amount_calculation (max loan amount)                      │
│  • guarantor_requirement (how many guarantors)               │
│  • penalty (what happens on default)                         │
│                                                              │
│ Stored as JSON configurations                                │
└──────────────────────────────────────────────────────────────┘

Example Rule JSON:
{
  "rule_type": "eligibility",
  "conditions": {
    "group_age_months": {"operator": "<=", "value": 3}
  },
  "error_message": "Only available in first 3 months"
}
```

---

## 3. Transaction Flow Diagrams

### A. Savings Deposit Flow

```
   Member
     │
     │ Clicks "Deposit"
     ▼
┌─────────────────────┐
│ SavingsDeposit Page │
│ (Filament)          │
└──────────┬──────────┘
           │ Calls
           ▼
┌─────────────────────┐
│  SavingsService     │
│  deposit()          │
└──────────┬──────────┘
           │ Creates
           ▼
┌─────────────────────────────────────┐
│     Double-Entry Transactions       │
│                                     │
│  Transaction 1:                     │
│    DR: Bank Account       +1000     │
│    Type: savings_deposit            │
│                                     │
│  Transaction 2:                     │
│    CR: Savings Account    +1000     │
│    Type: savings_deposit            │
└─────────────────────────────────────┘
           │
           ▼
   Balance Updated
   (calculated from transactions)
```

### B. Subscription Payment Flow

```
   Member
     │
     │ Enrolls in Risk Fund
     ▼
┌─────────────────────────────┐
│ MemberProductSubscription   │
│ Created                     │
│ ─────────────────────       │
│ total_expected: 360         │
│ total_paid: 0               │
│ status: active              │
└────────────┬────────────────┘
             │
             │ Monthly payment of 30
             ▼
┌─────────────────────────────┐
│  SubscriptionService        │
│  recordPayment()            │
└────────────┬────────────────┘
             │ Creates transactions
             ▼
┌─────────────────────────────────────┐
│     Double-Entry Transactions       │
│                                     │
│  Transaction 1:                     │
│    DR: Bank Account       +30       │
│    Type: subscription_payment       │
│                                     │
│  Transaction 2:                     │
│    CR: Risk Fund Income   +30       │
│    Type: subscription_payment       │
└─────────────────────────────────────┘
             │
             │ Updates subscription
             ▼
┌─────────────────────────────┐
│ MemberProductSubscription   │
│ Updated                     │
│ ─────────────────────       │
│ total_paid: 30              │
│ payment_count: 1            │
│ next_payment_date: +1 month │
└─────────────────────────────┘
             │
             │ After 12 payments
             ▼
┌─────────────────────────────┐
│ total_paid: 360             │
│ status: completed ✓         │
└─────────────────────────────┘
```

### C. Loan Application with Eligibility Check

```
   Member
     │
     │ Applies for Long Term Loan
     ▼
┌─────────────────────────────┐
│  Loan Application Form      │
│  ─────────────────────      │
│  • Product: Long Term       │
│  • Requested: 150,000       │
└────────────┬────────────────┘
             │
             │ Validate
             ▼
┌─────────────────────────────────────────────────────┐
│  LoanEligibilityService.checkEligibility()          │
│  ─────────────────────────────────────────────      │
│  1. Check group age > 3 months        ✓             │
│  2. Check member status = active      ✓             │
│  3. Calculate savings: 50,000                       │
│  4. Calculate max: 50,000 × 3 = 150,000             │
│  5. Round to 5000: 150,000            ✓             │
│  6. Check guarantors: need 2 (amount >= 5000)       │
└────────────┬────────────────────────────────────────┘
             │ All checks pass
             ▼
┌─────────────────────────────┐
│  Show Guarantor Form        │
│  ─────────────────────      │
│  Requires 2 guarantors      │
└────────────┬────────────────┘
             │
             │ Add guarantors
             ▼
┌─────────────────────────────────────┐
│  GuarantorService.addGuarantor()    │
│  ─────────────────────────────      │
│  Guarantor 1: Member A (25,000)     │
│  Guarantor 2: Member B (30,000)     │
└────────────┬────────────────────────┘
             │
             │ Validate sufficient
             ▼
┌─────────────────────────────┐
│  Application Approved       │
│  ─────────────────────      │
│  Loan created ✓             │
│  Awaiting disbursement      │
└─────────────────────────────┘
```

---

## 4. Data Flow Architecture

### Member Savings Balance Calculation

```
┌──────────────────────────────────────────────────────────┐
│                    TRANSACTIONS TABLE                     │
│ ────────────────────────────────────────────────────     │
│                                                          │
│  ID │ Type            │ Account         │ DR/CR │ Amt   │
│  ───┼─────────────────┼─────────────────┼───────┼─────  │
│  1  │ savings_deposit │ Savings Acct    │  CR   │ 1000  │ ◄─┐
│  2  │ savings_deposit │ Bank Acct       │  DR   │ 1000  │   │
│  3  │ savings_deposit │ Savings Acct    │  CR   │  500  │ ◄─┼─ DEPOSITS
│  4  │ savings_deposit │ Bank Acct       │  DR   │  500  │   │
│  5  │ savings_withdrawal│ Savings Acct  │  DR   │  300  │ ◄─┘
│  6  │ savings_withdrawal│ Bank Acct     │  CR   │  300  │ ◄─ WITHDRAWALS
│                                                          │
└──────────────────────────────────────────────────────────┘
                           │
                           │ Query & Calculate
                           ▼
┌──────────────────────────────────────────────────────────┐
│         BalanceCalculationService.calculateBalance()     │
│  ──────────────────────────────────────────────────      │
│                                                          │
│  WHERE account_name = 'Savings Account'                  │
│  AND member_id = X                                       │
│                                                          │
│  Credits (deposits):  1000 + 500 = 1500                  │
│  Debits (withdrawals): 300                               │
│  Balance = 1500 - 300 = 1200 ✓                           │
│                                                          │
└──────────────────────────────────────────────────────────┘
```

**Key Insight:** No balance is stored. Always calculated from transactions.

### Loan Eligibility Decision Tree

```
                        Member Applies for Loan
                                 │
                                 ▼
                    ┌────────────────────────────┐
                    │ Which Loan Product?        │
                    └────────┬───────────────────┘
                             │
         ┌───────────────────┼───────────────────┐
         │                   │                   │
         ▼                   ▼                   ▼
  ┌────────────┐      ┌────────────┐     ┌────────────┐
  │  Starter   │      │  Advance   │     │ Long Term  │
  │   Loan     │      │   Loan     │     │    Loan    │
  └─────┬──────┘      └─────┬──────┘     └─────┬──────┘
        │                   │                   │
        ▼                   ▼                   ▼
   ┌─────────────┐    ┌─────────────┐    ┌─────────────┐
   │ Eligibility │    │ Eligibility │    │ Eligibility │
   │ Rules Check │    │ Rules Check │    │ Rules Check │
   └──────┬──────┘    └──────┬──────┘    └──────┬──────┘
          │                  │                   │
          ▼                  ▼                   ▼
   Group Age       Group Age           Group Age
   <= 3 months?    > 3 months?        > 3 months?
          │                  │                   │
      YES │ NO           YES │ NO            YES │ NO
          ▼                  ▼                   ▼
      ┌────────┐        ┌────────┐         ┌────────┐
      │Continue│        │Continue│         │Continue│
      └───┬────┘        └───┬────┘         └───┬────┘
          │                  │                   │
          ▼                  ▼                   ▼
   ┌──────────────┐   ┌──────────────┐   ┌──────────────┐
   │ Calculate    │   │ Calculate    │   │ Calculate    │
   │ Max Amount   │   │ Max Amount   │   │ Max Amount   │
   │              │   │              │   │              │
   │ 2x savings   │   │ 2x savings   │   │ 3x savings   │
   │ (last 2 mo)  │   │ (all time)   │   │ (all time)   │
   │              │   │              │   │ rounded to   │
   │              │   │              │   │ nearest 5000 │
   └──────┬───────┘   └──────┬───────┘   └──────┬───────┘
          │                  │                   │
          ▼                  ▼                   ▼
   ┌──────────────┐   ┌──────────────┐   ┌──────────────┐
   │ Guarantors?  │   │ Guarantors?  │   │ Guarantors?  │
   │ None required│   │ None required│   │ 1 if < 5000  │
   │              │   │              │   │ 2 if >= 5000 │
   └──────┬───────┘   └──────┬───────┘   └──────┬───────┘
          │                  │                   │
          ▼                  ▼                   ▼
      APPROVED           APPROVED            APPROVED
   (if amount OK)     (if amount OK)    (if guarantors OK)
```

---

## 5. Service Layer Interaction Diagram

```
┌────────────────────────────────────────────────────────────────┐
│                    CONTROLLER / FILAMENT PAGE                   │
└────────────────┬───────────────────────────────────────────────┘
                 │
                 │ Delegates Business Logic
                 │
    ┌────────────┼────────────┬──────────────┬─────────────┐
    │            │            │              │             │
    ▼            ▼            ▼              ▼             ▼
┌──────────┐ ┌─────────┐ ┌────────────┐ ┌──────────┐ ┌──────────┐
│ Savings  │ │Subscript│ │   Loan     │ │Guarantor │ │   Fee    │
│ Service  │ │  -ion   │ │Eligibility │ │ Service  │ │Calculat- │
│          │ │ Service │ │  Service   │ │          │ │ion Svc   │
└────┬─────┘ └────┬────┘ └─────┬──────┘ └────┬─────┘ └────┬─────┘
     │            │            │              │            │
     └────────────┴────────────┴──────────────┴────────────┘
                              │
                              │ All Use
                              ▼
              ┌───────────────────────────────┐
              │    TransactionService         │
              │    ─────────────────────      │
              │    createDoubleEntry()        │
              │    reverseTransaction()       │
              └───────────────┬───────────────┘
                              │
                              │ Creates
                              ▼
              ┌───────────────────────────────┐
              │      TRANSACTIONS TABLE       │
              │      ─────────────────────    │
              │      All financial records    │
              └───────────────────────────────┘
```

**Principle:** Services don't directly create transactions. They use `TransactionService` to ensure consistency.

---

## 6. Chart of Accounts Integration

### Product-to-GL Account Mapping

```
┌─────────────────────────────────────────────────────────────────┐
│                      SACCO PRODUCT                               │
│                    "Member Savings"                              │
└───────────────────────────┬─────────────────────────────────────┘
                            │
                            │ Has mappings
                            ▼
┌─────────────────────────────────────────────────────────────────┐
│           sacco_product_chart_of_accounts                        │
│  ──────────────────────────────────────────────────────────     │
│                                                                  │
│  Account Type           │  Account Number  │  Account Name      │
│  ──────────────────────────────────────────────────────────     │
│  bank                   │  1001            │  Bank Account      │
│  savings_account        │  2201            │  Savings Liability │
│  savings_interest_exp   │  5101            │  Interest Expense  │
│                                                                  │
└─────────────────────────────────────────────────────────────────┘
                            │
                            │ Used in transactions
                            ▼
┌─────────────────────────────────────────────────────────────────┐
│                      TRANSACTION                                 │
│  ──────────────────────────────────────────────────────────     │
│  account_name:    "Bank Account"                                │
│  account_number:  "1001"                                        │
│  transaction_type: "savings_deposit"                            │
│  dr_cr:           "dr"                                          │
│  amount:          1000.00                                       │
└─────────────────────────────────────────────────────────────────┘
```

### Trial Balance Generation

```
Query all transactions GROUP BY account_number:

Account Number │ Account Name      │ Debits  │ Credits │ Balance
───────────────┼───────────────────┼─────────┼─────────┼─────────
1001           │ Bank Account      │ 150,000 │  50,000 │ 100,000 (DR)
1201           │ Loans Receivable  │ 100,000 │  20,000 │  80,000 (DR)
2201           │ Savings Liability │  10,000 │ 150,000 │ 140,000 (CR)
4101           │ Interest Income   │       0 │   5,000 │   5,000 (CR)
4201           │ Risk Fund Income  │       0 │   1,800 │   1,800 (CR)

Total Debits:  260,000
Total Credits: 226,800

✓ Must balance (within rounding tolerance)
```

---

## 7. Security & Audit Architecture

```
┌──────────────────────────────────────────────────────────────┐
│                      USER REQUEST                             │
│              (via Filament Interface)                         │
└────────────────────────┬─────────────────────────────────────┘
                         │
                         │ 1. Authentication
                         ▼
                ┌────────────────────┐
                │  Laravel Auth      │
                │  (Sanctum/Session) │
                └─────────┬──────────┘
                          │ 2. Authorization
                          ▼
                ┌────────────────────┐
                │  Laravel Policies  │
                │  • CanDeposit?     │
                │  • CanApprove?     │
                └─────────┬──────────┘
                          │ 3. Validation
                          ▼
                ┌────────────────────┐
                │  Form Validation   │
                │  • Amount > 0      │
                │  • Account exists  │
                └─────────┬──────────┘
                          │ 4. Business Logic
                          ▼
                ┌────────────────────┐
                │  Service Layer     │
                │  (in DB transaction│
                └─────────┬──────────┘
                          │ 5. Record
                          ▼
                ┌────────────────────┐
                │  Create Transaction│
                │  + Audit Log       │
                └─────────┬──────────┘
                          │
                          ▼
                ┌─────────────────────────────────────┐
                │  AUDIT LOG                          │
                │  ─────────────────────────────      │
                │  • Who: user_id                     │
                │  • What: transaction_created        │
                │  • When: timestamp                  │
                │  • Details: JSON metadata           │
                └─────────────────────────────────────┘
```

---

## 8. Deployment Architecture

### Development Environment

```
┌─────────────────────────────────────────────────┐
│  LOCAL DEVELOPMENT                              │
│  ─────────────────────────────────────────      │
│                                                 │
│  ┌──────────────┐    ┌──────────────┐          │
│  │  Laravel     │◄──►│  MySQL       │          │
│  │  (php artisan│    │  (local DB)  │          │
│  │   serve)     │    └──────────────┘          │
│  └──────────────┘                              │
│                                                 │
│  Test Data:                                     │
│  • php artisan migrate:fresh --seed             │
│  • Test members, groups, products               │
│                                                 │
└─────────────────────────────────────────────────┘
```

### Production Environment

```
┌───────────────────────────────────────────────────────────────┐
│  PRODUCTION                                                    │
│  ─────────────────────────────────────────────────────────    │
│                                                               │
│  ┌──────────────┐    ┌──────────────┐    ┌──────────────┐   │
│  │   Nginx      │◄──►│  Laravel     │◄──►│  MySQL       │   │
│  │  (Web Server)│    │  (PHP-FPM)   │    │  (Production)│   │
│  └──────────────┘    └──────────────┘    └──────────────┘   │
│                            │                     │            │
│                            ▼                     ▼            │
│                     ┌──────────────┐    ┌──────────────┐     │
│                     │  Redis       │    │  Backup      │     │
│                     │  (Cache/Queue│    │  (Daily)     │     │
│                     └──────────────┘    └──────────────┘     │
│                                                               │
│  Features:                                                    │
│  • HTTPS (SSL Certificate)                                    │
│  • Daily backups                                              │
│  • Queue workers for background jobs                          │
│  • Monitoring & alerting                                      │
│                                                               │
└───────────────────────────────────────────────────────────────┘
```

---

## 9. Testing Pyramid

```
                        ┌──────────────────┐
                        │                  │
                        │   E2E Tests      │  ← Few, Slow
                        │   (Browser)      │    Full workflows
                        │                  │
                        └────────┬─────────┘
                                 │
                    ┌────────────▼──────────────┐
                    │                           │
                    │   Integration Tests       │  ← Some, Medium
                    │   (Service → DB)          │    Service + DB
                    │                           │
                    └────────────┬──────────────┘
                                 │
              ┌──────────────────▼──────────────────────┐
              │                                         │
              │         Unit Tests                      │  ← Many, Fast
              │   (Service methods, Models)             │    Pure logic
              │                                         │
              └─────────────────────────────────────────┘

Priority Order:
1. Unit tests (fastest feedback)
2. Integration tests (catch DB issues)
3. E2E tests (catch UI issues)
```

---

## 10. Performance Optimization Strategy

### Query Optimization

```
┌─────────────────────────────────────────────────────────────┐
│  BEFORE (N+1 Query Problem)                                 │
│  ─────────────────────────────────────────────────────      │
│                                                             │
│  $members = Member::all(); // 1 query                       │
│  foreach ($members as $member) {                            │
│    echo $member->savingsAccounts; // N queries              │
│  }                                                          │
│                                                             │
│  Total: 1 + N queries                                       │
└─────────────────────────────────────────────────────────────┘

┌─────────────────────────────────────────────────────────────┐
│  AFTER (Eager Loading)                                      │
│  ─────────────────────────────────────────────────────      │
│                                                             │
│  $members = Member::with('savingsAccounts')->get();         │
│  foreach ($members as $member) {                            │
│    echo $member->savingsAccounts; // No extra queries       │
│  }                                                          │
│                                                             │
│  Total: 2 queries                                           │
└─────────────────────────────────────────────────────────────┘
```

### Caching Strategy

```
┌────────────────────────────────────────────────────────────┐
│  Cache Layers                                              │
│  ────────────────────────────────────────────────────      │
│                                                            │
│  1. Application Cache (Redis)                              │
│     • Product configurations (rarely change)               │
│     • Chart of accounts mapping                            │
│     • Loan product rules                                   │
│     TTL: 1 hour                                            │
│                                                            │
│  2. Query Result Cache                                     │
│     • Member total savings (frequently read)               │
│     • Outstanding loan balances                            │
│     TTL: 5 minutes                                         │
│                                                            │
│  3. Session Cache                                          │
│     • User permissions                                     │
│     • Current member context                               │
│     TTL: Session lifetime                                  │
│                                                            │
│  Cache Invalidation:                                       │
│     • On transaction creation → invalidate balances        │
│     • On product update → invalidate product cache         │
│                                                            │
└────────────────────────────────────────────────────────────┘
```

---

## Summary

This visual guide provides:

✅ **System Architecture** - How components interact  
✅ **Database Structure** - Entity relationships  
✅ **Transaction Flows** - How money moves through the system  
✅ **Service Interactions** - How business logic is organized  
✅ **Security Architecture** - How requests are authenticated & authorized  
✅ **Testing Strategy** - Pyramid approach  
✅ **Performance Optimization** - Caching and query optimization  

**Use this guide alongside:**
- Technical Report (detailed specifications)
- Executive Summary (business overview)
- Quick Start Guide (implementation steps)

---

**Last Updated:** October 19, 2025  
**Version:** 1.0

