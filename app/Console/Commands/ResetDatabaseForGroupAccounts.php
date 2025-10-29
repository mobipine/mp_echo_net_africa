<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ResetDatabaseForGroupAccounts extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'db:reset-for-group-accounts 
                            {--force : Force the operation without confirmation}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Clear all data and reset database for group accounts implementation';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        if (!$this->option('force')) {
            if (!$this->confirm('This will delete ALL data (members, groups, loans, transactions, etc.). Continue?')) {
                $this->info('Operation cancelled.');
                return 0;
            }
        }

        $this->info('Starting database reset...');
        $this->newLine();

        // Disable foreign key checks
        DB::statement('SET FOREIGN_KEY_CHECKS=0');

        // Step 1: Truncate transaction-related tables
        $this->info('1. Clearing transactions...');
        $this->truncateTable('transactions');
        $this->truncateTable('loan_repayments');
        $this->truncateTable('loan_amortization_schedules');
        
        // Step 2: Clear loans
        $this->info('2. Clearing loans...');
        $this->truncateTable('loans');
        
        // Step 3: Clear SACCO-related data
        $this->info('3. Clearing SACCO products and subscriptions...');
        $this->truncateTable('member_savings_accounts');
        $this->truncateTable('member_product_subscriptions');
        $this->truncateTable('member_fee_obligations');
        $this->truncateTable('sacco_product_attribute_values');
        $this->truncateTable('sacco_product_chart_of_accounts');
        $this->truncateTable('sacco_products');
        $this->truncateTable('sacco_product_attributes');
        $this->truncateTable('sacco_product_types');
        
        // Step 4: Clear members
        $this->info('4. Clearing members...');
        $this->truncateTable('members');
        
        // Step 5: Clear group accounts and capital transfers
        $this->info('5. Clearing group accounts and capital transfers...');
        $this->truncateTable('organization_group_capital_transfers');
        $this->truncateTable('group_accounts');
        
        // Step 6: Clear groups
        $this->info('6. Clearing groups...');
        $this->truncateTable('groups');
        
        // Step 7: Clear chart of accounts
        $this->info('7. Clearing chart of accounts...');
        $this->truncateTable('loan_product_chart_of_accounts');
        $this->truncateTable('chart_of_accounts');
        
        // Step 8: Clear loan products
        $this->info('8. Clearing loan products...');
        $this->truncateTable('loan_product_attributes');
        $this->truncateTable('loan_products');

        // Re-enable foreign key checks
        DB::statement('SET FOREIGN_KEY_CHECKS=1');

        $this->newLine();
        $this->info('✓ Database reset complete!');
        $this->newLine();
        $this->info('Next steps:');
        $this->info('  1. Run: php artisan db:seed --class=OrganizationChartOfAccountsSeeder');
        $this->info('  2. Run: php artisan db:seed --class=GroupsAndMembersTestSeeder');
        $this->newLine();

        return 0;
    }

    /**
     * Truncate a table if it exists
     */
    private function truncateTable(string $table): void
    {
        if (Schema::hasTable($table)) {
            DB::table($table)->truncate();
            $this->line("   ✓ Cleared: {$table}");
        } else {
            $this->line("   ⊗ Skipped: {$table} (table doesn't exist)");
        }
    }
}

