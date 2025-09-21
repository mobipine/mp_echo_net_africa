<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Loan;
use App\Models\LoanRepayment;
use App\Models\Transaction;
use App\Services\RepaymentAllocationService;
use Carbon\Carbon;

class TestRepaymentWithInterest extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'test:repayment-interest {loan_id} {amount} {--method=cash : Payment method}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Test repayment functionality with interest for a specific loan';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $loanId = $this->argument('loan_id');
        $amount = (float) $this->argument('amount');
        $method = $this->option('method');

        $this->info("🧪 Testing repayment for loan #{$loanId}...");

        $loan = Loan::with('member', 'loanProduct')->find($loanId);
        if (!$loan) {
            $this->error("❌ Loan #{$loanId} not found.");
            return;
        }

        $this->line("Loan: {$loan->loan_number} - {$loan->member->name}");
        $this->line("Principal: KES " . number_format((float)$loan->principal_amount, 2));
        $this->line("Outstanding Principal: KES " . number_format($loan->getOutstandingPrincipal(), 2));
        $this->line("Outstanding Interest: KES " . number_format($loan->getOutstandingInterest(), 2));
        $this->line("Outstanding Charges: KES " . number_format($loan->getOutstandingLoanCharges(), 2));
        $this->line("Total Outstanding: KES " . number_format($loan->remaining_balance, 2));
        $this->line("Current Status: {$loan->status}");
        $this->line("Current Transactions: " . $loan->transactions()->count());

        // Test allocation
        $allocationService = new RepaymentAllocationService();
        $allocation = $allocationService->allocateRepayment($loan, $amount);

        $this->line('');
        $this->info('Repayment Allocation:');
        $this->line("  Charges Payment: KES " . number_format($allocation['charges_payment'], 2));
        $this->line("  Interest Payment: KES " . number_format($allocation['interest_payment'], 2));
        $this->line("  Principal Payment: KES " . number_format($allocation['principal_payment'], 2));
        $this->line("  Remaining Amount: KES " . number_format($allocation['remaining_amount'], 2));

        if ($this->confirm('Do you want to create this repayment?')) {
            // Create repayment record
            $repayment = LoanRepayment::create([
                'loan_id' => $loan->id,
                'member_id' => $loan->member_id,
                'amount' => $amount,
                'repayment_date' => now(),
                'payment_method' => $method,
                'reference_number' => 'TEST-' . now()->format('YmdHis'),
                'notes' => 'Test repayment with interest',
                'recorded_by' => 3,
            ]);

            $this->line("✓ Created repayment record: {$repayment->id}");

            // Create transactions
            $transactions = $allocationService->createRepaymentTransactions($loan, $amount, $method);

            foreach ($transactions as $transactionData) {
                Transaction::create(array_merge($transactionData, [
                    'repayment_id' => $repayment->id,
                    'transaction_date' => now(),
                ]));
            }

            $this->line("✓ Created " . count($transactions) . " transactions");

            // Update loan status if fully repaid
            $loan->refresh();
            if ($loan->remaining_balance <= 0) {
                $loan->update(['status' => 'Fully Repaid']);
                $this->line("✓ Loan status updated to: Fully Repaid");
            }

            $this->line('');
            $this->info('✅ Repayment completed!');
            $this->line("New Outstanding Principal: KES " . number_format($loan->getOutstandingPrincipal(), 2));
            $this->line("New Outstanding Interest: KES " . number_format($loan->getOutstandingInterest(), 2));
            $this->line("New Outstanding Charges: KES " . number_format($loan->getOutstandingLoanCharges(), 2));
            $this->line("New Total Outstanding: KES " . number_format($loan->remaining_balance, 2));
            $this->line("New Status: {$loan->status}");
            $this->line("New Transactions Count: " . $loan->transactions()->count());

            // Show recent transactions
            $recentTransactions = $loan->transactions()->orderBy('id', 'desc')->limit(6)->get();
            $this->line('');
            $this->line('Recent transactions:');
            foreach ($recentTransactions as $transaction) {
                $this->line("  {$transaction->id}: {$transaction->transaction_type} - {$transaction->dr_cr} - KES " . number_format((float)$transaction->amount, 2) . " - {$transaction->account_name}");
            }
        } else {
            $this->info('Repayment cancelled.');
        }
    }
}
