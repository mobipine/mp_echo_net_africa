<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\{SaccoProduct, SaccoProductType, SaccoProductAttribute, ChartofAccounts};

class SaccoProductExamplesSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $this->command->info('Creating example SACCO products...');
        
        $this->createMainSavingsProduct();
        $this->createRiskFundProduct();
        $this->createRegistrationFeeProduct();
        
        $this->command->info('Example products created successfully!');
    }
    
    /**
     * Create Main Savings Account product
     */
    private function createMainSavingsProduct(): void
    {
        $savingsType = SaccoProductType::where('slug', 'member-savings')->first();
        
        if (!$savingsType) {
            $this->command->warn('Member savings product type not found. Run SaccoInitialDataSeeder first.');
            return;
        }
        
        $product = SaccoProduct::updateOrCreate(
            ['code' => 'MAIN_SAVINGS'],
            [
                'product_type_id' => $savingsType->id,
                'name' => 'Member Main Savings',
                'description' => 'Primary savings account for all members',
                'is_active' => true,
                'is_mandatory' => true,
            ]
        );
        
        // Set attributes
        $allowsWithdrawalAttr = SaccoProductAttribute::where('slug', 'allows_withdrawal')->first();
        $minDepositAttr = SaccoProductAttribute::where('slug', 'minimum_deposit')->first();
        
        if ($allowsWithdrawalAttr) {
            $product->attributeValues()->updateOrCreate(
                ['attribute_id' => $allowsWithdrawalAttr->id],
                ['value' => 'true']
            );
        }
        
        if ($minDepositAttr) {
            $product->attributeValues()->updateOrCreate(
                ['attribute_id' => $minDepositAttr->id],
                ['value' => '100']
            );
        }
        
        // Map to chart of accounts (if they exist)
        $this->mapChartOfAccounts($product, [
            'bank' => '1001',
            'savings_account' => '2201',
        ]);
        
        $this->command->info(' ✓ Main Savings Account created');
    }
    
    /**
     * Create Risk Fund subscription product
     */
    private function createRiskFundProduct(): void
    {
        $subscriptionType = SaccoProductType::where('slug', 'subscription-product')->first();
        
        if (!$subscriptionType) {
            $this->command->warn('Subscription product type not found.');
            return;
        }
        
        $product = SaccoProduct::updateOrCreate(
            ['code' => 'RISK_FUND'],
            [
                'product_type_id' => $subscriptionType->id,
                'name' => 'Risk Fund',
                'description' => 'Monthly risk fund contribution (Ksh 30/month for 12 months)',
                'is_active' => true,
                'is_mandatory' => true,
            ]
        );
        
        // Set attributes
        $attributes = [
            'payment_frequency' => 'monthly',
            'amount_per_cycle' => '30',
            'total_cycles' => '12',
            'max_total_amount' => '360',
        ];
        
        foreach ($attributes as $slug => $value) {
            $attr = SaccoProductAttribute::where('slug', $slug)->first();
            if ($attr) {
                $product->attributeValues()->updateOrCreate(
                    ['attribute_id' => $attr->id],
                    ['value' => $value]
                );
            }
        }
        
        // Map to chart of accounts
        $this->mapChartOfAccounts($product, [
            'bank' => '1001',
            'contribution_receivable' => '1301',
            'contribution_income' => '4201',
        ]);
        
        $this->command->info(' ✓ Risk Fund created');
    }
    
    /**
     * Create Registration Fee product
     */
    private function createRegistrationFeeProduct(): void
    {
        $feeType = SaccoProductType::where('slug', 'one-time-fee')->first();
        
        if (!$feeType) {
            $this->command->warn('One-time fee product type not found.');
            return;
        }
        
        $product = SaccoProduct::updateOrCreate(
            ['code' => 'REG_FEE'],
            [
                'product_type_id' => $feeType->id,
                'name' => 'Registration Fee',
                'description' => 'One-time registration fee (starts at Ksh 300, increases by Ksh 50/month, max 3000)',
                'is_active' => true,
                'is_mandatory' => true,
            ]
        );
        
        // Set calculation formula
        $formulaAttr = SaccoProductAttribute::where('slug', 'calculation_formula')->first();
        if ($formulaAttr) {
            $formula = [
                'type' => 'escalating',
                'base_amount' => 300,
                'increment_amount' => 50,
                'increment_frequency' => 'monthly',
                'max_amount' => 3000,
                'launch_date' => '2025-01-01',
            ];
            
            $product->attributeValues()->updateOrCreate(
                ['attribute_id' => $formulaAttr->id],
                ['value' => json_encode($formula)]
            );
        }
        
        // Map to chart of accounts
        $this->mapChartOfAccounts($product, [
            'bank' => '1001',
            'fee_receivable' => '1302',
            'fee_income' => '4202',
        ]);
        
        $this->command->info(' ✓ Registration Fee created');
    }
    
    /**
     * Map product to chart of accounts
     */
    private function mapChartOfAccounts(SaccoProduct $product, array $mappings): void
    {
        foreach ($mappings as $accountType => $accountCode) {
            // Check if account exists
            $account = ChartofAccounts::where('account_code', $accountCode)->first();
            
            if ($account) {
                $product->chartOfAccounts()->updateOrCreate(
                    ['account_type' => $accountType],
                    ['account_number' => $accountCode]
                );
            } else {
                $this->command->warn("   ⚠ Account {$accountCode} not found for {$accountType}");
            }
        }
    }
}
