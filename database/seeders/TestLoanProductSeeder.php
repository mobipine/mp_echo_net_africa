<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\LoanProduct;
use App\Models\LoanAttribute;
use App\Models\LoanProductAttribute;
use App\Models\LoanProductChartOfAccount;
use App\Models\ChartofAccounts;

class TestLoanProductSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $this->command->info('Creating test loan product (0% interest)...');

        // Create the loan product
        $loanProduct = LoanProduct::updateOrCreate(
            ['name' => 'Emergency Loan (0% Interest)'],
            [
                'description' => 'Interest-free emergency loan for testing purposes. Up to KES 50,000 for 12 months.',
                'is_active' => true,
            ]
        );

        $this->command->info("✓ Created loan product: {$loanProduct->name} (ID: {$loanProduct->id})");

        // Set up attributes (only using the simplified set)
        $attributes = [
            'interest_rate' => '0',
            'loan_charges' => '0',
            'max_loan_amount' => '50000',
            'is_loan_attachments_required' => 'false',
            'is_guarantors_required' => 'false',
            'is_collaterals_required' => 'false',
            'interest_cycle' => 'Monthly',
            'interest_type' => 'Flat',
            'interest_accrual_moment' => 'Loan issue',
        ];

        $this->command->info('  Setting loan attributes...');
        foreach ($attributes as $slug => $value) {
            $loanAttribute = LoanAttribute::where('slug', $slug)->first();
            
            if ($loanAttribute) {
                LoanProductAttribute::updateOrCreate(
                    [
                        'loan_product_id' => $loanProduct->id,
                        'loan_attribute_id' => $loanAttribute->id,
                    ],
                    [
                        'value' => $value,
                        'order' => 0,
                    ]
                );
                $this->command->info("    ✓ {$loanAttribute->name}: {$value}");
            } else {
                $this->command->warn("    ⚠ Attribute '{$slug}' not found. Run LoanAttributesSeeder first.");
            }
        }

        // Map to chart of accounts
        $this->command->info('  Mapping to chart of accounts...');
        $this->mapChartOfAccounts($loanProduct);

        $this->command->info("\n✓ Test loan product created successfully!");
        $this->command->info("  You can now test loan applications with this product.");
    }

    /**
     * Map loan product to chart of accounts
     */
    private function mapChartOfAccounts(LoanProduct $loanProduct): void
    {
        // These should correspond to organization-level accounts
        // Note: The actual loan transactions will use GROUP accounts (G1-1101, G1-1001, etc.)
        // These mappings are for reference only in the loan product definition
        $accountMappings = [
            'loans_receivable' => '1101',
            'interest_receivable' => '1102',
            'loan_charges_receivable' => '1103',
            // 'bank' => '1000', // Skip this - we'll use group bank accounts directly
            'interest_income' => '4101',
            'loan_charges_income' => '4102',
        ];

        foreach ($accountMappings as $accountType => $accountCode) {
            $account = ChartofAccounts::where('account_code', $accountCode)->first();
            
            if ($account) {
                LoanProductChartOfAccount::updateOrCreate(
                    [
                        'loan_product_id' => $loanProduct->id,
                        'account_type' => $accountType,
                    ],
                    [
                        'account_number' => $accountCode,
                    ]
                );
                $this->command->info("    ✓ {$accountType}: {$account->name} ({$accountCode})");
            } else {
                $this->command->warn("    ⚠ Account {$accountCode} not found for {$accountType}");
            }
        }
    }
}

