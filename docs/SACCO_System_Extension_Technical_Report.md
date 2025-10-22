# TrustFund SACCO System Extension - Technical Report

**Report Date:** October 19, 2025  
**Prepared By:** System Architect  
**Purpose:** Detailed technical blueprint for extending the TrustFund Loan Management System into a full-featured SACCO system

---

## Table of Contents

1. [Executive Summary](#executive-summary)
2. [Current System Analysis](#current-system-analysis)
3. [Proposed System Architecture](#proposed-system-architecture)
4. [Database Schema Extensions](#database-schema-extensions)
5. [Member Savings Module](#member-savings-module)
6. [Additional Products System](#additional-products-system)
7. [Dynamic Loan Products with Flexible Rules](#dynamic-loan-products-with-flexible-rules)
8. [Transaction System Integration](#transaction-system-integration)
9. [Models and Relationships](#models-and-relationships)
10. [Service Layer Architecture](#service-layer-architecture)
11. [Backward Compatibility Strategy](#backward-compatibility-strategy)
12. [Implementation Roadmap](#implementation-roadmap)
13. [Testing Strategy](#testing-strategy)
14. [Security and Audit Considerations](#security-and-audit-considerations)

---

## 1. Executive Summary

The current TrustFund Loan Management System provides a solid foundation with:
- ✅ Double-entry accounting system
- ✅ Dynamic loan product configuration via attributes
- ✅ Chart of accounts integration per loan product
- ✅ Flexible transaction tracking
- ✅ Interest accrual system with multiple cycles
- ✅ Configurable repayment priority allocation

This report outlines a **comprehensive, modular approach** to extend the system into a full SACCO system that supports:
- **Member Savings** with cumulative tracking
- **Configurable Products** (Risk Fund, Registration Fee, Welfare, etc.)
- **Dynamic Loan Products** with complex business rules
- **Guarantor Management** with automated validation
- **Flexible Transaction Recording** for all financial activities

**Key Design Principles:**
1. **Reuse existing patterns** (attributes, chart of accounts, transactions)
2. **Maintain backward compatibility** (no breaking changes to loans)
3. **Modular architecture** (new features don't impact existing ones)
4. **Configuration over code** (business rules in database, not hardcoded)
5. **Audit trail for everything** (comprehensive transaction logging)

---

## 2. Current System Analysis

### 2.1 Core Architecture Overview

The current system is built on Laravel with Filament admin panel and follows these patterns:

#### 2.1.1 Dynamic Attribute System
```
loan_products (e.g., "Emergency Loan")
  ↓ 
loan_product_attributes (pivot with values)
  ↓
loan_attributes (definitions: interest_rate, loan_charges, etc.)
```

**Key Insight:** This pattern allows loan products to have different configurations without schema changes. **We can replicate this for SACCO products.**

#### 2.1.2 Chart of Accounts Mapping
```
loan_product_chart_of_accounts
  ↓ maps account_type (e.g., 'bank', 'loans_receivable')
  ↓ to specific account_number in chart_of_accounts
```

**Key Insight:** Each product can use different GL accounts. **This same pattern works for savings and contribution products.**

#### 2.1.3 Transaction Structure
```php
transactions {
    account_name: string
    account_number: string (FK to chart_of_accounts)
    loan_id: FK (nullable)
    member_id: FK
    repayment_id: FK (nullable)
    transaction_type: string ('loan_issue', 'interest_accrual', etc.)
    dr_cr: enum('dr', 'cr')
    amount: decimal
    transaction_date: date
    description: text
}
```

**Key Insight:** The transaction model is already generic and can handle any transaction type. **We just need to add new transaction_type values.**

#### 2.1.4 Outstanding Balance Calculation
All outstanding calculations are based on **transaction analysis**, not stored balances:
- Sum debits and credits on specific accounts
- Difference = outstanding balance

**Key Insight:** This approach is audit-friendly and self-correcting. **We'll use the same pattern for savings balances.**

### 2.2 Current Limitations for SACCO Operations

1. **No Savings Tracking:** System only tracks loans, not member contributions/savings
2. **No Product Subscriptions:** No way to define recurring contribution products
3. **Loan Eligibility Logic:** Hardcoded or manual; no automatic validation based on savings
4. **No Guarantor System:** Missing database tables and validation logic
5. **Limited Business Rules:** Loan product attributes are simple key-value pairs; complex rules (like "only in first 3 months") not supported

---

## 3. Proposed System Architecture

### 3.1 High-Level Architecture

```
┌─────────────────────────────────────────────────────────────────┐
│                        SACCO MANAGEMENT SYSTEM                   │
├─────────────────────────────────────────────────────────────────┤
│                                                                   │
│  ┌──────────────┐  ┌──────────────┐  ┌──────────────┐          │
│  │   MEMBERS    │  │    GROUPS    │  │    USERS     │          │
│  └──────────────┘  └──────────────┘  └──────────────┘          │
│         │                 │                   │                  │
│  ┌──────┴─────────────────┴───────────────────┴──────┐          │
│  │              PRODUCT MANAGEMENT LAYER              │          │
│  ├────────────────────────────────────────────────────┤          │
│  │  ┌────────────┐  ┌─────────────┐  ┌────────────┐  │          │
│  │  │   LOAN     │  │   SAVINGS   │  │ ADDITIONAL │  │          │
│  │  │  PRODUCTS  │  │   PRODUCTS  │  │  PRODUCTS  │  │          │
│  │  └────────────┘  └─────────────┘  └────────────┘  │          │
│  └────────────────────────────────────────────────────┘          │
│         │                 │                   │                  │
│  ┌──────┴─────────────────┴───────────────────┴──────┐          │
│  │           TRANSACTION & ACCOUNTING LAYER           │          │
│  ├────────────────────────────────────────────────────┤          │
│  │  • Double-Entry Transactions                       │          │
│  │  • Chart of Accounts Mapping                       │          │
│  │  • Balance Calculation Services                    │          │
│  │  • Transaction Type Registry                       │          │
│  └────────────────────────────────────────────────────┘          │
│         │                                                         │
│  ┌──────┴─────────────────────────────────────────────┐          │
│  │           BUSINESS RULES ENGINE                     │          │
│  ├────────────────────────────────────────────────────┤          │
│  │  • Loan Eligibility Validator                      │          │
│  │  • Savings Calculator                              │          │
│  │  • Product Subscription Manager                    │          │
│  │  • Guarantor Validator                             │          │
│  └────────────────────────────────────────────────────┘          │
│                                                                   │
└─────────────────────────────────────────────────────────────────┘
```

### 3.2 Design Patterns to Leverage

#### Pattern 1: **Product Abstraction**
All financial products (loans, savings, subscriptions) share common characteristics:
- Have a name, description, active status
- Have configurable attributes (interest rate, charges, duration, etc.)
- Map to chart of accounts for different transaction types
- Generate transactions when used

**Approach:** Create a generic `Product` system with product types, then use polymorphic relationships.

#### Pattern 2: **Attribute-Value Storage**
Replicate the `loan_product_attributes` pattern for all product types:
- Define attributes once (e.g., "monthly_amount", "max_cycles")
- Assign values per product instance
- Read dynamically at runtime

**Benefit:** No schema changes when adding new product configurations.

#### Pattern 3: **Transaction-Based State**
Never store calculated balances. Always derive from transactions:
```php
// Savings balance
$deposits = transactions where type='savings_deposit' and dr_cr='dr' on savings_account
$withdrawals = transactions where type='savings_withdrawal' and dr_cr='cr' on savings_account
$balance = $deposits - $withdrawals
```

**Benefit:** Audit trail, error correction via reversal transactions, no data corruption.

---

## 4. Database Schema Extensions

### 4.1 New Tables Overview

| Table | Purpose | Priority |
|-------|---------|----------|
| `sacco_products` | Unified product catalog | High |
| `sacco_product_types` | Product type definitions | High |
| `sacco_product_attributes` | Product attribute definitions | High |
| `sacco_product_attribute_values` | Product-specific attribute values | High |
| `sacco_product_chart_of_accounts` | Account mappings per product | High |
| `member_savings_accounts` | Individual member savings tracking | High |
| `member_product_subscriptions` | Member subscription to products | High |
| `member_savings_transactions` | Legacy table (optional, use transactions) | Low |
| `loan_guarantors` | Guarantor relationships | High |
| `loan_product_rules` | Complex business rules for loans | Medium |
| `product_transaction_types` | Transaction type registry per product | Medium |

### 4.2 Detailed Schema Definitions

#### 4.2.1 SACCO Products Table
```sql
CREATE TABLE sacco_products (
    id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    product_type_id BIGINT UNSIGNED NOT NULL,
    name VARCHAR(255) NOT NULL,
    code VARCHAR(50) UNIQUE NOT NULL,
    description TEXT,
    is_active BOOLEAN DEFAULT TRUE,
    is_mandatory BOOLEAN DEFAULT FALSE,
    start_date DATE,
    end_date DATE,
    created_at TIMESTAMP,
    updated_at TIMESTAMP,
    
    FOREIGN KEY (product_type_id) REFERENCES sacco_product_types(id) ON DELETE RESTRICT,
    INDEX idx_active (is_active),
    INDEX idx_type (product_type_id)
);
```

**Explanation:**
- `product_type_id`: Links to product type (savings, subscription, etc.)
- `code`: Unique identifier for API/integrations (e.g., 'RISK_FUND', 'WELFARE')
- `is_mandatory`: If true, all members must subscribe
- `start_date/end_date`: Product availability window

#### 4.2.2 SACCO Product Types Table
```sql
CREATE TABLE sacco_product_types (
    id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    name VARCHAR(100) NOT NULL UNIQUE,
    slug VARCHAR(100) NOT NULL UNIQUE,
    description TEXT,
    category ENUM('savings', 'subscription', 'fee', 'fine', 'loan') NOT NULL,
    created_at TIMESTAMP,
    updated_at TIMESTAMP,
    
    INDEX idx_category (category)
);
```

**Initial Data:**
```sql
INSERT INTO sacco_product_types (name, slug, category) VALUES
('Member Savings', 'member-savings', 'savings'),
('Subscription Product', 'subscription-product', 'subscription'),
('One-Time Fee', 'one-time-fee', 'fee'),
('Penalty/Fine', 'penalty-fine', 'fine'),
('Loan Product', 'loan-product', 'loan');
```

#### 4.2.3 SACCO Product Attributes Table
```sql
CREATE TABLE sacco_product_attributes (
    id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    name VARCHAR(255) NOT NULL,
    slug VARCHAR(255) NOT NULL UNIQUE,
    type VARCHAR(50) NOT NULL, -- 'string', 'integer', 'decimal', 'boolean', 'date', 'select', 'json'
    options TEXT, -- JSON for select options or validation rules
    description TEXT,
    applicable_product_types JSON, -- Which product types can use this attribute
    is_required BOOLEAN DEFAULT FALSE,
    default_value TEXT,
    created_at TIMESTAMP,
    updated_at TIMESTAMP,
    
    INDEX idx_slug (slug)
);
```

**Sample Attributes:**
```sql
INSERT INTO sacco_product_attributes (name, slug, type, applicable_product_types) VALUES
('Payment Frequency', 'payment_frequency', 'select', '["subscription-product"]'),
('Amount Per Cycle', 'amount_per_cycle', 'decimal', '["subscription-product"]'),
('Total Cycles', 'total_cycles', 'integer', '["subscription-product"]'),
('Max Total Amount', 'max_total_amount', 'decimal', '["subscription-product"]'),
('Payment Type', 'payment_type', 'select', '["subscription-product", "one-time-fee"]'),
('Minimum Deposit', 'minimum_deposit', 'decimal', '["member-savings"]'),
('Maximum Deposit', 'maximum_deposit', 'decimal', '["member-savings"]'),
('Allows Withdrawal', 'allows_withdrawal', 'boolean', '["member-savings"]'),
('Interest Rate', 'savings_interest_rate', 'decimal', '["member-savings"]'),
('Calculation Formula', 'calculation_formula', 'string', '["one-time-fee"]');
```

#### 4.2.4 SACCO Product Attribute Values Table
```sql
CREATE TABLE sacco_product_attribute_values (
    id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    sacco_product_id BIGINT UNSIGNED NOT NULL,
    attribute_id BIGINT UNSIGNED NOT NULL,
    value TEXT,
    created_at TIMESTAMP,
    updated_at TIMESTAMP,
    
    FOREIGN KEY (sacco_product_id) REFERENCES sacco_products(id) ON DELETE CASCADE,
    FOREIGN KEY (attribute_id) REFERENCES sacco_product_attributes(id) ON DELETE CASCADE,
    UNIQUE KEY unique_product_attribute (sacco_product_id, attribute_id)
);
```

#### 4.2.5 SACCO Product Chart of Accounts Table
```sql
CREATE TABLE sacco_product_chart_of_accounts (
    id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    sacco_product_id BIGINT UNSIGNED NOT NULL,
    account_type VARCHAR(100) NOT NULL, -- 'receivable', 'income', 'liability', 'expense'
    account_number VARCHAR(50) NOT NULL,
    created_at TIMESTAMP,
    updated_at TIMESTAMP,
    
    FOREIGN KEY (sacco_product_id) REFERENCES sacco_products(id) ON DELETE CASCADE,
    FOREIGN KEY (account_number) REFERENCES chart_of_accounts(account_code) ON DELETE CASCADE,
    UNIQUE KEY unique_product_account_type (sacco_product_id, account_type)
);
```

**Account Types for Different Products:**
- **Savings:** `savings_account`, `savings_interest_payable`, `savings_withdrawal_account`
- **Subscription Products:** `contribution_receivable`, `contribution_income`
- **Fees:** `fee_receivable`, `fee_income`
- **Fines:** `fine_receivable`, `fine_income`

#### 4.2.6 Member Savings Accounts Table
```sql
CREATE TABLE member_savings_accounts (
    id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    member_id BIGINT UNSIGNED NOT NULL,
    sacco_product_id BIGINT UNSIGNED NOT NULL,
    account_number VARCHAR(50) UNIQUE NOT NULL,
    opening_date DATE NOT NULL,
    status ENUM('active', 'dormant', 'closed') DEFAULT 'active',
    closed_date DATE,
    notes TEXT,
    created_at TIMESTAMP,
    updated_at TIMESTAMP,
    
    FOREIGN KEY (member_id) REFERENCES members(id) ON DELETE CASCADE,
    FOREIGN KEY (sacco_product_id) REFERENCES sacco_products(id) ON DELETE RESTRICT,
    UNIQUE KEY unique_member_product (member_id, sacco_product_id),
    INDEX idx_member (member_id),
    INDEX idx_status (status)
);
```

**Explanation:**
- Each member can have one savings account per savings product
- `account_number`: Auto-generated (e.g., SAV-ACC-0001-0001)
- Balance calculated from transactions, not stored here

#### 4.2.7 Member Product Subscriptions Table
```sql
CREATE TABLE member_product_subscriptions (
    id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    member_id BIGINT UNSIGNED NOT NULL,
    sacco_product_id BIGINT UNSIGNED NOT NULL,
    subscription_date DATE NOT NULL,
    start_date DATE NOT NULL,
    end_date DATE,
    status ENUM('active', 'completed', 'cancelled', 'suspended') DEFAULT 'active',
    total_paid DECIMAL(15, 2) DEFAULT 0.00,
    total_expected DECIMAL(15, 2),
    payment_count INTEGER DEFAULT 0,
    last_payment_date DATE,
    next_payment_date DATE,
    notes TEXT,
    created_at TIMESTAMP,
    updated_at TIMESTAMP,
    
    FOREIGN KEY (member_id) REFERENCES members(id) ON DELETE CASCADE,
    FOREIGN KEY (sacco_product_id) REFERENCES sacco_products(id) ON DELETE RESTRICT,
    INDEX idx_member (member_id),
    INDEX idx_product (sacco_product_id),
    INDEX idx_status (status),
    INDEX idx_next_payment (next_payment_date)
);
```

**Explanation:**
- Tracks member enrollment in subscription products (Risk Fund, Welfare, etc.)
- `total_paid`: Cumulative amount paid (updated via transactions)
- `next_payment_date`: For reminder/notification systems
- `status = 'completed'`: When total_paid >= total_expected

#### 4.2.8 Loan Guarantors Table
```sql
CREATE TABLE loan_guarantors (
    id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    loan_id BIGINT UNSIGNED NOT NULL,
    guarantor_member_id BIGINT UNSIGNED NOT NULL,
    guaranteed_amount DECIMAL(15, 2) NOT NULL,
    guarantor_savings_at_guarantee DECIMAL(15, 2), -- Snapshot of guarantor's savings
    status ENUM('pending', 'approved', 'rejected', 'released') DEFAULT 'pending',
    approved_at TIMESTAMP,
    approved_by BIGINT UNSIGNED,
    rejection_reason TEXT,
    created_at TIMESTAMP,
    updated_at TIMESTAMP,
    
    FOREIGN KEY (loan_id) REFERENCES loans(id) ON DELETE CASCADE,
    FOREIGN KEY (guarantor_member_id) REFERENCES members(id) ON DELETE CASCADE,
    FOREIGN KEY (approved_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_loan (loan_id),
    INDEX idx_guarantor (guarantor_member_id)
);
```

#### 4.2.9 Loan Product Rules Table
```sql
CREATE TABLE loan_product_rules (
    id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    loan_product_id BIGINT UNSIGNED NOT NULL,
    rule_type VARCHAR(100) NOT NULL, -- 'eligibility', 'amount_calculation', 'guarantor_requirement', 'penalty'
    rule_name VARCHAR(255) NOT NULL,
    rule_config JSON NOT NULL,
    priority INTEGER DEFAULT 0,
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP,
    updated_at TIMESTAMP,
    
    FOREIGN KEY (loan_product_id) REFERENCES loan_products(id) ON DELETE CASCADE,
    INDEX idx_loan_product (loan_product_id),
    INDEX idx_rule_type (rule_type)
);
```

**Sample Rule Configurations:**

**Example 1: Starter Loan - Time-Based Eligibility**
```json
{
  "rule_type": "eligibility",
  "conditions": {
    "group_age_months": {"max": 3},
    "member_status": "active"
  },
  "error_message": "Starter Loan only available in first 3 months of group formation"
}
```

**Example 2: Starter Loan - Amount Calculation**
```json
{
  "rule_type": "amount_calculation",
  "formula": "savings_last_n_months * multiplier",
  "parameters": {
    "months": 2,
    "multiplier": 2,
    "round_to": 1
  },
  "max_amount": null,
  "min_amount": 100
}
```

**Example 3: Long Term Loan - Guarantor Requirement**
```json
{
  "rule_type": "guarantor_requirement",
  "rules": [
    {"condition": {"loan_amount": {"lt": 5000}}, "min_guarantors": 1},
    {"condition": {"loan_amount": {"gte": 5000}}, "min_guarantors": 2}
  ]
}
```

**Example 4: Advance Loan - Penalty Calculation**
```json
{
  "rule_type": "penalty",
  "trigger": "default",
  "calculation": {
    "type": "percentage",
    "base": "loan_amount",
    "rate": 20,
    "description": "Double the interest rate"
  }
}
```

#### 4.2.10 Product Transaction Types Registry
```sql
CREATE TABLE product_transaction_types (
    id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    sacco_product_id BIGINT UNSIGNED,
    transaction_type VARCHAR(100) NOT NULL,
    display_name VARCHAR(255) NOT NULL,
    debit_account_type VARCHAR(100), -- References account_type in sacco_product_chart_of_accounts
    credit_account_type VARCHAR(100),
    description TEXT,
    created_at TIMESTAMP,
    updated_at TIMESTAMP,
    
    FOREIGN KEY (sacco_product_id) REFERENCES sacco_products(id) ON DELETE CASCADE,
    INDEX idx_transaction_type (transaction_type)
);
```

**Sample Data:**
```sql
-- Savings Product
INSERT INTO product_transaction_types VALUES
(null, 1, 'savings_deposit', 'Savings Deposit', 'savings_account', 'bank', 'Member deposits money to savings'),
(null, 1, 'savings_withdrawal', 'Savings Withdrawal', 'bank', 'savings_account', 'Member withdraws from savings'),
(null, 1, 'savings_interest', 'Savings Interest', 'savings_interest_expense', 'savings_account', 'Interest earned on savings');

-- Risk Fund Product
INSERT INTO product_transaction_types VALUES
(null, 2, 'risk_fund_payment', 'Risk Fund Payment', 'contribution_receivable', 'bank', 'Risk fund contribution payment'),
(null, 2, 'risk_fund_allocation', 'Risk Fund Allocation', 'risk_fund_account', 'contribution_income', 'Risk fund allocation');
```

### 4.3 Schema Modifications to Existing Tables

#### 4.3.1 Modify `transactions` Table
```sql
-- Add new fields to support SACCO operations
ALTER TABLE transactions 
ADD COLUMN savings_account_id BIGINT UNSIGNED AFTER repayment_id,
ADD COLUMN product_subscription_id BIGINT UNSIGNED AFTER savings_account_id,
ADD COLUMN reference_number VARCHAR(100) AFTER description,
ADD COLUMN metadata JSON AFTER reference_number,
ADD FOREIGN KEY (savings_account_id) REFERENCES member_savings_accounts(id) ON DELETE CASCADE,
ADD FOREIGN KEY (product_subscription_id) REFERENCES member_product_subscriptions(id) ON DELETE CASCADE;
```

**Rationale:** Allows transactions to link to savings accounts or subscriptions, maintaining the existing loan/repayment links.

#### 4.3.2 Modify `groups` Table
```sql
-- Add group formation tracking for loan eligibility rules
ALTER TABLE groups
ADD COLUMN formation_date DATE AFTER name,
ADD COLUMN registration_number VARCHAR(100) AFTER formation_date;
```

#### 4.3.3 Modify `members` Table
```sql
-- Already has account_number from boot() method
-- Add additional fields for SACCO operations
ALTER TABLE members
ADD COLUMN member_since DATE AFTER is_active,
ADD COLUMN membership_status ENUM('active', 'inactive', 'suspended', 'exited') DEFAULT 'active' AFTER member_since;
```

### 4.4 Migration Strategy

**Order of Execution:**
1. Create `sacco_product_types` table and seed data
2. Create `sacco_product_attributes` table and seed common attributes
3. Create `sacco_products` table
4. Create `sacco_product_attribute_values` table
5. Create `sacco_product_chart_of_accounts` table
6. Create `member_savings_accounts` table
7. Create `member_product_subscriptions` table
8. Create `loan_guarantors` table
9. Create `loan_product_rules` table
10. Create `product_transaction_types` table
11. Modify existing tables (`transactions`, `groups`, `members`)

**Rollback Safety:** Each migration should have a proper `down()` method that drops tables in reverse order.

---

## 5. Member Savings Module

### 5.1 Functional Requirements

1. **Savings Deposit**
   - Member can deposit any amount (subject to product limits)
   - Transaction recorded in double-entry system
   - Balance calculated dynamically from transactions
   - Support multiple deposit methods (cash, bank transfer, mobile money)

2. **Savings Withdrawal**
   - Check product configuration (`allows_withdrawal`)
   - Validate sufficient balance
   - Record withdrawal transaction
   - Optional: Require approval for large withdrawals

3. **Savings Balance Inquiry**
   - Show cumulative deposits
   - Show cumulative withdrawals
   - Show current balance
   - Show interest earned (if applicable)

4. **Savings History**
   - List all transactions
   - Filter by date range, transaction type
   - Export to PDF/Excel

### 5.2 Savings Product Configuration

**Example: Main Savings Account**
```php
SaccoProduct::create([
    'product_type_id' => 1, // Member Savings
    'name' => 'Member Main Savings',
    'code' => 'MAIN_SAVINGS',
    'description' => 'Primary member savings account',
    'is_active' => true,
    'is_mandatory' => true,
]);

// Assign attributes
$product->attributes()->attach([
    'minimum_deposit' => 100,
    'maximum_deposit' => 1000000,
    'allows_withdrawal' => true,
    'savings_interest_rate' => 5.0, // 5% per annum
]);

// Assign chart of accounts
$product->chartOfAccounts()->create([
    'account_type' => 'savings_account',
    'account_number' => '2201', // Members Savings Account (Liability)
]);
$product->chartOfAccounts()->create([
    'account_type' => 'bank',
    'account_number' => '1001', // Bank Account
]);
```

### 5.3 Savings Service Implementation

**File:** `app/Services/SavingsService.php`

```php
<?php

namespace App\Services;

use App\Models\Member;
use App\Models\MemberSavingsAccount;
use App\Models\Transaction;
use Illuminate\Support\Facades\DB;

class SavingsService
{
    /**
     * Deposit money to member savings account
     */
    public function deposit(
        MemberSavingsAccount $savingsAccount,
        float $amount,
        string $paymentMethod = 'cash',
        string $referenceNumber = null,
        string $notes = null
    ): array {
        return DB::transaction(function () use ($savingsAccount, $amount, $paymentMethod, $referenceNumber, $notes) {
            // Get account mappings
            $savingsAccountGL = $savingsAccount->product->getAccountNumber('savings_account');
            $bankAccountGL = $savingsAccount->product->getAccountNumber('bank');
            
            // Create double-entry transactions
            $transactions = [];
            
            // Debit: Bank/Cash Account (money coming in)
            $transactions[] = Transaction::create([
                'account_name' => $savingsAccount->product->getAccountName('bank'),
                'account_number' => $bankAccountGL,
                'member_id' => $savingsAccount->member_id,
                'savings_account_id' => $savingsAccount->id,
                'transaction_type' => 'savings_deposit',
                'dr_cr' => 'dr',
                'amount' => $amount,
                'transaction_date' => now(),
                'description' => "Savings deposit by {$savingsAccount->member->name}",
                'reference_number' => $referenceNumber,
                'metadata' => json_encode(['payment_method' => $paymentMethod, 'notes' => $notes]),
            ]);
            
            // Credit: Member Savings Account (liability increases)
            $transactions[] = Transaction::create([
                'account_name' => $savingsAccount->product->getAccountName('savings_account'),
                'account_number' => $savingsAccountGL,
                'member_id' => $savingsAccount->member_id,
                'savings_account_id' => $savingsAccount->id,
                'transaction_type' => 'savings_deposit',
                'dr_cr' => 'cr',
                'amount' => $amount,
                'transaction_date' => now(),
                'description' => "Savings deposit by {$savingsAccount->member->name}",
                'reference_number' => $referenceNumber,
                'metadata' => json_encode(['payment_method' => $paymentMethod, 'notes' => $notes]),
            ]);
            
            return [
                'success' => true,
                'transactions' => $transactions,
                'new_balance' => $this->getBalance($savingsAccount),
            ];
        });
    }
    
    /**
     * Withdraw money from savings account
     */
    public function withdraw(
        MemberSavingsAccount $savingsAccount,
        float $amount,
        string $paymentMethod = 'cash',
        string $referenceNumber = null,
        string $notes = null
    ): array {
        // Validate withdrawal allowed
        if (!$savingsAccount->product->getAttribute('allows_withdrawal')) {
            throw new \Exception('Withdrawals not allowed for this savings product');
        }
        
        // Check sufficient balance
        $balance = $this->getBalance($savingsAccount);
        if ($balance < $amount) {
            throw new \Exception("Insufficient balance. Available: {$balance}");
        }
        
        return DB::transaction(function () use ($savingsAccount, $amount, $paymentMethod, $referenceNumber, $notes) {
            $savingsAccountGL = $savingsAccount->product->getAccountNumber('savings_account');
            $bankAccountGL = $savingsAccount->product->getAccountNumber('bank');
            
            $transactions = [];
            
            // Debit: Member Savings Account (liability decreases)
            $transactions[] = Transaction::create([
                'account_name' => $savingsAccount->product->getAccountName('savings_account'),
                'account_number' => $savingsAccountGL,
                'member_id' => $savingsAccount->member_id,
                'savings_account_id' => $savingsAccount->id,
                'transaction_type' => 'savings_withdrawal',
                'dr_cr' => 'dr',
                'amount' => $amount,
                'transaction_date' => now(),
                'description' => "Savings withdrawal by {$savingsAccount->member->name}",
                'reference_number' => $referenceNumber,
                'metadata' => json_encode(['payment_method' => $paymentMethod, 'notes' => $notes]),
            ]);
            
            // Credit: Bank/Cash Account (money going out)
            $transactions[] = Transaction::create([
                'account_name' => $savingsAccount->product->getAccountName('bank'),
                'account_number' => $bankAccountGL,
                'member_id' => $savingsAccount->member_id,
                'savings_account_id' => $savingsAccount->id,
                'transaction_type' => 'savings_withdrawal',
                'dr_cr' => 'cr',
                'amount' => $amount,
                'transaction_date' => now(),
                'description' => "Savings withdrawal by {$savingsAccount->member->name}",
                'reference_number' => $referenceNumber,
                'metadata' => json_encode(['payment_method' => $paymentMethod, 'notes' => $notes]),
            ]);
            
            return [
                'success' => true,
                'transactions' => $transactions,
                'new_balance' => $this->getBalance($savingsAccount),
            ];
        });
    }
    
    /**
     * Get savings account balance
     */
    public function getBalance(MemberSavingsAccount $savingsAccount): float
    {
        $savingsAccountName = $savingsAccount->product->getAccountName('savings_account');
        
        // Sum credits (deposits increase liability)
        $deposits = Transaction::where('savings_account_id', $savingsAccount->id)
            ->where('account_name', $savingsAccountName)
            ->where('dr_cr', 'cr')
            ->sum('amount');
        
        // Sum debits (withdrawals decrease liability)
        $withdrawals = Transaction::where('savings_account_id', $savingsAccount->id)
            ->where('account_name', $savingsAccountName)
            ->where('dr_cr', 'dr')
            ->sum('amount');
        
        return max(0, $deposits - $withdrawals);
    }
    
    /**
     * Get cumulative savings for loan eligibility
     */
    public function getCumulativeSavings(Member $member, int $months = null): float
    {
        $query = Transaction::where('member_id', $member->id)
            ->where('transaction_type', 'savings_deposit')
            ->where('dr_cr', 'cr'); // Credits to savings account
        
        if ($months) {
            $query->where('transaction_date', '>=', now()->subMonths($months));
        }
        
        return $query->sum('amount');
    }
}
```

### 5.4 Savings Account Creation Flow

**Automatic Creation on First Deposit:**
```php
// In SavingsService or dedicated AccountOpeningService
public function openSavingsAccount(Member $member, SaccoProduct $product): MemberSavingsAccount
{
    // Check if account already exists
    $existing = MemberSavingsAccount::where('member_id', $member->id)
        ->where('sacco_product_id', $product->id)
        ->first();
    
    if ($existing) {
        return $existing;
    }
    
    // Generate account number
    $accountNumber = $this->generateSavingsAccountNumber($member, $product);
    
    return MemberSavingsAccount::create([
        'member_id' => $member->id,
        'sacco_product_id' => $product->id,
        'account_number' => $accountNumber,
        'opening_date' => now(),
        'status' => 'active',
    ]);
}

private function generateSavingsAccountNumber(Member $member, SaccoProduct $product): string
{
    // Format: SAV-{PRODUCT_CODE}-{MEMBER_ACC_NUMBER}
    // Example: SAV-MAIN-ACC-0001
    return 'SAV-' . strtoupper($product->code) . '-' . $member->account_number;
}
```

---

## 6. Additional Products System

### 6.1 Product Type Categories

1. **Subscription Products** (recurring payments)
   - Risk Fund
   - Welfare
   - Haul
   - Monthly contributions

2. **One-Time Fee Products**
   - Registration Fee
   - Passbook
   - Certificate Fee

3. **Irregular Products**
   - Fines
   - Penalties
   - Special levies

### 6.2 Subscription Product Configuration

**Example 1: Risk Fund**
```php
$productType = SaccoProductType::where('slug', 'subscription-product')->first();

$riskFund = SaccoProduct::create([
    'product_type_id' => $productType->id,
    'name' => 'Risk Fund',
    'code' => 'RISK_FUND',
    'description' => 'Monthly risk fund contribution',
    'is_active' => true,
    'is_mandatory' => true,
]);

// Assign attributes
$riskFund->attributeValues()->create([
    'attribute_id' => SaccoProductAttribute::where('slug', 'payment_frequency')->first()->id,
    'value' => 'monthly',
]);
$riskFund->attributeValues()->create([
    'attribute_id' => SaccoProductAttribute::where('slug', 'amount_per_cycle')->first()->id,
    'value' => '30',
]);
$riskFund->attributeValues()->create([
    'attribute_id' => SaccoProductAttribute::where('slug', 'total_cycles')->first()->id,
    'value' => '12',
]);
$riskFund->attributeValues()->create([
    'attribute_id' => SaccoProductAttribute::where('slug', 'max_total_amount')->first()->id,
    'value' => '360',
]);

// Chart of accounts
$riskFund->chartOfAccounts()->create([
    'account_type' => 'contribution_receivable',
    'account_number' => '1301', // Risk Fund Receivable
]);
$riskFund->chartOfAccounts()->create([
    'account_type' => 'contribution_income',
    'account_number' => '4201', // Risk Fund Income
]);
```

**Example 2: Registration Fee (Dynamic Pricing)**
```php
$regFee = SaccoProduct::create([
    'product_type_id' => SaccoProductType::where('slug', 'one-time-fee')->first()->id,
    'name' => 'Registration Fee',
    'code' => 'REG_FEE',
    'description' => 'One-time registration fee with escalating pricing',
    'is_active' => true,
    'is_mandatory' => true,
]);

$regFee->attributeValues()->create([
    'attribute_id' => SaccoProductAttribute::where('slug', 'payment_type')->first()->id,
    'value' => 'one_time',
]);

// Store pricing formula in JSON attribute
$regFee->attributeValues()->create([
    'attribute_id' => SaccoProductAttribute::where('slug', 'calculation_formula')->first()->id,
    'value' => json_encode([
        'type' => 'escalating',
        'base_amount' => 300,
        'increment_amount' => 50,
        'increment_frequency' => 'monthly',
        'max_amount' => 3000,
    ]),
]);
```

### 6.3 Subscription Management Service

**File:** `app/Services/SubscriptionService.php`

```php
<?php

namespace App\Services;

use App\Models\Member;
use App\Models\MemberProductSubscription;
use App\Models\SaccoProduct;
use App\Models\Transaction;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class SubscriptionService
{
    /**
     * Subscribe member to a product
     */
    public function subscribe(Member $member, SaccoProduct $product): MemberProductSubscription
    {
        // Check if already subscribed
        $existing = MemberProductSubscription::where('member_id', $member->id)
            ->where('sacco_product_id', $product->id)
            ->whereIn('status', ['active', 'suspended'])
            ->first();
        
        if ($existing) {
            throw new \Exception('Member already subscribed to this product');
        }
        
        // Get product configuration
        $frequency = $product->getAttributeValue('payment_frequency'); // 'monthly', 'yearly'
        $amountPerCycle = $product->getAttributeValue('amount_per_cycle');
        $totalCycles = $product->getAttributeValue('total_cycles');
        $maxTotalAmount = $product->getAttributeValue('max_total_amount');
        
        // Calculate expected total
        $totalExpected = $maxTotalAmount ?? ($amountPerCycle * $totalCycles);
        
        // Calculate next payment date
        $nextPaymentDate = $this->calculateNextPaymentDate(now(), $frequency);
        
        return MemberProductSubscription::create([
            'member_id' => $member->id,
            'sacco_product_id' => $product->id,
            'subscription_date' => now(),
            'start_date' => now(),
            'status' => 'active',
            'total_paid' => 0,
            'total_expected' => $totalExpected,
            'payment_count' => 0,
            'next_payment_date' => $nextPaymentDate,
        ]);
    }
    
    /**
     * Record payment for subscription
     */
    public function recordPayment(
        MemberProductSubscription $subscription,
        float $amount,
        string $paymentMethod = 'cash',
        string $referenceNumber = null
    ): array {
        return DB::transaction(function () use ($subscription, $amount, $paymentMethod, $referenceNumber) {
            // Get account mappings
            $receivableGL = $subscription->product->getAccountNumber('contribution_receivable');
            $incomeGL = $subscription->product->getAccountNumber('contribution_income');
            $bankGL = $subscription->product->getAccountNumber('bank');
            
            $transactions = [];
            
            // Debit: Bank Account
            $transactions[] = Transaction::create([
                'account_name' => $subscription->product->getAccountName('bank'),
                'account_number' => $bankGL,
                'member_id' => $subscription->member_id,
                'product_subscription_id' => $subscription->id,
                'transaction_type' => 'subscription_payment',
                'dr_cr' => 'dr',
                'amount' => $amount,
                'transaction_date' => now(),
                'description' => "{$subscription->product->name} payment by {$subscription->member->name}",
                'reference_number' => $referenceNumber,
                'metadata' => json_encode(['payment_method' => $paymentMethod]),
            ]);
            
            // Credit: Contribution Income
            $transactions[] = Transaction::create([
                'account_name' => $subscription->product->getAccountName('contribution_income'),
                'account_number' => $incomeGL,
                'member_id' => $subscription->member_id,
                'product_subscription_id' => $subscription->id,
                'transaction_type' => 'subscription_payment',
                'dr_cr' => 'cr',
                'amount' => $amount,
                'transaction_date' => now(),
                'description' => "{$subscription->product->name} income from {$subscription->member->name}",
                'reference_number' => $referenceNumber,
            ]);
            
            // Update subscription record
            $subscription->total_paid += $amount;
            $subscription->payment_count += 1;
            $subscription->last_payment_date = now();
            
            // Check if completed
            if ($subscription->total_paid >= $subscription->total_expected) {
                $subscription->status = 'completed';
                $subscription->end_date = now();
            } else {
                // Calculate next payment date
                $frequency = $subscription->product->getAttributeValue('payment_frequency');
                $subscription->next_payment_date = $this->calculateNextPaymentDate(now(), $frequency);
            }
            
            $subscription->save();
            
            return [
                'success' => true,
                'transactions' => $transactions,
                'subscription' => $subscription->fresh(),
            ];
        });
    }
    
    /**
     * Get outstanding amount for subscription
     */
    public function getOutstandingAmount(MemberProductSubscription $subscription): float
    {
        return max(0, $subscription->total_expected - $subscription->total_paid);
    }
    
    /**
     * Calculate next payment date based on frequency
     */
    private function calculateNextPaymentDate(Carbon $from, string $frequency): Carbon
    {
        return match($frequency) {
            'daily' => $from->copy()->addDay(),
            'weekly' => $from->copy()->addWeek(),
            'monthly' => $from->copy()->addMonth(),
            'quarterly' => $from->copy()->addMonths(3),
            'yearly' => $from->copy()->addYear(),
            default => $from->copy()->addMonth(),
        };
    }
    
    /**
     * Get overdue subscriptions (for notifications)
     */
    public function getOverdueSubscriptions(): \Illuminate\Database\Eloquent\Collection
    {
        return MemberProductSubscription::where('status', 'active')
            ->where('next_payment_date', '<', now())
            ->with(['member', 'product'])
            ->get();
    }
}
```

### 6.4 Dynamic Fee Calculation Service

**File:** `app/Services/FeeCalculationService.php`

```php
<?php

namespace App\Services;

use App\Models\Member;
use App\Models\SaccoProduct;
use Carbon\Carbon;

class FeeCalculationService
{
    /**
     * Calculate fee amount based on product configuration
     */
    public function calculateFee(SaccoProduct $product, Member $member, Carbon $date = null): float
    {
        $date = $date ?? now();
        $formula = json_decode($product->getAttributeValue('calculation_formula'), true);
        
        if (!$formula) {
            // Simple fixed amount
            return (float) $product->getAttributeValue('amount_per_cycle') ?? 0;
        }
        
        return match($formula['type']) {
            'fixed' => $formula['amount'],
            'escalating' => $this->calculateEscalatingFee($formula, $date),
            'percentage' => $this->calculatePercentageFee($formula, $member),
            default => 0,
        };
    }
    
    /**
     * Calculate escalating fee (e.g., Registration Fee)
     */
    private function calculateEscalatingFee(array $formula, Carbon $date): float
    {
        // Example: 300 + (50 * months_since_launch) up to max 3000
        $launchDate = Carbon::parse($formula['launch_date'] ?? '2025-01-01');
        $monthsSinceLaunch = max(0, $date->diffInMonths($launchDate));
        
        $baseAmount = $formula['base_amount'];
        $incrementAmount = $formula['increment_amount'];
        $maxAmount = $formula['max_amount'];
        
        $calculatedAmount = $baseAmount + ($incrementAmount * $monthsSinceLaunch);
        
        return min($calculatedAmount, $maxAmount);
    }
    
    /**
     * Calculate percentage-based fee
     */
    private function calculatePercentageFee(array $formula, Member $member): float
    {
        // Example: 2% of total savings
        $base = match($formula['base']) {
            'savings' => app(SavingsService::class)->getCumulativeSavings($member),
            'loan_amount' => $formula['loan_amount'] ?? 0,
            default => 0,
        };
        
        $percentage = $formula['percentage'] / 100;
        $amount = $base * $percentage;
        
        // Apply min/max
        if (isset($formula['min_amount'])) {
            $amount = max($amount, $formula['min_amount']);
        }
        if (isset($formula['max_amount'])) {
            $amount = min($amount, $formula['max_amount']);
        }
        
        return $amount;
    }
}
```

---

## 7. Dynamic Loan Products with Flexible Rules

### 7.1 Rule Engine Architecture

The loan product rule system allows defining complex business logic in the database without code changes.

**Rule Types:**
1. **Eligibility Rules** - Who can apply
2. **Amount Calculation Rules** - How much they can borrow
3. **Guarantor Rules** - How many guarantors required
4. **Penalty Rules** - What happens on default
5. **Interest Calculation Rules** - Custom interest formulas

### 7.2 Loan Product Examples with Rules

#### Example 1: Starter Loan
```php
$starterLoan = LoanProduct::create([
    'name' => 'Starter Loan',
    'description' => 'Short-term loan for new groups',
    'is_active' => true,
]);

// Eligibility Rule: Only in first 3 months
LoanProductRule::create([
    'loan_product_id' => $starterLoan->id,
    'rule_type' => 'eligibility',
    'rule_name' => 'Group Age Restriction',
    'rule_config' => json_encode([
        'conditions' => [
            'group_age_months' => ['operator' => '<=', 'value' => 3],
        ],
        'error_message' => 'Starter Loan only available in first 3 months of group formation',
    ]),
    'priority' => 1,
]);

// Amount Calculation Rule: 2x savings in last 2 months
LoanProductRule::create([
    'loan_product_id' => $starterLoan->id,
    'rule_type' => 'amount_calculation',
    'rule_name' => 'Savings-Based Amount',
    'rule_config' => json_encode([
        'formula' => 'cumulative_savings * multiplier',
        'parameters' => [
            'savings_period_months' => 2,
            'multiplier' => 2,
        ],
        'min_amount' => 100,
        'max_amount' => null,
    ]),
    'priority' => 1,
]);

// Loan Attributes
$starterLoan->LoanProductAttributes()->create([
    'loan_attribute_id' => LoanAttribute::where('slug', 'loan_duration')->first()->id,
    'value' => '1', // 1 month
]);
$starterLoan->LoanProductAttributes()->create([
    'loan_attribute_id' => LoanAttribute::where('slug', 'interest_rate')->first()->id,
    'value' => '10', // 10% flat
]);
$starterLoan->LoanProductAttributes()->create([
    'loan_attribute_id' => LoanAttribute::where('slug', 'loan_charges')->first()->id,
    'value' => '10', // Ksh 10
]);
```

#### Example 2: Long Term Loan
```php
$longTermLoan = LoanProduct::create([
    'name' => 'Long Term Loan',
    'description' => 'Long-term loan with guarantors',
    'is_active' => true,
]);

// Eligibility: After 3 months
LoanProductRule::create([
    'loan_product_id' => $longTermLoan->id,
    'rule_type' => 'eligibility',
    'rule_name' => 'Minimum Group Age',
    'rule_config' => json_encode([
        'conditions' => [
            'group_age_months' => ['operator' => '>', 'value' => 3],
        ],
        'error_message' => 'Long Term Loan available after 3 months of group formation',
    ]),
]);

// Amount Calculation: 3x savings, rounded to nearest 5000
LoanProductRule::create([
    'loan_product_id' => $longTermLoan->id,
    'rule_type' => 'amount_calculation',
    'rule_name' => 'Savings-Based with Rounding',
    'rule_config' => json_encode([
        'formula' => 'round_to_nearest(cumulative_savings * multiplier, round_to)',
        'parameters' => [
            'savings_period_months' => null, // All time
            'multiplier' => 3,
            'round_to' => 5000,
        ],
    ]),
]);

// Guarantor Rules
LoanProductRule::create([
    'loan_product_id' => $longTermLoan->id,
    'rule_type' => 'guarantor_requirement',
    'rule_name' => 'Tiered Guarantor Requirement',
    'rule_config' => json_encode([
        'tiers' => [
            ['max_amount' => 4999, 'min_guarantors' => 1],
            ['min_amount' => 5000, 'min_guarantors' => 2],
        ],
    ]),
]);

// Interest: 1.5% reducing balance
$longTermLoan->LoanProductAttributes()->create([
    'loan_attribute_id' => LoanAttribute::where('slug', 'interest_rate')->first()->id,
    'value' => '1.5',
]);
$longTermLoan->LoanProductAttributes()->create([
    'loan_attribute_id' => LoanAttribute::where('slug', 'interest_type')->first()->id,
    'value' => 'reducing_balance',
]);

// Loan charge: 2% for every 5100
LoanProductRule::create([
    'loan_product_id' => $longTermLoan->id,
    'rule_type' => 'charge_calculation',
    'rule_name' => 'Tiered Charge Calculation',
    'rule_config' => json_encode([
        'formula' => '(loan_amount / tier_amount) * charge_per_tier',
        'parameters' => [
            'tier_amount' => 5100,
            'charge_per_tier' => 102, // 2% of 5100
        ],
    ]),
]);
```

### 7.3 Rule Validation Service

**File:** `app/Services/LoanEligibilityService.php`

```php
<?php

namespace App\Services;

use App\Models\Loan;
use App\Models\LoanProduct;
use App\Models\LoanProductRule;
use App\Models\Member;
use Carbon\Carbon;

class LoanEligibilityService
{
    protected SavingsService $savingsService;
    
    public function __construct(SavingsService $savingsService)
    {
        $this->savingsService = $savingsService;
    }
    
    /**
     * Check if member is eligible for loan product
     */
    public function checkEligibility(Member $member, LoanProduct $loanProduct): array
    {
        $rules = $loanProduct->rules()
            ->where('rule_type', 'eligibility')
            ->where('is_active', true)
            ->orderBy('priority')
            ->get();
        
        $errors = [];
        
        foreach ($rules as $rule) {
            $result = $this->evaluateEligibilityRule($member, $rule);
            if (!$result['passed']) {
                $errors[] = $result['message'];
            }
        }
        
        return [
            'eligible' => empty($errors),
            'errors' => $errors,
        ];
    }
    
    /**
     * Evaluate a single eligibility rule
     */
    private function evaluateEligibilityRule(Member $member, LoanProductRule $rule): array
    {
        $config = json_decode($rule->rule_config, true);
        $conditions = $config['conditions'];
        
        foreach ($conditions as $field => $condition) {
            $actualValue = $this->getFieldValue($member, $field);
            $expectedValue = $condition['value'] ?? $condition;
            $operator = $condition['operator'] ?? '==';
            
            if (!$this->compareValues($actualValue, $expectedValue, $operator)) {
                return [
                    'passed' => false,
                    'message' => $config['error_message'] ?? "Eligibility check failed for {$field}",
                ];
            }
        }
        
        return ['passed' => true];
    }
    
    /**
     * Calculate maximum loan amount based on rules
     */
    public function calculateMaxLoanAmount(Member $member, LoanProduct $loanProduct): float
    {
        $rules = $loanProduct->rules()
            ->where('rule_type', 'amount_calculation')
            ->where('is_active', true)
            ->orderBy('priority')
            ->first();
        
        if (!$rules) {
            return 0;
        }
        
        $config = json_decode($rules->rule_config, true);
        
        return $this->evaluateFormula($member, $config);
    }
    
    /**
     * Evaluate amount calculation formula
     */
    private function evaluateFormula(Member $member, array $config): float
    {
        $formula = $config['formula'];
        $parameters = $config['parameters'] ?? [];
        
        // Get cumulative savings
        $savingsPeriodMonths = $parameters['savings_period_months'] ?? null;
        $cumulativeSavings = $this->savingsService->getCumulativeSavings($member, $savingsPeriodMonths);
        
        // Apply multiplier
        $multiplier = $parameters['multiplier'] ?? 1;
        $amount = $cumulativeSavings * $multiplier;
        
        // Apply rounding if specified
        if (isset($parameters['round_to'])) {
            $roundTo = $parameters['round_to'];
            $amount = round($amount / $roundTo) * $roundTo;
        }
        
        // Apply min/max constraints
        if (isset($config['min_amount'])) {
            $amount = max($amount, $config['min_amount']);
        }
        if (isset($config['max_amount'])) {
            $amount = min($amount, $config['max_amount']);
        }
        
        return $amount;
    }
    
    /**
     * Get number of guarantors required
     */
    public function getRequiredGuarantors(LoanProduct $loanProduct, float $loanAmount): int
    {
        $rule = $loanProduct->rules()
            ->where('rule_type', 'guarantor_requirement')
            ->where('is_active', true)
            ->first();
        
        if (!$rule) {
            return 0;
        }
        
        $config = json_decode($rule->rule_config, true);
        $tiers = $config['tiers'] ?? [];
        
        foreach ($tiers as $tier) {
            $minAmount = $tier['min_amount'] ?? 0;
            $maxAmount = $tier['max_amount'] ?? PHP_FLOAT_MAX;
            
            if ($loanAmount >= $minAmount && $loanAmount <= $maxAmount) {
                return $tier['min_guarantors'];
            }
        }
        
        return 0;
    }
    
    /**
     * Get field value from member or group
     */
    private function getFieldValue(Member $member, string $field)
    {
        return match($field) {
            'group_age_months' => $this->getGroupAgeInMonths($member->group),
            'member_age_months' => now()->diffInMonths($member->member_since),
            'member_status' => $member->membership_status,
            'cumulative_savings' => $this->savingsService->getCumulativeSavings($member),
            default => null,
        };
    }
    
    /**
     * Get group age in months
     */
    private function getGroupAgeInMonths($group): int
    {
        if (!$group || !$group->formation_date) {
            return 0;
        }
        
        return now()->diffInMonths(Carbon::parse($group->formation_date));
    }
    
    /**
     * Compare values with operator
     */
    private function compareValues($actual, $expected, string $operator): bool
    {
        return match($operator) {
            '==' => $actual == $expected,
            '!=' => $actual != $expected,
            '>' => $actual > $expected,
            '>=' => $actual >= $expected,
            '<' => $actual < $expected,
            '<=' => $actual <= $expected,
            'in' => in_array($actual, $expected),
            'not_in' => !in_array($actual, $expected),
            default => false,
        };
    }
}
```

### 7.4 Guarantor Management Service

**File:** `app/Services/GuarantorService.php`

```php
<?php

namespace App\Services;

use App\Models\Loan;
use App\Models\LoanGuarantor;
use App\Models\Member;
use Illuminate\Support\Facades\DB;

class GuarantorService
{
    protected SavingsService $savingsService;
    protected LoanEligibilityService $eligibilityService;
    
    public function __construct(
        SavingsService $savingsService,
        LoanEligibilityService $eligibilityService
    ) {
        $this->savingsService = $savingsService;
        $this->eligibilityService = $eligibilityService;
    }
    
    /**
     * Add guarantor to loan
     */
    public function addGuarantor(
        Loan $loan,
        Member $guarantor,
        float $guaranteedAmount
    ): LoanGuarantor {
        // Validate guarantor is not the borrower
        if ($guarantor->id === $loan->member_id) {
            throw new \Exception('Member cannot guarantee their own loan');
        }
        
        // Check if already a guarantor
        $existing = LoanGuarantor::where('loan_id', $loan->id)
            ->where('guarantor_member_id', $guarantor->id)
            ->first();
        
        if ($existing) {
            throw new \Exception('This member is already a guarantor for this loan');
        }
        
        // Get guarantor's current savings (snapshot)
        $guarantorSavings = $this->savingsService->getCumulativeSavings($guarantor);
        
        // Validate guarantor has sufficient savings (optional business rule)
        if ($guarantorSavings < $guaranteedAmount) {
            throw new \Exception("Guarantor must have savings >= guaranteed amount");
        }
        
        return LoanGuarantor::create([
            'loan_id' => $loan->id,
            'guarantor_member_id' => $guarantor->id,
            'guaranteed_amount' => $guaranteedAmount,
            'guarantor_savings_at_guarantee' => $guarantorSavings,
            'status' => 'pending',
        ]);
    }
    
    /**
     * Validate loan has sufficient guarantors
     */
    public function validateGuarantors(Loan $loan): array
    {
        $requiredCount = $this->eligibilityService->getRequiredGuarantors(
            $loan->loanProduct,
            $loan->principal_amount
        );
        
        $actualCount = LoanGuarantor::where('loan_id', $loan->id)
            ->whereIn('status', ['approved', 'pending'])
            ->count();
        
        return [
            'valid' => $actualCount >= $requiredCount,
            'required' => $requiredCount,
            'actual' => $actualCount,
            'message' => $actualCount >= $requiredCount
                ? 'Sufficient guarantors'
                : "Need {$requiredCount} guarantors, only have {$actualCount}",
        ];
    }
    
    /**
     * Approve guarantor
     */
    public function approveGuarantor(LoanGuarantor $guarantor, int $approvedBy): void
    {
        $guarantor->update([
            'status' => 'approved',
            'approved_at' => now(),
            'approved_by' => $approvedBy,
        ]);
    }
    
    /**
     * Release guarantors when loan is fully repaid
     */
    public function releaseGuarantors(Loan $loan): void
    {
        LoanGuarantor::where('loan_id', $loan->id)
            ->where('status', 'approved')
            ->update(['status' => 'released']);
    }
}
```

---

## 8. Transaction System Integration

### 8.1 Transaction Type Registry

All new transaction types must be registered and mapped to GL accounts.

**New Transaction Types:**
```php
// Savings
'savings_deposit'
'savings_withdrawal'
'savings_interest'
'savings_transfer'

// Subscriptions
'subscription_payment'
'subscription_reversal'

// Fees
'registration_fee_payment'
'passbook_fee_payment'

// Fines
'fine_assessment'
'fine_payment'

// Guarantor
'guarantor_lock' // Lock guarantor's savings
'guarantor_release' // Release guarantor's savings
```

### 8.2 Unified Transaction Service

**File:** `app/Services/TransactionService.php`

```php
<?php

namespace App\Services;

use App\Models\Transaction;
use Illuminate\Support\Facades\DB;

class TransactionService
{
    /**
     * Create double-entry transaction
     */
    public function createDoubleEntry(
        string $debitAccount,
        string $debitAccountNumber,
        string $creditAccount,
        string $creditAccountNumber,
        float $amount,
        string $transactionType,
        array $references = [],
        string $description = '',
        array $metadata = []
    ): array {
        return DB::transaction(function () use (
            $debitAccount,
            $debitAccountNumber,
            $creditAccount,
            $creditAccountNumber,
            $amount,
            $transactionType,
            $references,
            $description,
            $metadata
        ) {
            $baseData = [
                'transaction_type' => $transactionType,
                'amount' => $amount,
                'transaction_date' => now(),
                'description' => $description,
                'metadata' => json_encode($metadata),
            ];
            
            // Merge references (loan_id, member_id, savings_account_id, etc.)
            $baseData = array_merge($baseData, $references);
            
            // Debit transaction
            $debitTx = Transaction::create(array_merge($baseData, [
                'account_name' => $debitAccount,
                'account_number' => $debitAccountNumber,
                'dr_cr' => 'dr',
            ]));
            
            // Credit transaction
            $creditTx = Transaction::create(array_merge($baseData, [
                'account_name' => $creditAccount,
                'account_number' => $creditAccountNumber,
                'dr_cr' => 'cr',
            ]));
            
            return [$debitTx, $creditTx];
        });
    }
    
    /**
     * Reverse a transaction
     */
    public function reverseTransaction(Transaction $transaction, string $reason): array
    {
        // Create reversal with opposite dr_cr
        $reversalType = $transaction->transaction_type . '_reversal';
        $oppositeDrCr = $transaction->dr_cr === 'dr' ? 'cr' : 'dr';
        
        return Transaction::create([
            'account_name' => $transaction->account_name,
            'account_number' => $transaction->account_number,
            'loan_id' => $transaction->loan_id,
            'member_id' => $transaction->member_id,
            'savings_account_id' => $transaction->savings_account_id,
            'product_subscription_id' => $transaction->product_subscription_id,
            'transaction_type' => $reversalType,
            'dr_cr' => $oppositeDrCr,
            'amount' => $transaction->amount,
            'transaction_date' => now(),
            'description' => "Reversal: {$reason}. Original: {$transaction->description}",
            'reference_number' => $transaction->reference_number,
            'metadata' => json_encode(['reversed_transaction_id' => $transaction->id, 'reason' => $reason]),
        ]);
    }
}
```

### 8.3 Balance Calculation Abstraction

**File:** `app/Services/BalanceCalculationService.php`

```php
<?php

namespace App\Services;

use App\Models\Transaction;

class BalanceCalculationService
{
    /**
     * Calculate balance for an account
     * 
     * @param string $accountName
     * @param array $filters Additional filters (member_id, loan_id, etc.)
     * @param string $accountNature 'asset' or 'liability'
     */
    public function calculateBalance(
        string $accountName,
        array $filters = [],
        string $accountNature = 'asset'
    ): float {
        $query = Transaction::where('account_name', $accountName);
        
        // Apply filters
        foreach ($filters as $field => $value) {
            $query->where($field, $value);
        }
        
        $debits = (clone $query)->where('dr_cr', 'dr')->sum('amount');
        $credits = (clone $query)->where('dr_cr', 'cr')->sum('amount');
        
        // For assets: debit increases balance
        // For liabilities: credit increases balance
        return $accountNature === 'asset'
            ? $debits - $credits
            : $credits - $debits;
    }
}
```

---

## 9. Models and Relationships

### 9.1 New Model Files

#### 9.1.1 SaccoProduct Model
**File:** `app/Models/SaccoProduct.php`

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SaccoProduct extends Model
{
    protected $fillable = [
        'product_type_id', 'name', 'code', 'description',
        'is_active', 'is_mandatory', 'start_date', 'end_date'
    ];
    
    protected $casts = [
        'is_active' => 'boolean',
        'is_mandatory' => 'boolean',
        'start_date' => 'date',
        'end_date' => 'date',
    ];
    
    public function productType()
    {
        return $this->belongsTo(SaccoProductType::class);
    }
    
    public function attributeValues()
    {
        return $this->hasMany(SaccoProductAttributeValue::class);
    }
    
    public function chartOfAccounts()
    {
        return $this->hasMany(SaccoProductChartOfAccount::class);
    }
    
    public function subscriptions()
    {
        return $this->hasMany(MemberProductSubscription::class);
    }
    
    /**
     * Get attribute value by slug
     */
    public function getAttributeValue(string $slug)
    {
        $attributeValue = $this->attributeValues()
            ->whereHas('attribute', fn($q) => $q->where('slug', $slug))
            ->first();
        
        return $attributeValue?->value;
    }
    
    /**
     * Get account number for account type
     */
    public function getAccountNumber(string $accountType): ?string
    {
        return $this->chartOfAccounts()
            ->where('account_type', $accountType)
            ->first()?->account_number;
    }
    
    /**
     * Get account name for account type
     */
    public function getAccountName(string $accountType): ?string
    {
        $accountNumber = $this->getAccountNumber($accountType);
        if (!$accountNumber) return null;
        
        return ChartofAccounts::where('account_code', $accountNumber)->first()?->name;
    }
}
```

#### 9.1.2 MemberSavingsAccount Model
**File:** `app/Models/MemberSavingsAccount.php`

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MemberSavingsAccount extends Model
{
    protected $fillable = [
        'member_id', 'sacco_product_id', 'account_number',
        'opening_date', 'status', 'closed_date', 'notes'
    ];
    
    protected $casts = [
        'opening_date' => 'date',
        'closed_date' => 'date',
    ];
    
    public function member()
    {
        return $this->belongsTo(Member::class);
    }
    
    public function product()
    {
        return $this->belongsTo(SaccoProduct::class, 'sacco_product_id');
    }
    
    public function transactions()
    {
        return $this->hasMany(Transaction::class, 'savings_account_id');
    }
    
    /**
     * Get current balance
     */
    public function getBalanceAttribute(): float
    {
        return app(SavingsService::class)->getBalance($this);
    }
}
```

#### 9.1.3 MemberProductSubscription Model
**File:** `app/Models/MemberProductSubscription.php`

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MemberProductSubscription extends Model
{
    protected $fillable = [
        'member_id', 'sacco_product_id', 'subscription_date', 'start_date',
        'end_date', 'status', 'total_paid', 'total_expected', 'payment_count',
        'last_payment_date', 'next_payment_date', 'notes'
    ];
    
    protected $casts = [
        'subscription_date' => 'date',
        'start_date' => 'date',
        'end_date' => 'date',
        'last_payment_date' => 'date',
        'next_payment_date' => 'date',
        'total_paid' => 'decimal:2',
        'total_expected' => 'decimal:2',
    ];
    
    public function member()
    {
        return $this->belongsTo(Member::class);
    }
    
    public function product()
    {
        return $this->belongsTo(SaccoProduct::class, 'sacco_product_id');
    }
    
    public function transactions()
    {
        return $this->hasMany(Transaction::class, 'product_subscription_id');
    }
    
    /**
     * Get outstanding amount
     */
    public function getOutstandingAmountAttribute(): float
    {
        return max(0, $this->total_expected - $this->total_paid);
    }
    
    /**
     * Check if subscription is completed
     */
    public function getIsCompletedAttribute(): bool
    {
        return $this->total_paid >= $this->total_expected;
    }
}
```

#### 9.1.4 LoanGuarantor Model
**File:** `app/Models/LoanGuarantor.php`

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LoanGuarantor extends Model
{
    protected $fillable = [
        'loan_id', 'guarantor_member_id', 'guaranteed_amount',
        'guarantor_savings_at_guarantee', 'status', 'approved_at',
        'approved_by', 'rejection_reason'
    ];
    
    protected $casts = [
        'guaranteed_amount' => 'decimal:2',
        'guarantor_savings_at_guarantee' => 'decimal:2',
        'approved_at' => 'datetime',
    ];
    
    public function loan()
    {
        return $this->belongsTo(Loan::class);
    }
    
    public function guarantor()
    {
        return $this->belongsTo(Member::class, 'guarantor_member_id');
    }
    
    public function approver()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}
```

#### 9.1.5 LoanProductRule Model
**File:** `app/Models/LoanProductRule.php`

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LoanProductRule extends Model
{
    protected $fillable = [
        'loan_product_id', 'rule_type', 'rule_name',
        'rule_config', 'priority', 'is_active'
    ];
    
    protected $casts = [
        'rule_config' => 'array',
        'is_active' => 'boolean',
    ];
    
    public function loanProduct()
    {
        return $this->belongsTo(LoanProduct::class);
    }
}
```

### 9.2 Relationships to Add to Existing Models

#### Update Member Model
```php
// Add to app/Models/Member.php

public function savingsAccounts()
{
    return $this->hasMany(MemberSavingsAccount::class);
}

public function productSubscriptions()
{
    return $this->hasMany(MemberProductSubscription::class);
}

public function guaranteedLoans()
{
    return $this->hasMany(LoanGuarantor::class, 'guarantor_member_id');
}

/**
 * Get total savings across all accounts
 */
public function getTotalSavingsAttribute(): float
{
    return app(SavingsService::class)->getCumulativeSavings($this);
}
```

#### Update LoanProduct Model
```php
// Add to app/Models/LoanProduct.php

public function rules()
{
    return $this->hasMany(LoanProductRule::class);
}
```

#### Update Loan Model
```php
// Add to app/Models/Loan.php

public function guarantors()
{
    return $this->hasMany(LoanGuarantor::class);
}

/**
 * Check if loan has sufficient guarantors
 */
public function hasSufficientGuarantors(): bool
{
    $result = app(GuarantorService::class)->validateGuarantors($this);
    return $result['valid'];
}
```

---

## 10. Service Layer Architecture

### 10.1 Service Layer Design

All business logic should be in service classes, not in controllers or models.

**Service Hierarchy:**
```
TransactionService (base)
├── SavingsService
├── SubscriptionService
├── LoanEligibilityService
├── GuarantorService
└── FeeCalculationService

BalanceCalculationService (standalone)
RepaymentAllocationService (existing, no changes)
```

### 10.2 Service Provider Registration

**File:** `app/Providers/SaccoServiceProvider.php`

```php
<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use App\Services\{
    SavingsService,
    SubscriptionService,
    LoanEligibilityService,
    GuarantorService,
    FeeCalculationService,
    TransactionService,
    BalanceCalculationService
};

class SaccoServiceProvider extends ServiceProvider
{
    public function register()
    {
        $this->app->singleton(TransactionService::class);
        $this->app->singleton(BalanceCalculationService::class);
        $this->app->singleton(SavingsService::class);
        $this->app->singleton(SubscriptionService::class);
        $this->app->singleton(LoanEligibilityService::class);
        $this->app->singleton(GuarantorService::class);
        $this->app->singleton(FeeCalculationService::class);
    }
}
```

Register in `config/app.php`:
```php
'providers' => [
    // ...
    App\Providers\SaccoServiceProvider::class,
],
```

---

## 11. Backward Compatibility Strategy

### 11.1 Non-Breaking Changes

1. **New Tables Only:** All new functionality in new tables
2. **Nullable Foreign Keys:** `transactions` table additions are nullable
3. **Loan System Unchanged:** Existing loan approval, repayment logic untouched
4. **Config Fallbacks:** New account types fall back to config if not mapped

### 11.2 Data Migration for Existing Data

**No migration needed** for existing loans and transactions. They continue to work as-is.

**Optional migrations:**
1. Create savings accounts for existing members (one-time script)
2. Backfill `groups.formation_date` if missing (use `created_at` as fallback)

### 11.3 Testing Existing Functionality

Before deploying:
1. Run existing loan application and approval flows
2. Test existing loan repayment allocation
3. Verify existing interest accrual command
4. Check existing transaction reports

**All existing tests should pass without modification.**

---

## 12. Implementation Roadmap

### Phase 1: Foundation (Week 1-2)
**Goal:** Database schema and basic models

- [ ] Create all migrations (follow order in section 4.4)
- [ ] Run migrations on dev environment
- [ ] Create all model classes
- [ ] Add relationships to existing models
- [ ] Create seeders for product types and attributes
- [ ] Write unit tests for models

**Deliverables:**
- Working database schema
- All model files with relationships
- Passing model tests

### Phase 2: Member Savings (Week 3-4)
**Goal:** Full savings functionality

- [ ] Implement `SavingsService`
- [ ] Create Filament resource for `SaccoProduct`
- [ ] Create Filament page for savings deposits
- [ ] Create Filament page for savings withdrawals
- [ ] Implement balance calculation
- [ ] Create savings transaction reports
- [ ] Write integration tests

**Deliverables:**
- Working savings deposit/withdrawal
- Admin can view member savings balances
- Transaction reports include savings

### Phase 3: Subscription Products (Week 5-6)
**Goal:** Subscription management

- [ ] Implement `SubscriptionService`
- [ ] Implement `FeeCalculationService`
- [ ] Create Filament page for member subscriptions
- [ ] Create Filament page for subscription payments
- [ ] Implement subscription tracking dashboard
- [ ] Create automated reminders for overdue subscriptions
- [ ] Write integration tests

**Deliverables:**
- Members can subscribe to products
- Payments recorded and tracked
- Outstanding amounts calculated correctly

### Phase 4: Dynamic Loan Rules (Week 7-9)
**Goal:** Flexible loan product configuration

- [ ] Implement `LoanEligibilityService`
- [ ] Implement `GuarantorService`
- [ ] Create Filament resource for `LoanProductRule`
- [ ] Update loan application to check eligibility
- [ ] Update loan application to calculate max amount
- [ ] Update loan application to require guarantors
- [ ] Implement guarantor approval workflow
- [ ] Write extensive unit tests for rule engine

**Deliverables:**
- Loan products with complex rules (Starter, Advance, Long Term)
- Automatic eligibility validation
- Guarantor management system

### Phase 5: Integration & Testing (Week 10-11)
**Goal:** End-to-end workflows

- [ ] Create comprehensive E2E tests
- [ ] Test full member journey (join → save → apply → repay)
- [ ] Load testing with realistic data volumes
- [ ] Security audit
- [ ] Performance optimization

**Deliverables:**
- Passing E2E test suite
- Performance benchmarks met
- Security review complete

### Phase 6: Reporting & Analytics (Week 12)
**Goal:** Management reports

- [ ] Member savings summary report
- [ ] Product subscription status report
- [ ] Loan eligibility dashboard
- [ ] Financial statements (trial balance)
- [ ] Export functionality

**Deliverables:**
- Comprehensive reporting suite
- Data export capabilities

### Phase 7: Deployment & Training (Week 13-14)
**Goal:** Production launch

- [ ] Staging environment testing
- [ ] User acceptance testing
- [ ] User training materials
- [ ] Admin training sessions
- [ ] Production deployment
- [ ] Post-deployment monitoring

**Deliverables:**
- System live in production
- Trained users
- Documentation

---

## 13. Testing Strategy

### 13.1 Unit Tests

**Test Coverage Requirements:**
- All services: 100% method coverage
- All models: Relationship and accessor tests
- All rule evaluators: Edge case coverage

**Example Test:**
```php
// tests/Unit/Services/LoanEligibilityServiceTest.php

public function test_starter_loan_blocked_after_3_months()
{
    $group = Group::factory()->create(['formation_date' => now()->subMonths(4)]);
    $member = Member::factory()->create(['group_id' => $group->id]);
    $loanProduct = $this->createStarterLoanProduct();
    
    $result = $this->eligibilityService->checkEligibility($member, $loanProduct);
    
    $this->assertFalse($result['eligible']);
    $this->assertStringContainsString('first 3 months', $result['errors'][0]);
}
```

### 13.2 Integration Tests

Test complete workflows:
1. Member deposits → balance updates → loan eligibility changes
2. Member subscribes → pays → subscription completes
3. Loan application → eligibility check → guarantor validation → approval

### 13.3 Database Tests

- Test transaction integrity (every debit has matching credit)
- Test balance calculations match manual sums
- Test soft delete cascades

---

## 14. Security and Audit Considerations

### 14.1 Audit Trail

**Every financial transaction must be logged with:**
- Who initiated (user_id)
- When (timestamp)
- What changed (before/after values in metadata)
- Why (description, notes)

**Implement audit logging:**
```php
// Use Laravel's observer pattern
class TransactionObserver
{
    public function created(Transaction $transaction)
    {
        AuditLog::create([
            'user_id' => auth()->id(),
            'action' => 'transaction_created',
            'model_type' => Transaction::class,
            'model_id' => $transaction->id,
            'metadata' => $transaction->toJson(),
        ]);
    }
}
```

### 14.2 Authorization

Use Laravel policies for all financial operations:
- Only admins can create products
- Only authorized users can approve loans
- Members can only view their own data
- Guarantors must approve their guarantorship

### 14.3 Data Validation

- Validate all amounts are positive
- Prevent duplicate transactions (use DB transactions)
- Validate account mappings exist before creating transactions
- Prevent deletion of transactions (use soft deletes)

### 14.4 Compliance

- Maintain double-entry integrity
- Store all calculations in audit trail
- Implement maker-checker for sensitive operations
- Regular backup procedures

---

## Conclusion

This technical report provides a comprehensive blueprint for extending the TrustFund Loan Management System into a full-featured SACCO system. The proposed architecture:

✅ **Leverages existing patterns** (attributes, chart of accounts, transactions)  
✅ **Maintains backward compatibility** (no breaking changes)  
✅ **Supports complex business rules** (via rule engine)  
✅ **Provides audit trail** (transaction-based state)  
✅ **Enables scalability** (modular, service-oriented)  

**Key Success Factors:**
1. Follow the phased implementation roadmap
2. Write tests before implementing features
3. Keep business logic in service classes
4. Maintain the double-entry accounting principles
5. Validate with stakeholders at each phase

**Next Steps:**
1. Review and approve this technical report
2. Set up development environment
3. Begin Phase 1 (Foundation) implementation
4. Schedule regular progress reviews

---

**Document Version:** 1.0  
**Last Updated:** October 19, 2025  
**Review Status:** Pending Approval

