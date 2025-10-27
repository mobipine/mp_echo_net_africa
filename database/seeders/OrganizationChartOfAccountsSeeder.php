<?php

namespace Database\Seeders;

use App\Models\ChartofAccounts;
use Illuminate\Database\Seeder;

class OrganizationChartOfAccountsSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $accounts = [
            // ASSETS
            ['name' => 'Organization Bank Account', 'slug' => 'org-bank-account', 'account_code' => '1001', 'account_type' => 'Asset'],
            ['name' => 'Organization Cash Account', 'slug' => 'org-cash-account', 'account_code' => '1010', 'account_type' => 'Asset'],
            ['name' => 'Mobile Money Account', 'slug' => 'mobile-money-account', 'account_code' => '1020', 'account_type' => 'Asset'],
            ['name' => 'Capital Advances to Groups', 'slug' => 'capital-advances-to-groups', 'account_code' => '1201', 'account_type' => 'Asset'],
            ['name' => 'Other Receivables', 'slug' => 'other-receivables', 'account_code' => '1301', 'account_type' => 'Asset'],
            
            // Legacy loan accounts (for backward compatibility with existing loans)
            ['name' => 'Loans Receivable (Legacy)', 'slug' => 'loans-receivable-legacy', 'account_code' => '1101', 'account_type' => 'Asset'],
            ['name' => 'Interest Receivable (Legacy)', 'slug' => 'interest-receivable-legacy', 'account_code' => '1102', 'account_type' => 'Asset'],
            ['name' => 'Loan Charges Receivable (Legacy)', 'slug' => 'loan-charges-receivable-legacy', 'account_code' => '1103', 'account_type' => 'Asset'],
            
            // LIABILITIES
            ['name' => 'Organization Liabilities', 'slug' => 'org-liabilities', 'account_code' => '2101', 'account_type' => 'Liability'],
            ['name' => 'Member Savings (Legacy)', 'slug' => 'member-savings-legacy', 'account_code' => '2201', 'account_type' => 'Liability'],
            
            // EQUITY
            ['name' => 'Organization Capital', 'slug' => 'org-capital', 'account_code' => '3001', 'account_type' => 'Equity'],
            ['name' => 'Retained Earnings', 'slug' => 'retained-earnings', 'account_code' => '3101', 'account_type' => 'Equity'],
            
            // REVENUE
            ['name' => 'Management Fees from Groups', 'slug' => 'management-fees-from-groups', 'account_code' => '4001', 'account_type' => 'Revenue'],
            ['name' => 'Other Income', 'slug' => 'other-income', 'account_code' => '4002', 'account_type' => 'Revenue'],
            ['name' => 'Interest Income (Legacy)', 'slug' => 'interest-income-legacy', 'account_code' => '4101', 'account_type' => 'Revenue'],
            ['name' => 'Loan Charges Income (Legacy)', 'slug' => 'loan-charges-income-legacy', 'account_code' => '4102', 'account_type' => 'Revenue'],
            ['name' => 'Contribution Income (Legacy)', 'slug' => 'contribution-income-legacy', 'account_code' => '4201', 'account_type' => 'Revenue'],
            ['name' => 'Fee Income (Legacy)', 'slug' => 'fee-income-legacy', 'account_code' => '4202', 'account_type' => 'Revenue'],
            
            // EXPENSES
            ['name' => 'Administrative Expenses', 'slug' => 'administrative-expenses', 'account_code' => '5001', 'account_type' => 'Expense'],
            ['name' => 'Other Expenses', 'slug' => 'other-expenses', 'account_code' => '5002', 'account_type' => 'Expense'],
        ];
        
        foreach ($accounts as $account) {
            ChartofAccounts::updateOrCreate(
                ['account_code' => $account['account_code']],
                $account
            );
        }
        
        $this->command->info('Organization chart of accounts seeded successfully!');
    }
}

