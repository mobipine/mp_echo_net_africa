<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\SaccoProductType;
use App\Models\SaccoProductAttribute;
use App\Models\ChartofAccounts;

class SaccoInitialDataSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $this->command->info('Seeding SACCO chart of accounts...');
        $this->seedChartOfAccounts();
        
        $this->command->info('Seeding SACCO product types...');
        $this->seedProductTypes();
        
        $this->command->info('Seeding SACCO product attributes...');
        $this->seedProductAttributes();
        
        $this->command->info('SACCO initial data seeded successfully!');
    }
    
    /**
     * Seed chart of accounts for SACCO operations
     */
    private function seedChartOfAccounts(): void
    {
        $accounts = [
            // Assets
            ['name' => 'Bank Account', 'slug' => 'bank-account', 'account_code' => '1001', 'account_type' => 'Asset'],
            ['name' => 'Cash Account', 'slug' => 'cash-account', 'account_code' => '1010', 'account_type' => 'Asset'],
            ['name' => 'Mobile Money', 'slug' => 'mobile-money', 'account_code' => '1020', 'account_type' => 'Asset'],
            ['name' => 'Contribution Receivable', 'slug' => 'contribution-receivable', 'account_code' => '1301', 'account_type' => 'Asset'],
            ['name' => 'Fee Receivable', 'slug' => 'fee-receivable', 'account_code' => '1302', 'account_type' => 'Asset'],
            
            // Liabilities
            ['name' => 'Member Savings', 'slug' => 'member-savings', 'account_code' => '2201', 'account_type' => 'Liability'],
            
            // Revenue
            ['name' => 'Contribution Income', 'slug' => 'contribution-income', 'account_code' => '4201', 'account_type' => 'Revenue'],
            ['name' => 'Fee Income', 'slug' => 'fee-income', 'account_code' => '4202', 'account_type' => 'Revenue'],
            ['name' => 'Fine Income', 'slug' => 'fine-income', 'account_code' => '4203', 'account_type' => 'Revenue'],
            
            // Expenses
            ['name' => 'Savings Interest', 'slug' => 'savings-interest', 'account_code' => '5001', 'account_type' => 'Expense'],
        ];
        
        foreach ($accounts as $account) {
            ChartofAccounts::updateOrCreate(
                ['account_code' => $account['account_code']],
                $account
            );
        }
    }
    
    /**
     * Seed product types
     */
    private function seedProductTypes(): void
    {
        $productTypes = [
            [
                'name' => 'Member Savings',
                'slug' => 'member-savings',
                'description' => 'Savings products for members',
                'category' => 'savings',
            ],
            [
                'name' => 'Subscription Product',
                'slug' => 'subscription-product',
                'description' => 'Recurring contribution products',
                'category' => 'subscription',
            ],
            [
                'name' => 'One-Time Fee',
                'slug' => 'one-time-fee',
                'description' => 'Single payment fees',
                'category' => 'fee',
            ],
            [
                'name' => 'Penalty/Fine',
                'slug' => 'penalty-fine',
                'description' => 'Penalties and fines',
                'category' => 'fine',
            ],
        ];
        
        foreach ($productTypes as $type) {
            SaccoProductType::updateOrCreate(
                ['slug' => $type['slug']],
                $type
            );
        }
    }
    
    /**
     * Seed product attributes
     */
    private function seedProductAttributes(): void
    {
        $attributes = [
            // Subscription product attributes
            [
                'name' => 'Payment Frequency',
                'slug' => 'payment_frequency',
                'type' => 'select',
                'options' => json_encode(['daily', 'weekly', 'monthly', 'quarterly', 'yearly']),
                'description' => 'How often payments are made',
                'applicable_product_types' => json_encode(['subscription-product']),
                'is_required' => true,
            ],
            [
                'name' => 'Amount Per Cycle',
                'slug' => 'amount_per_cycle',
                'type' => 'decimal',
                'description' => 'Amount to pay per cycle',
                'applicable_product_types' => json_encode(['subscription-product']),
                'is_required' => true,
            ],
            [
                'name' => 'Total Cycles',
                'slug' => 'total_cycles',
                'type' => 'integer',
                'description' => 'Total number of payment cycles',
                'applicable_product_types' => json_encode(['subscription-product']),
                'is_required' => false,
            ],
            [
                'name' => 'Max Total Amount',
                'slug' => 'max_total_amount',
                'type' => 'decimal',
                'description' => 'Maximum total amount to be paid',
                'applicable_product_types' => json_encode(['subscription-product']),
                'is_required' => false,
            ],
            
            // Savings product attributes
            [
                'name' => 'Minimum Deposit',
                'slug' => 'minimum_deposit',
                'type' => 'decimal',
                'description' => 'Minimum amount that can be deposited',
                'applicable_product_types' => json_encode(['member-savings']),
                'is_required' => false,
                'default_value' => '0',
            ],
            [
                'name' => 'Maximum Deposit',
                'slug' => 'maximum_deposit',
                'type' => 'decimal',
                'description' => 'Maximum amount that can be deposited',
                'applicable_product_types' => json_encode(['member-savings']),
                'is_required' => false,
            ],
            [
                'name' => 'Allows Withdrawal',
                'slug' => 'allows_withdrawal',
                'type' => 'boolean',
                'description' => 'Whether withdrawals are allowed',
                'applicable_product_types' => json_encode(['member-savings']),
                'is_required' => true,
                'default_value' => 'true',
            ],
            [
                'name' => 'Savings Interest Rate',
                'slug' => 'savings_interest_rate',
                'type' => 'decimal',
                'description' => 'Annual interest rate for savings (%)',
                'applicable_product_types' => json_encode(['member-savings']),
                'is_required' => false,
            ],
            
            // Fee product attributes
            [
                'name' => 'Calculation Formula',
                'slug' => 'calculation_formula',
                'type' => 'json',
                'description' => 'JSON formula for calculating dynamic fees',
                'applicable_product_types' => json_encode(['one-time-fee']),
                'is_required' => false,
            ],
            [
                'name' => 'Fixed Amount',
                'slug' => 'fixed_amount',
                'type' => 'decimal',
                'description' => 'Fixed fee amount',
                'applicable_product_types' => json_encode(['one-time-fee', 'penalty-fine']),
                'is_required' => false,
            ],
        ];
        
        foreach ($attributes as $attr) {
            SaccoProductAttribute::updateOrCreate(
                ['slug' => $attr['slug']],
                $attr
            );
        }
    }
}
