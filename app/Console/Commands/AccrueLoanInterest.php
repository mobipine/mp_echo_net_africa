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
     * Check if a loan should accrue interest based on accrual moment and cycle
     */
    private function shouldAccrueInterest(Loan $loan): bool
    {
        $attributes = $loan->all_attributes;
        $accrualMoment = $attributes['interest_accrual_moment']['value'] ?? 'After First Cycle';
        $cycle = $attributes['interest_cycle']['value'] ?? 'Monthly';
        
        if ($accrualMoment === 'Loan issue') {
            // Interest accrues immediately when loan is issued
            return true;
        }
        
        if ($accrualMoment === 'After First Cycle') {
            // Check if we're due for the next cycle
            return $this->isDueForNextCycle($loan, $cycle);
        }
        
        return false;
    }

    /**
     * Check if loan is due for the next interest accrual cycle
     */
    private function isDueForNextCycle(Loan $loan, string $cycle): bool
    {
        $lastAccrualDate = $this->getLastInterestAccrualDate($loan);
        $referenceDate = $lastAccrualDate ?: Carbon::parse($loan->release_date);
        $currentDate = now();
        
        // Calculate the next cycle date
        $nextCycleDate = $this->getNextCycleDate($referenceDate, $cycle);
        
        // Check if current date is past the next cycle date
        return $currentDate->gte($nextCycleDate);
    }

    /**
     * Get the next cycle date based on the cycle type
     */
    private function getNextCycleDate(Carbon $referenceDate, string $cycle): Carbon
    {
        return match ($cycle) {
            'Daily' => $referenceDate->copy()->addDay(),
            'Weekly' => $referenceDate->copy()->addWeek(),
            'Monthly' => $referenceDate->copy()->addMonth(),
            'Yearly' => $referenceDate->copy()->addYear(),
            default => $referenceDate->copy()->addMonth(),
        };
    }

    /**
     * Calculate interest for a specific loan for the current cycle
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
        $referenceDate = $lastAccrualDate ?: Carbon::parse($loan->release_date);
        
        // Calculate the period for the current cycle
        $cyclePeriod = $this->getCyclePeriodInDays($cycle);
        
        // For the first accrual, calculate from release date to first cycle
        // For subsequent accruals, calculate for exactly one cycle period
        if (!$lastAccrualDate) {
            // First accrual: calculate from release date to first cycle date
            $firstCycleDate = $this->getNextCycleDate($referenceDate, $cycle);
            $daysForCalculation = $referenceDate->diffInDays($firstCycleDate);

            // dd($daysForCalculation);
        } else {
            // Subsequent accruals: calculate for exactly one cycle period
            $daysForCalculation = $cyclePeriod;
        }
        
        if ($daysForCalculation <= 0) {
            return 0;
        }

        switch ($interestType) {
            case 'Simple':
                return $this->calculateSimpleInterest($principal, $interestRate, $daysForCalculation, $cycle);
                
            case 'Flat':
                return $this->calculateFlatInterest($principal, $interestRate, $daysForCalculation, $cycle);
                
            case 'ReducingBalance':
                return $this->calculateReducingBalanceInterest($loan, $interestRate, $daysForCalculation, $cycle);
                
            default:
                return $this->calculateSimpleInterest($principal, $interestRate, $daysForCalculation, $cycle);
        }
    }

    /**
     * Get the number of days in a cycle period
     */
    private function getCyclePeriodInDays(string $cycle): int
    {
        return match ($cycle) {
            'Daily' => 1,
            'Weekly' => 7,
            'Monthly' => 30, // Using 30 days as standard month
            'Yearly' => 365,
            default => 30,
        };
    }

    /**
     * Calculate simple interest for the given period
     */
    private function calculateSimpleInterest(float $principal, float $rate, int $days, string $cycle): float
    {

        // dd($principal, $rate, $days);
        // Simple interest formula: (Principal × Rate × Time) / (365 × 100)
        // Rate is annual percentage, so we use 365 days per year
       
        return ($principal * $rate * $days) / (365 * 100);
    }

    /**
     * Calculate flat interest for the given period
     */
    private function calculateFlatInterest(float $principal, float $rate, int $days, string $cycle): float
    {
        // Flat interest uses the same formula as simple interest
        return ($principal * $rate * $days) / (365 * 100);
    }

    /**
     * Calculate reducing balance interest for the given period
     */
    private function calculateReducingBalanceInterest(Loan $loan, float $rate, int $days, string $cycle): float
    {
        $remainingBalance = $loan->remaining_balance;
        // Reducing balance interest on remaining balance
        return ($remainingBalance * $rate * $days) / (365 * 100);
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
        // Get member's group for group-level accounting
        $group = $loan->member->group;
        $groupId = $group->id;

        // Debit: Interest Receivable (Asset - money owed to us) - GROUP ACCOUNT
        Transaction::create([
            'account_name' => "{$group->name} - Interest Receivable",
            'account_number' => "G{$groupId}-1103",
            'loan_id' => $loan->id,
            'member_id' => $loan->member_id,
            'group_id' => $groupId,
            'transaction_type' => 'interest_accrual',
            'dr_cr' => 'dr',
            'amount' => $interestAmount,
            'transaction_date' => now(),
            'description' => "Interest accrued for loan #{$loan->loan_number} - {$loan->member->name}",
        ]);

        // Credit: Interest Income (Revenue - income earned) - GROUP ACCOUNT
        Transaction::create([
            'account_name' => "{$group->name} - Interest Income",
            'account_number' => "G{$groupId}-4002",
            'loan_id' => $loan->id,
            'member_id' => $loan->member_id,
            'group_id' => $groupId,
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