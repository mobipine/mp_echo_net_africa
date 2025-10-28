<?php

namespace App\Observers;

use App\Models\Group;
use App\Models\GroupAccount;
use Illuminate\Support\Facades\Log;

class GroupObserver
{
    /**
     * Handle the Group "created" event.
     * Automatically create group accounts when a new group is created.
     */
    public function created(Group $group): void
    {
        $this->createGroupAccounts($group);
    }

    /**
     * Create all necessary accounts for a group
     */
    private function createGroupAccounts(Group $group): void
    {
        $accountTemplates = [
            // Assets
            [
                'code_suffix' => '1001',
                'name' => 'Bank Account',
                'type' => 'group_bank',
                'nature' => 'asset',
                'parent' => '1001'
            ],
            [
                'code_suffix' => '1101',
                'name' => 'Loans Receivable',
                'type' => 'group_loans_receivable',
                'nature' => 'asset',
                'parent' => null
            ],
            [
                'code_suffix' => '1102',
                'name' => 'Interest Receivable',
                'type' => 'group_interest_receivable',
                'nature' => 'asset',
                'parent' => null
            ],
            [
                'code_suffix' => '1103',
                'name' => 'Loan Charges Receivable',
                'type' => 'group_loan_charges_receivable',
                'nature' => 'asset',
                'parent' => null
            ],
            
            // Liabilities
            [
                'code_suffix' => '2201',
                'name' => 'Member Savings',
                'type' => 'group_member_savings',
                'nature' => 'liability',
                'parent' => null
            ],
            [
                'code_suffix' => '2202',
                'name' => 'Contribution Liability',
                'type' => 'group_contribution_liability',
                'nature' => 'liability',
                'parent' => null
            ],
            [
                'code_suffix' => '2301',
                'name' => 'Capital Payable to Organization',
                'type' => 'group_capital_payable',
                'nature' => 'liability',
                'parent' => null
            ],
            
            // Revenue
            [
                'code_suffix' => '4101',
                'name' => 'Interest Income',
                'type' => 'group_interest_income',
                'nature' => 'revenue',
                'parent' => null
            ],
            [
                'code_suffix' => '4102',
                'name' => 'Loan Charges Income',
                'type' => 'group_loan_charges_income',
                'nature' => 'revenue',
                'parent' => null
            ],
            [
                'code_suffix' => '4201',
                'name' => 'Contribution Income',
                'type' => 'group_contribution_income',
                'nature' => 'revenue',
                'parent' => null
            ],
            
            // Expenses
            [
                'code_suffix' => '5001',
                'name' => 'Savings Interest Expense',
                'type' => 'group_savings_interest_expense',
                'nature' => 'expense',
                'parent' => null
            ],
        ];

        foreach ($accountTemplates as $template) {
            GroupAccount::create([
                'group_id' => $group->id,
                'account_code' => "G{$group->id}-{$template['code_suffix']}",
                'account_name' => "{$group->name} - {$template['name']}",
                'account_type' => $template['type'],
                'account_nature' => $template['nature'],
                'parent_account_code' => $template['parent'],
                'is_active' => true,
                'opening_balance' => 0.00,
                'opening_date' => now(),
            ]);
        }

        Log::info("Created group accounts for group: {$group->name} (ID: {$group->id})");
    }

    /**
     * Handle the Group "deleted" event.
     */
    public function deleted(Group $group): void
    {
        // Group accounts will be deleted automatically via cascade
        Log::info("Group deleted: {$group->name} (ID: {$group->id}). Accounts will be cascaded.");
    }
}

