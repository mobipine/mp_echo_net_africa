<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Loan;
use App\Models\Transaction;
use App\Models\LoanRepayment;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class AccrueLoanInterest extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'loans:accrue-interest {--dry-run : Show what would be done without executing}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Accrue interest for active loans based on their interest type and cycle';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $dryRun = $this->option('dry-run');
        
        if ($dryRun) {
            $this->info('🔍 DRY RUN MODE - No changes will be made');
        }

        $this->info('🚀 Starting loan interest accrual process...');

        // Get all active loans that need interest accrual
        $loans = $this->getLoansForInterestAccrual();

        if ($loans->isEmpty()) {
            $this->info('✅ No loans require interest accrual at this time.');
            return;
        }

        $this->info("📊 Found {$loans->count()} loans requiring interest accrual");

        $totalInterestAccrued = 0;
        $processedCount = 0;

        foreach ($loans as $loan) {
            try {
                $interestAmount = $this->calculateInterestForLoan($loan);
                
                if ($interestAmount > 0) {
                    if (!$dryRun) {
                        $this->createInterestAccrualTransactions($loan, $interestAmount);
                    }
                    
                    $totalInterestAccrued += $interestAmount;
                    $processedCount++;
                    
                    $this->line("  ✓ Loan #{$loan->loan_number}: KES " . number_format($interestAmount, 2));
                }
            } catch (\Exception $e) {
                $this->error("  ✗ Error processing loan #{$loan->loan_number}: " . $e->getMessage());
                Log::error('Interest accrual error', [
                    'loan_id' => $loan->id,
                    'error' => $e->getMessage()
                ]);
            }
        }

        if ($dryRun) {
            $this->info("🔍 DRY RUN COMPLETE:");
            $this->info("  • {$processedCount} loans would be processed");
            $this->info("  • Total interest: KES " . number_format($totalInterestAccrued, 2));
        } else {
            $this->info("✅ Interest accrual completed successfully!");
            $this->info("  • {$processedCount} loans processed");
            $this->info("  • Total interest accrued: KES " . number_format($totalInterestAccrued, 2));
        }
    }

    /**
     * Get loans that need interest accrual
     */
    private function getLoansForInterestAccrual()
    {
        return Loan::whereIn('status', ['Approved', 'Active'])
            ->where('release_date', '<=', now())
            ->where('due_at', '>', now()) // Not yet due
            ->with(['loanProduct.loanProductAttributes.loanAttribute'])
            ->get()
            ->filter(function ($loan) {
                return $this->shouldAccrueInterest($loan);
            });
    }

    /**
     * Check if a loan should accrue interest based on accrual moment
     */
    private function shouldAccrueInterest(Loan $loan): bool
    {
        $attributes = $loan->all_attributes;
        $accrualMoment = $attributes['interest_accrual_moment']['value'] ?? 'After First Cycle';
        
        if ($accrualMoment === 'Loan issue') {
            // Interest accrues immediately when loan is issued
            return true;
        }
        
        if ($accrualMoment === 'After First Cycle') {
            // Check if first cycle has passed
            $cycle = $attributes['interest_cycle']['value'] ?? 'Monthly';
            $firstCycleDate = $this->getFirstCycleDate($loan->release_date, $cycle);
            
            return now()->gte($firstCycleDate);
        }
        
        return false;
    }

    /**
     * Calculate interest for a specific loan
     */
    private function calculateInterestForLoan(Loan $loan): float
    {
        $attributes = $loan->all_attributes;
        $interestType = $attributes['interest_type']['value'] ?? 'Simple';
        $interestRate = (float) ($attributes['interest_rate']['value'] ?? 0);
        $cycle = $attributes['interest_cycle']['value'] ?? 'Monthly';
        
        if ($interestRate <= 0) {
            return 0;
        }

        $principal = (float) $loan->principal_amount;
        $lastAccrualDate = $this->getLastInterestAccrualDate($loan);
        $currentDate = now();
        
        // Calculate days since last accrual
        $daysSinceLastAccrual = $lastAccrualDate ? $lastAccrualDate->diffInDays($currentDate) : Carbon::parse($loan->release_date)->diffInDays($currentDate);
        
        if ($daysSinceLastAccrual <= 0) {
            return 0;
        }

        switch ($interestType) {
            case 'Simple':
                return $this->calculateSimpleInterest($principal, $interestRate, $daysSinceLastAccrual, $cycle);
                
            case 'Flat':
                return $this->calculateFlatInterest($principal, $interestRate, $daysSinceLastAccrual, $cycle);
                
            case 'ReducingBalance':
                return $this->calculateReducingBalanceInterest($loan, $interestRate, $daysSinceLastAccrual, $cycle);
                
            default:
                return $this->calculateSimpleInterest($principal, $interestRate, $daysSinceLastAccrual, $cycle);
        }
    }

    /**
     * Calculate simple interest
     */
    private function calculateSimpleInterest(float $principal, float $rate, int $days, string $cycle): float
    {
        $daysPerYear = $this->getDaysPerYear($cycle);
        return ($principal * $rate * $days) / ($daysPerYear * 100);
    }

    /**
     * Calculate flat interest
     */
    private function calculateFlatInterest(float $principal, float $rate, int $days, string $cycle): float
    {
        $daysPerYear = $this->getDaysPerYear($cycle);
        return ($principal * $rate * $days) / ($daysPerYear * 100);
    }

    /**
     * Calculate reducing balance interest
     */
    private function calculateReducingBalanceInterest(Loan $loan, float $rate, int $days, string $cycle): float
    {
        $remainingBalance = $loan->remaining_balance;
        $daysPerYear = $this->getDaysPerYear($cycle);
        return ($remainingBalance * $rate * $days) / ($daysPerYear * 100);
    }

    /**
     * Get days per year based on cycle
     */
    private function getDaysPerYear(string $cycle): int
    {
        return match ($cycle) {
            'Daily' => 365,
            'Weekly' => 52,
            'Monthly' => 12,
            'Yearly' => 1,
            default => 12,
        };
    }

    /**
     * Get first cycle date
     */
    private function getFirstCycleDate(Carbon $releaseDate, string $cycle): Carbon
    {
        $releaseDate = Carbon::parse($releaseDate);
        return match ($cycle) {
            'Daily' => $releaseDate->addDay(),
            'Weekly' => $releaseDate->addWeek(),
            'Monthly' => $releaseDate->addMonth(),
            'Yearly' => $releaseDate->addYear(),
            default => $releaseDate->addMonth(),
        };
    }

    /**
     * Get last interest accrual date for a loan
     */
    private function getLastInterestAccrualDate(Loan $loan): ?Carbon
    {
        $lastTransaction = Transaction::where('loan_id', $loan->id)
            ->where('transaction_type', 'interest_accrual')
            ->orderBy('transaction_date', 'desc')
            ->first();
            
        return $lastTransaction ? $lastTransaction->transaction_date : null;
    }

    /**
     * Create double-entry transactions for interest accrual
     */
    private function createInterestAccrualTransactions(Loan $loan, float $interestAmount): void
    {
        // Debit: Interest Receivable (Asset - money owed to us)
        Transaction::create([
            'account_name' => 'Interest Receivable',
            'loan_id' => $loan->id,
            'member_id' => $loan->member_id,
            'transaction_type' => 'interest_accrual',
            'dr_cr' => 'dr',
            'amount' => $interestAmount,
            'transaction_date' => now(),
            'description' => "Interest accrued for loan #{$loan->loan_number} - {$loan->member->name}",
        ]);

        // Credit: Interest Income (Revenue - income earned)
        Transaction::create([
            'account_name' => 'Interest Income',
            'loan_id' => $loan->id,
            'member_id' => $loan->member_id,
            'transaction_type' => 'interest_accrual',
            'dr_cr' => 'cr',
            'amount' => $interestAmount,
            'transaction_date' => now(),
            'description' => "Interest income earned from loan #{$loan->loan_number} - {$loan->member->name}",
        ]);

        // Update loan's interest amount
        $loan->increment('interest_amount', $interestAmount);
    }
}