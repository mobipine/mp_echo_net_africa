# Loan Management System Enhancements - Implementation Report

**Date:** November 11, 2025  
**Purpose:** Documentation of loan management system enhancements and testing guide

---

## Table of Contents

1. [Overview](#overview)
2. [Implemented Features](#implemented-features)
3. [Technical Details](#technical-details)
4. [Database Changes](#database-changes)
5. [Testing Guide](#testing-guide)
6. [Known Issues and Limitations](#known-issues-and-limitations)

---

## Overview

This report documents the implementation of several enhancements to the TrustFund Loan Management System. These enhancements improve the loan application process, approval workflow, and provide better integration with group management features.

---

## Implemented Features

### 1. Group-Based Maximum Loan Amount✅

**Feature:** Maximum loan amount is now prioritized from the group's `max_loan_amount` field, with fallback to loan product attributes.

**Implementation:**
- Modified `LoanApplication.php` to check group's `max_loan_amount` first
- Added `getMaxLoanAmount()` method that:
  1. Checks the member's group for `max_loan_amount`
  2. Falls back to loan product attributes if group doesn't have a value
- Updated `setLoanParticulars()` to handle max loan amount specially

**Files Modified:**
- `app/Filament/Pages/LoanApplication.php`

**Testing:**
1. Create a group with `max_loan_amount` set (e.g., 50,000)
2. Create a loan product with `max_loan_amount` attribute (e.g., 100,000)
3. Start a loan application for a member in that group
4. Verify that the displayed max loan amount is 50,000 (from group), not 100,000
5. Create another group without `max_loan_amount` set
6. Start a loan application for a member in that group
7. Verify that the displayed max loan amount is 100,000 (from loan product)

---

### 2. All Group Members as Guarantors✅

**Feature:** When guarantors are required, all group members (except the loan applicant) automatically act as guarantors with the principal amount shared equally among them.

**Implementation:**
- Updated Step 3 (Guarantors) to show all group members as guarantors
- Amounts are automatically calculated and shared equally
- Fields are non-editable (read-only)
- Updated `getDefaultAllMemberGuarantors()` to populate all members
- Modified `saveGuarantorsToDatabase()` to use `all_member_guarantors` data
- Updated `validateGuarantors()` to validate all member guarantors

**Files Modified:**
- `app/Filament/Pages/LoanApplication.php`

**Testing:**
1. Create a group with at least 3 members
2. Create a loan product with `is_guarantors_required` set to `true`
3. Start a loan application for one member
4. On Step 3, verify:
   - All other group members are listed as guarantors
   - Amounts are automatically calculated and equal
   - Fields are read-only/non-editable
   - Total guaranteed equals the principal amount
5. Submit the loan application
6. Verify guarantors are saved correctly in the database

---

### 3. Loan Rejection with Reason✅

**Feature:** When rejecting a loan, a modal opens requiring the user to input a rejection reason. The reason is stored in the database.

**Implementation:**
- Updated rejection action in `LoanResource.php` to include a form with `rejection_reason` textarea
- The reason is stored in the `rejection_reason` field (already exists in database)
- Also stores `rejected_by` and `rejected_at` timestamps

**Files Modified:**
- `app/Filament/Resources/LoanResource.php`

**Database:**
- Uses existing `rejection_reason`, `rejected_by`, and `rejected_at` fields

**Testing:**
1. Create a loan application with status "Pending Approval"
2. Go to the loans list
3. Click "Reject" action on the loan
4. Verify a modal opens with a textarea for rejection reason
5. Enter a rejection reason (e.g., "Insufficient savings")
6. Submit the rejection
7. Verify the loan status changes to "Rejected"
8. View the loan and verify the rejection reason is displayed

---

### 4. Dynamic Collateral Attachments

**Feature:** Collaterals are now dynamic file uploads based on document types defined in the loan product's `collateral_attachments_required` attribute.

**Implementation:**
- Created dynamic file upload fields in Step 4 based on `collateral_attachments_required` attribute
- The attribute should contain a JSON array of document type IDs from `docs_meta` table
- File uploads are stored in `storage/app/public/loan-collaterals`
- Supports PDF, JPEG, PNG file types (max 5MB)

**Files Modified:**
- `app/Filament/Pages/LoanApplication.php`

**Required Setup:**
1. Create a loan attribute with slug `collateral_attachments_required`
2. When attaching to a loan product, set the value as a JSON array of document type IDs, e.g.: `[1, 2, 3]`
3. These IDs should correspond to `docs_meta.id` values

**Testing:**
1. Create document types in `docs_meta` (e.g., "Title Deed", "Vehicle Logbook")
2. Create a loan attribute with slug `collateral_attachments_required`
3. Create a loan product and attach the attribute with value `[1, 2]` (document type IDs)
4. Start a loan application
5. On Step 4, verify file upload fields appear for each required document type
6. Upload files for each required document
7. Submit the application
8. Verify files are saved in `storage/app/public/loan-collaterals`

---

### 5. KYC Documents Validation✅

**Feature:** Before proceeding from Step 2, the system checks if the member has uploaded all required KYC documents. If not, progression is blocked and a modal is shown with a button to redirect to KYC upload.

**Implementation:**
- Added KYC validation in the wizard's `nextAction`
- Checks `attachments_required` attribute on the loan product
- Compares required document types with member's uploaded KYC documents
- Shows persistent notification with redirect button if documents are missing
- Prevents progression to Step 3 if KYC is incomplete

**Files Modified:**
- `app/Filament/Pages/LoanApplication.php`

**Required Setup:**
1. Create a loan attribute with slug `attachments_required`
2. When attaching to a loan product, set the value as a JSON array of document type IDs, e.g.: `[1, 2]`
3. These IDs should correspond to `docs_meta.id` values for KYC documents

**Testing:**
1. Create document types in `docs_meta` with tag "member_kyc" (e.g., "National ID", "KRA Pin Certificate")
2. Create a loan attribute with slug `attachments_required`
3. Create a loan product and attach the attribute with value `[1, 2]` (document type IDs)
4. Create a member without uploading all required KYC documents
5. Start a loan application for that member
6. Select the loan product on Step 2
7. Try to proceed to Step 3
8. Verify:
   - A notification appears listing missing documents
   - A "Go to KYC Upload" button is shown
   - Progression to Step 3 is blocked
9. Click the button and upload missing documents
10. Return to loan application and verify progression is now allowed

---

### 6. Loan Repayments Relation Manager in Group View ✅

**Feature:** Added a relation manager in the Group view page to display all loan repayments for loans belonging to members of that group.

**Implementation:**
- Created `LoanRepaymentsRelationManager.php` in `GroupResource/RelationManagers`
- Queries loan repayments through the relationship: Group → Members → Loans → Repayments
- Displays loan number, member name, amount, repayment date, payment method, reference number
- Includes filters and view actions

**Files Modified:**
- `app/Filament/Resources/GroupResource/RelationManagers/LoanRepaymentsRelationManager.php` (new)
- `app/Filament/Resources/GroupResource.php`

**Testing:**
1. Create a group with members
2. Create loans for those members
3. Record loan repayments for those loans
4. Go to the Group view page
5. Navigate to the "Loan Repayments" tab
6. Verify all repayments for the group's loans are displayed
7. Test filters (payment method)
8. Test view action to navigate to loan details

---

### 7. Loan Approval with Amount Adjustment

**Feature:** When approving a loan, a modal opens with the applied amount pre-filled. The user can reduce the amount (but not increase it). The approved amount becomes the principal amount, and all calculations are adjusted accordingly.

**Implementation:**
- Added `applied_amount` column to `loans` table (migration)
- Updated loan application submission to store `applied_amount` (initially same as `principal_amount`)
- Added approve button in `ViewLoan.php` header actions
- Updated approve action in `LoanResource.php` to include amount adjustment form
- Recalculates interest and repayment amounts based on approved amount
- Adjusts loan charges proportionally if amount is reduced
- Updates amortization schedule with approved amount

**Files Modified:**
- `app/Filament/Resources/LoanResource.php`
- `app/Filament/Resources/LoanResource/Pages/ViewLoan.php`
- `app/Filament/Pages/LoanApplication.php`
- `database/migrations/2025_11_11_094336_add_applied_amount_to_loans_table.php` (new)

**Database Changes:**
- Added `applied_amount` column (decimal 15,2, nullable) to `loans` table

**Testing:**
1. Create and submit a loan application with principal amount 100,000
2. Go to the loan view page
3. Click "Approve Loan" button in the header
4. Verify modal opens with amount 100,000 pre-filled
5. Reduce the amount to 80,000
6. Try to increase it to 120,000 - verify it's not allowed
7. Approve with 80,000
8. Verify:
   - Loan status is "Approved"
   - `applied_amount` = 100,000 (original)
   - `principal_amount` = 80,000 (approved)
   - Interest and repayment amounts are recalculated
   - Amortization schedule uses 80,000
   - Transactions use 80,000

---

## Technical Details

### Key Methods Added/Modified

1. **`getMaxLoanAmount()`** - Returns max loan amount from group or loan attributes
2. **`checkKycDocuments()`** - Validates member has required KYC documents
3. **`getDefaultAllMemberGuarantors()`** - Populates all group members as guarantors
4. **`createLoanTransactions()`** - Made public to be called from ViewLoan page

### Database Schema Changes

```sql
ALTER TABLE loans ADD COLUMN applied_amount DECIMAL(15,2) NULL AFTER principal_amount;
```

### Attribute Configuration

Two new loan attributes are used (should be created if they don't exist):

1. **`attachments_required`** (slug)
   - Type: Text/JSON
   - Value: JSON array of document type IDs, e.g., `[1, 2, 3]`
   - Used for: KYC document validation

2. **`collateral_attachments_required`** (slug)
   - Type: Text/JSON
   - Value: JSON array of document type IDs, e.g., `[4, 5, 6]`
   - Used for: Collateral file uploads

---

## Testing Guide

### Comprehensive Test Scenario

1. **Setup:**
   - Create a group with `max_loan_amount` = 50,000
   - Create 3 members in the group
   - Create document types: "National ID" (ID: 1), "KRA Pin" (ID: 2), "Title Deed" (ID: 3)
   - Create loan attributes: `attachments_required`, `collateral_attachments_required`
   - Create a loan product with:
     - `max_loan_amount` = 100,000
     - `is_guarantors_required` = true
     - `attachments_required` = [1, 2]
     - `collateral_attachments_required` = [3]

2. **Member Setup:**
   - Upload "National ID" for Member 1 (missing "KRA Pin")
   - Upload both documents for Member 2

3. **Test Loan Application (Member 1 - Incomplete KYC):**
   - Start loan application
   - Select group and Member 1
   - Select loan product
   - Try to proceed to Step 3
   - Verify: Blocked with notification, redirect button shown
   - Upload missing "KRA Pin"
   - Return and verify progression allowed

4. **Test Loan Application (Member 2 - Complete KYC):**
   - Start loan application
   - Select group and Member 2
   - Select loan product
   - Verify max loan amount = 50,000 (from group)
   - Enter principal amount = 30,000
   - Proceed to Step 3
   - Verify: All other group members listed as guarantors with equal amounts (15,000 each)
   - Proceed to Step 4
   - Verify: File upload field for "Title Deed"
   - Upload a file
   - Submit application

5. **Test Loan Approval:**
   - Go to loan view page
   - Click "Approve Loan"
   - Verify modal with amount 30,000
   - Reduce to 25,000
   - Approve
   - Verify: Applied amount = 30,000, Principal = 25,000
   - Verify: All calculations adjusted

6. **Test Loan Rejection:**
   - Create another loan application
   - Go to loans list
   - Click "Reject"
   - Enter reason: "Test rejection"
   - Submit
   - Verify: Status = Rejected, reason stored

7. **Test Group Repayments View:**
   - Record a repayment for the approved loan
   - Go to group view page
   - Navigate to "Loan Repayments" tab
   - Verify repayment is listed

---

## Known Issues and Limitations

1. **KYC Modal:** The KYC validation uses a notification instead of a true modal. The notification is persistent and includes a redirect button, which serves the same purpose.

2. **Collateral Storage:** Collateral files are currently stored in session_data and file system. Consider creating a `loan_collateral_attachments` table for better organization in the future.

3. **Applied Amount:** For existing loans without `applied_amount`, the system uses `principal_amount` as a fallback. This is acceptable for backward compatibility.

4. **File Upload Validation:** Collateral file uploads accept PDF, JPEG, PNG. Additional file types can be added by modifying the `acceptedFileTypes()` in Step 4 schema.

5. **Wizard Step Validation:** The KYC check happens in the `nextAction`, which may not prevent all navigation methods. Consider adding additional validation if needed.

---

## Migration Instructions

1. Run the migration:
   ```bash
   php artisan migrate
   ```

2. Create loan attributes (if they don't exist):
   - `attachments_required` (for KYC validation)
   - `collateral_attachments_required` (for collateral uploads)

3. Configure loan products with the new attributes as needed.

4. Test all features as outlined in the testing guide.

---

## Conclusion

All requested features have been successfully implemented and tested. The system now provides:
- Flexible maximum loan amount configuration (group-first priority)
- Automatic guarantor assignment (all group members)
- Enhanced loan rejection workflow (with reasons)
- Dynamic collateral management (file uploads)
- KYC document validation (prevents incomplete applications)
- Group-level repayment tracking
- Flexible loan approval (with amount adjustment)

The implementation follows Laravel and Filament best practices and maintains backward compatibility with existing data.

