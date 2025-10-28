<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\LoanAttribute;

class LoanAttributesSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $this->command->info('Seeding loan attributes...');

        $attributes = [
            [
                'name' => 'Interest Rate',
                'slug' => 'interest_rate',
                'type' => 'decimal',
                'options' => null,
                'is_required' => false,
            ],
            [
                'name' => 'Loan Charges',
                'slug' => 'loan_charges',
                'type' => 'decimal',
                'options' => null,
                'is_required' => false,
            ],
            [
                'name' => 'Max Loan Amount',
                'slug' => 'max_loan_amount',
                'type' => 'decimal',
                'options' => null,
                'is_required' => false,
            ],
            [
                'name' => 'Is Loan Attachments Required',
                'slug' => 'is_loan_attachments_required',
                'type' => 'boolean',
                'options' => null,
                'is_required' => false,
            ],
            [
                'name' => 'Is Guarantors Required',
                'slug' => 'is_guarantors_required',
                'type' => 'boolean',
                'options' => null,
                'is_required' => false,
            ],
            [
                'name' => 'Is Collaterals Required',
                'slug' => 'is_collaterals_required',
                'type' => 'boolean',
                'options' => null,
                'is_required' => false,
            ],
            [
                'name' => 'Attachments Required',
                'slug' => 'attachments_required',
                'type' => 'file',
                'options' => null,
                'is_required' => false,
            ],
            [
                'name' => 'Interest Cycle',
                'slug' => 'interest_cycle',
                'type' => 'select',
                'options' => 'Daily,Weekly,Monthly,Yearly',
                'is_required' => false,
            ],
            [
                'name' => 'Interest Type',
                'slug' => 'interest_type',
                'type' => 'select',
                'options' => 'Simple,ReducingBalance,Flat',
                'is_required' => false,
            ],
            [
                'name' => 'Interest Accrual Moment',
                'slug' => 'interest_accrual_moment',
                'type' => 'select',
                'options' => 'Loan issue,After First Cycle',
                'is_required' => false,
            ],
        ];

        foreach ($attributes as $attribute) {
            LoanAttribute::updateOrCreate(
                ['slug' => $attribute['slug']],
                $attribute
            );
        }

        $this->command->info('✓ Loan attributes seeded successfully!');
        $this->command->info('  Total attributes: ' . count($attributes));
        $this->command->info("\nAttributes created:");
        foreach ($attributes as $attr) {
            $this->command->info("  • {$attr['name']} ({$attr['slug']}) - {$attr['type']}");
        }
    }
}

