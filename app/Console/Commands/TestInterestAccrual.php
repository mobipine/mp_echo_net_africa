<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Loan;
use App\Models\LoanProduct;
use App\Models\Member;
use App\Models\ChartofAccounts;
use App\Models\LoanProductChartOfAccount;
use App\Models\LoanProductAttribute;
use App\Models\LoanAttribute;
use App\Models\Transaction;
use Carbon\Carbon;

class TestInterestAccrual extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'test:interest-accrual {--setup : Setup test data} {--run : Run interest accrual test} {--cleanup : Clean up test data}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Test interest accrual functionality with sample data';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        if ($this->option('setup')) {
            $this->setupTestData();
        } elseif ($this->option('run')) {
            $this->runInterestAccrualTest();
        } elseif ($this->option('cleanup')) {
            $this->cleanupTestData();
        } else {
            $this->info('Usage:');
            $this->line('  php artisan test:interest-accrual --setup   # Setup test data');
            $this->line('  php artisan test:interest-accrual --run     # Run interest accrual test');
            $this->line('  php artisan test:interest-accrual --cleanup  # Clean up test data');
        }
    }

    /**
     * Setup test data for interest accrual testing
     */
    private function setupTestData()
    {
        $this->info('🔧 Setting up test data for interest accrual...');

        // 1. Create test member
        $uniqueEmail = 'test-interest-' . now()->format('YmdHis') . '@example.com';
        $uniqueNationalId = '12345678' . now()->format('His');
        $uniqueAccountNumber = 'TEST-' . now()->format('YmdHis');
        
        // Create member without triggering boot method
        $member = new Member([
            'name' => 'Test Interest Member',
            'email' => $uniqueEmail,
            'phone' => '0700000000',
            'national_id' => $uniqueNationalId,
            'gender' => 'Male',
            'marital_status' => 'Single',
            'group_id' => 1,
            'account_number' => $uniqueAccountNumber,
        ]);
        $member->save();
        $this->line("✓ Created test member: {$member->name}");

        // 2. Create chart of accounts entries
        $accounts = [
            ['name' => 'Interest Receivable', 'account_code' => '1201', 'account_type' => 'Asset', 'slug' => 'interest-receivable'],
            ['name' => 'Interest Income', 'account_code' => '4101', 'account_type' => 'Income', 'slug' => 'interest-income'],
            ['name' => 'Loans Receivable', 'account_code' => '1202', 'account_type' => 'Asset', 'slug' => 'loans-receivable'],
            ['name' => 'Bank Account', 'account_code' => '1001', 'account_type' => 'Asset', 'slug' => 'bank-account'],
            ['name' => 'Loan Charges Income', 'account_code' => '4102', 'account_type' => 'Income', 'slug' => 'loan-charges-income'],
            ['name' => 'Loan Charges Receivable', 'account_code' => '1203', 'account_type' => 'Asset', 'slug' => 'loan-charges-receivable'],
        ];

        foreach ($accounts as $accountData) {
            ChartofAccounts::firstOrCreate(
                ['account_code' => $accountData['account_code']],
                $accountData
            );
        }
        $this->line('✓ Created chart of accounts entries');

        // 3. Create loan attributes
        $attributes = [
            ['name' => 'Interest Rate', 'slug' => 'interest_rate', 'data_type' => 'decimal'],
            ['name' => 'Interest Type', 'slug' => 'interest_type', 'data_type' => 'string'],
            ['name' => 'Interest Cycle', 'slug' => 'interest_cycle', 'data_type' => 'string'],
            ['name' => 'Interest Accrual Moment', 'slug' => 'interest_accrual_moment', 'data_type' => 'string'],
            ['name' => 'Loan Charges', 'slug' => 'loan_charges', 'data_type' => 'decimal'],
        ];

        foreach ($attributes as $attrData) {
            LoanAttribute::firstOrCreate(
                ['slug' => $attrData['slug']],
                $attrData
            );
        }
        $this->line('✓ Created loan attributes');

        // 4. Create test loan product
        $loanProduct = LoanProduct::firstOrCreate(
            ['name' => 'Test Interest Loan Product'],
            [
                'description' => 'Test loan product for interest accrual testing',
                'max_loan_amount' => 1000000,
                'min_loan_amount' => 10000,
                'max_duration' => 12,
                'min_duration' => 1,
                'interest_rate' => 12.0, // 12% annual
                'is_active' => true,
            ]
        );
        $this->line("✓ Created test loan product: {$loanProduct->name}");

        // 5. Assign attributes to loan product
        $attributeValues = [
            'interest_rate' => '12.0',
            'interest_type' => 'Simple',
            'interest_cycle' => 'Monthly',
            'interest_accrual_moment' => 'After First Cycle',
            'loan_charges' => '500.0',
        ];

        foreach ($attributeValues as $slug => $value) {
            $attribute = LoanAttribute::where('slug', $slug)->first();
            if ($attribute) {
                LoanProductAttribute::firstOrCreate(
                    [
                        'loan_product_id' => $loanProduct->id,
                        'loan_attribute_id' => $attribute->id,
                    ],
                    ['value' => $value]
                );
            }
        }
        $this->line('✓ Assigned attributes to loan product');

        // 6. Assign chart of accounts to loan product
        $accountAssignments = [
            'interest_receivable' => '1201',
            'interest_income' => '4101',
            'loans_receivable' => '1202',
            'bank' => '1001',
            'loan_charges_income' => '4102',
            'loan_charges_receivable' => '1203',
        ];

        foreach ($accountAssignments as $accountType => $accountCode) {
            LoanProductChartOfAccount::firstOrCreate(
                [
                    'loan_product_id' => $loanProduct->id,
                    'account_type' => $accountType,
                ],
                ['account_number' => $accountCode]
            );
        }
        $this->line('✓ Assigned chart of accounts to loan product');

        // 7. Create test loan (initially as Pending Approval)
        $loan = Loan::firstOrCreate(
            [
                'member_id' => $member->id,
                'loan_product_id' => $loanProduct->id,
                'loan_number' => 'TEST-INT-001',
            ],
            [
                'principal_amount' => 100000,
                'interest_rate' => 12.0,
                'interest_amount' => 0,
                'repayment_amount' => 0,
                'loan_duration' => 6, // 6 months
                'status' => 'Pending Approval',
                'release_date' => now()->subDays(35), // Released 35 days ago
                'due_date' => now()->addDays(145), // Due in 145 days
                'due_at' => now()->addDays(145),
            ]
        );
        $this->line("✓ Created test loan: {$loan->loan_number} (Pending Approval)");

        // 8. Simulate loan approval process
        $this->line("🔄 Simulating loan approval process...");
        
        // Update loan status to approved
        $loan->update([
            'status' => 'Approved',
            'approved_by' => 3,
            'approved_at' => now()->subDays(35),
        ]);
        $this->line("✓ Loan approved");

        // Generate amortization schedule
        \App\Models\LoanAmortizationSchedule::generateSchedule($loan);
        $this->line("✓ Amortization schedule generated");

        // Create loan transactions using the same logic as LoanResource
        $this->createLoanTransactions($loan);
        $this->line("✓ Loan transactions created");

        $this->info('✅ Test data setup complete!');
        $this->line('');
        $this->line('Test data created:');
        $this->line("  • Member: {$member->name} ({$member->email})");
        $this->line("  • Loan Product: {$loanProduct->name}");
        $this->line("  • Loan: {$loan->loan_number} - KES " . number_format((float)$loan->principal_amount, 2));
        $this->line("  • Interest Rate: 12% per annum");
        $this->line("  • Interest Type: Simple");
        $this->line("  • Interest Cycle: Monthly");
        $this->line("  • Release Date: " . Carbon::parse($loan->release_date)->format('Y-m-d') . " (" . Carbon::parse($loan->release_date)->diffInDays(now()) . " days ago)");
    }

    /**
     * Run interest accrual test
     */
    private function runInterestAccrualTest()
    {
        $this->info('🧪 Running interest accrual test...');

        $loan = Loan::where('loan_number', 'TEST-INT-001')->first();
        if (!$loan) {
            $this->error('❌ Test loan not found. Run --setup first.');
            return;
        }

        $this->line("Testing loan: {$loan->loan_number}");
        $this->line("Principal: KES " . number_format((float)$loan->principal_amount, 2));
        $this->line("Release Date: " . Carbon::parse($loan->release_date)->format('Y-m-d'));
        $this->line("Days since release: " . Carbon::parse($loan->release_date)->diffInDays(now()));
        $this->line("Current outstanding interest: KES " . number_format($loan->getOutstandingInterest(), 2));
        $this->line("Current transactions count: " . $loan->transactions()->count());

        // Run the interest accrual command
        $this->line('');
        $this->info('Running: php artisan loans:accrue-interest');
        
        $exitCode = $this->call('loans:accrue-interest');
        
        if ($exitCode === 0) {
            $loan->refresh();
            $this->line('');
            $this->info('✅ Interest accrual completed!');
            $this->line("New outstanding interest: KES " . number_format($loan->getOutstandingInterest(), 2));
            $this->line("New transactions count: " . $loan->transactions()->count());
            
            // Show recent transactions
            $recentTransactions = $loan->transactions()->orderBy('id', 'desc')->limit(4)->get();
            $this->line('');
            $this->line('Recent transactions:');
            foreach ($recentTransactions as $transaction) {
                $this->line("  {$transaction->id}: {$transaction->transaction_type} - {$transaction->dr_cr} - KES " . number_format((float)$transaction->amount, 2) . " - {$transaction->account_name}");
            }
        } else {
            $this->error('❌ Interest accrual failed!');
        }
    }

    /**
     * Clean up test data
     */
    private function cleanupTestData()
    {
        $this->info('🧹 Cleaning up test data...');

        // Delete test loan and its transactions
        $loan = Loan::where('loan_number', 'TEST-INT-001')->first();
        if ($loan) {
            $loan->transactions()->delete();
            $loan->delete();
            $this->line('✓ Deleted test loan and transactions');
        }

        // Delete test member (check for any test-interest email pattern)
        $member = Member::where('email', 'like', 'test-interest-%@example.com')->first();
        if ($member) {
            $member->delete();
            $this->line('✓ Deleted test member');
        }

        // Delete test loan product and its relations
        $loanProduct = LoanProduct::where('name', 'Test Interest Loan Product')->first();
        if ($loanProduct) {
            $loanProduct->chartOfAccounts()->delete();
            $loanProduct->loanProductAttributes()->delete();
            $loanProduct->delete();
            $this->line('✓ Deleted test loan product');
        }

        // Delete test loan attributes
        \App\Models\LoanAttribute::where('name', 'loan_charges')->delete();
        \App\Models\LoanAttribute::where('name', 'loan_penalty')->delete();
        $this->line('✓ Deleted test loan attributes');

        // Delete test chart of accounts
        \App\Models\ChartofAccounts::where('name', 'Loans Receivable')->delete();
        \App\Models\ChartofAccounts::where('name', 'Bank Account')->delete();
        \App\Models\ChartofAccounts::where('name', 'Loan Charges Income')->delete();
        \App\Models\ChartofAccounts::where('name', 'Loan Charges Receivable')->delete();
        \App\Models\ChartofAccounts::where('name', 'Interest Receivable')->delete();
        \App\Models\ChartofAccounts::where('name', 'Interest Income')->delete();
        $this->line('✓ Deleted test chart of accounts');

        $this->info('✅ Cleanup complete!');
    }

    /**
     * Create loan transactions using the same logic as LoanResource
     * This simulates the actual loan approval process
     */
    private function createLoanTransactions(Loan $loan)
    {
        $attributes = $loan->all_attributes;
        $loanCharges = (float) ($attributes['loan_charges']['value'] ?? 0);
        $applyChargesOnIssuance = config('repayment_priority.charges.apply_on_issuance', true);
        $deductFromPrincipal = config('repayment_priority.charges.deduct_from_principal', false);
        
        // Calculate net disbursement amount
        $netDisbursement = $loan->principal_amount;
        if ($applyChargesOnIssuance && $loanCharges > 0) {
            if ($deductFromPrincipal) {
                $netDisbursement = $loan->principal_amount - $loanCharges;
            }
        }

        // Create loan receivable transaction
        $loansReceivableName = $this->getAccountNameFromLoanProduct($loan, 'loans_receivable') ?? config('repayment_priority.accounts.loans_receivable');
        $loansReceivableNumber = $this->getAccountNumberFromLoanProduct($loan, 'loans_receivable');
        
        Transaction::create([
            'account_name' => $loansReceivableName,
            'account_number' => $loansReceivableNumber,
            'loan_id' => $loan->id,
            'member_id' => $loan->member_id,
            'transaction_type' => 'loan_issue',
            'dr_cr' => 'dr',
            'amount' => $loan->principal_amount,
            'transaction_date' => $loan->release_date,
            'description' => "Loan issued to member {$loan->member->name}",
        ]);

        // Create bank disbursement transaction
        $bankAccountName = $this->getAccountNameFromLoanProduct($loan, 'bank') ?? config('repayment_priority.accounts.bank');
        $bankAccountNumber = $this->getAccountNumberFromLoanProduct($loan, 'bank');
        
        Transaction::create([
            'account_name' => $bankAccountName,
            'account_number' => $bankAccountNumber,
            'loan_id' => $loan->id,
            'member_id' => $loan->member_id,
            'transaction_type' => 'loan_issue',
            'dr_cr' => 'cr',
            'amount' => $netDisbursement,
            'transaction_date' => $loan->release_date,
            'description' => "Bank disbursement for loan issued to member {$loan->member->name}",
        ]);

        // Create loan charges transactions if applicable
        if ($applyChargesOnIssuance && $loanCharges > 0) {
            // Credit loan charges income
            $chargesIncomeName = $this->getAccountNameFromLoanProduct($loan, 'loan_charges_income') ?? config('repayment_priority.accounts.loan_charges_income');
            $chargesIncomeNumber = $this->getAccountNumberFromLoanProduct($loan, 'loan_charges_income');
            
            Transaction::create([
                'account_name' => $chargesIncomeName,
                'account_number' => $chargesIncomeNumber,
                'loan_id' => $loan->id,
                'member_id' => $loan->member_id,
                'transaction_type' => 'loan_charges',
                'dr_cr' => 'cr',
                'amount' => $loanCharges,
                'transaction_date' => $loan->release_date,
                'description' => "Loan charges income for loan issued to member {$loan->member->name}",
            ]);

            // If charges are not deducted from principal, create receivable entry
            if (!$deductFromPrincipal) {
                $chargesReceivableName = $this->getAccountNameFromLoanProduct($loan, 'loan_charges_receivable') ?? config('repayment_priority.accounts.loan_charges_receivable');
                $chargesReceivableNumber = $this->getAccountNumberFromLoanProduct($loan, 'loan_charges_receivable');
                
                Transaction::create([
                    'account_name' => $chargesReceivableName,
                    'account_number' => $chargesReceivableNumber,
                    'loan_id' => $loan->id,
                    'member_id' => $loan->member_id,
                    'transaction_type' => 'loan_charges',
                    'dr_cr' => 'dr',
                    'amount' => $loanCharges,
                    'transaction_date' => $loan->release_date,
                    'description' => "Loan charges receivable for loan issued to member {$loan->member->name}",
                ]);
            }
        }
    }

    /**
     * Get account name from loan product chart of accounts
     */
    private function getAccountNameFromLoanProduct(Loan $loan, string $accountType): ?string
    {
        $loanProductChartOfAccount = $loan->loanProduct->chartOfAccounts()
            ->where('account_type', $accountType)
            ->first();
        
        if ($loanProductChartOfAccount) {
            $chartOfAccount = ChartofAccounts::where('account_code', $loanProductChartOfAccount->account_number)->first();
            return $chartOfAccount?->name;
        }
        
        return null;
    }

    /**
     * Get account number from loan product chart of accounts
     */
    private function getAccountNumberFromLoanProduct(Loan $loan, string $accountType): ?string
    {
        $loanProductChartOfAccount = $loan->loanProduct->chartOfAccounts()
            ->where('account_type', $accountType)
            ->first();
        
        return $loanProductChartOfAccount?->account_number;
    }
}
