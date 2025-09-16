<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class LoanAmortizationSchedule extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'loan_id',
        'payment_number',
        'payment_date',
        'principal_payment',
        'interest_payment',
        'total_payment',
        'remaining_balance',
    ];

    protected $casts = [
        'payment_date' => 'date',
        'principal_payment' => 'decimal:2',
        'interest_payment' => 'decimal:2',
        'total_payment' => 'decimal:2',
        'remaining_balance' => 'decimal:2',
    ];

    public function loan()
    {
        return $this->belongsTo(Loan::class);
    }

    /**
     * Generate amortization schedule for a loan
     */
    public static function generateSchedule(Loan $loan)
    {
        // Clear existing schedule
        self::where('loan_id', $loan->id)->delete();

        // Get the loan product and all attributes
        $loanProduct = $loan->loanProduct;
        $attributes = $loanProduct->LoanProductAttributes;

        // Get interest type from loan product attributes
        $interest_type_slug = 'interest_type';
        $interest_cycle_slug = 'interest_cycle';

        $interest_type_attribute = LoanAttribute::where('slug', $interest_type_slug)->first();
        $interest_cycle_attribute = LoanAttribute::where('slug', $interest_cycle_slug)->first();

        $interest_type_value = $attributes->where('loan_attribute_id', $interest_type_attribute->id)->first();
        $interest_type = $interest_type_value ? $interest_type_value->value : 'Simple';

        $interest_cycle_value = $attributes->where('loan_attribute_id', $interest_cycle_attribute->id)->first();
        $interest_cycle = $interest_cycle_value ? $interest_cycle_value->value : 'jj';

        // dd($interest_type,$interest_cycle, $interest_type_value, $interest_type_attribute, $attributes);

        // Loan details
        $principalAmount = $loan->principal_amount;
        $interestRate = $loan->interest_rate / 100; // Convert percentage to decimal
        $loanDuration = $loan->loan_duration;
        $startDate = $loan->release_date;

        // Calculate payment frequency based on interest cycle
        $paymentsPerYear = self::getPaymentsPerYear($interest_cycle);
        // $totalPayments = $loanDuration * $paymentsPerYear;
        $totalPayments = $loanDuration;

        // dd($totalPayments);

        $schedules = [];

        // Generate schedule based on interest type
        switch (strtolower($interest_type)) {
            case 'simple':
                $schedules = self::generateSimpleInterestSchedule($loan, $principalAmount, $interestRate, $loanDuration, $interest_cycle, $startDate, $totalPayments);
                break;
                
            case 'flat':
                $schedules = self::generateFlatRateSchedule($loan, $principalAmount, $interestRate, $loanDuration, $interest_cycle, $startDate, $totalPayments);
                break;
            case 'flatrate':
                $schedules = self::generateFlatRateSchedule($loan, $principalAmount, $interestRate, $loanDuration, $interest_cycle, $startDate, $totalPayments);
                break;
                
            case 'reducingbalance':
                // TODO: Implement reducing balance calculation
                $schedules = self::generateReducingBalanceSchedule($loan, $principalAmount, $interestRate, $loanDuration, $interest_cycle, $startDate, $totalPayments);
                break;
                
            default:
                // Default to simple interest
                $schedules = self::generateSimpleInterestSchedule($loan, $principalAmount, $interestRate, $loanDuration, $interest_cycle, $startDate, $totalPayments);
        }

        // Insert all schedules
        self::insert($schedules);
    }

    /**
     * Generate schedule for Simple Interest
     * Formula: Total Repayment = Principal + (Principal * Interest Rate * Time)
     */
    private static function generateSimpleInterestSchedule($loan, $principalAmount, $interestRate, $loanDuration, $interest_cycle, $startDate, $totalPayments)
    {
        $schedules = [];
        
        // Calculate total interest for the entire loan period
        $totalInterest = $principalAmount * $interestRate * $loanDuration;
        $totalRepayment = $principalAmount + $totalInterest;
        
        // Calculate equal payments
        $paymentAmount = $totalRepayment / $totalPayments;
        $principalPerPayment = $principalAmount / $totalPayments;
        $interestPerPayment = $totalInterest / $totalPayments;
        
        $remainingBalance = $principalAmount;

        for ($i = 1; $i <= $totalPayments; $i++) {
            // Calculate payment date
            $paymentDate = self::calculatePaymentDate($startDate, $i, $interest_cycle);

            // Update remaining balance
            $remainingBalance -= $principalPerPayment;

            $schedules[] = [
                'loan_id' => $loan->id,
                'payment_number' => $i,
                'payment_date' => $paymentDate,
                'principal_payment' => round($principalPerPayment, 2),
                'interest_payment' => round($interestPerPayment, 2),
                'total_payment' => round($paymentAmount, 2),
                'remaining_balance' => round(max($remainingBalance, 0), 2),
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        return $schedules;
    }

    /**
     * Generate schedule for Flat Rate Interest
     * Formula: Total Repayment = Principal * (1 + Interest Rate)
     */
    private static function generateFlatRateSchedule($loan, $principalAmount, $interestRate, $loanDuration, $interest_cycle, $startDate, $totalPayments)
    {
        $schedules = [];
        
        // Calculate total repayment using flat rate
        $totalRepayment = $principalAmount * (1 + $interestRate);
        $totalInterest = $totalRepayment - $principalAmount;
        
        // Calculate equal payments
        $paymentAmount = $totalRepayment / $totalPayments;
        $principalPerPayment = $principalAmount / $totalPayments;
        $interestPerPayment = $totalInterest / $totalPayments;
        
        $remainingBalance = $principalAmount;

        for ($i = 1; $i <= $totalPayments; $i++) {
            // Calculate payment date
            $paymentDate = self::calculatePaymentDate($startDate, $i, $interest_cycle);

            // Update remaining balance
            $remainingBalance -= $principalPerPayment;

            $schedules[] = [
                'loan_id' => $loan->id,
                'payment_number' => $i,
                'payment_date' => $paymentDate,
                'principal_payment' => round($principalPerPayment, 2),
                'interest_payment' => round($interestPerPayment, 2),
                'total_payment' => round($paymentAmount, 2),
                'remaining_balance' => round(max($remainingBalance, 0), 2),
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        return $schedules;
    }

    /**
     * Generate schedule for Reducing Balance Interest
     * TODO: Implement reducing balance calculation
     */
    private static function generateReducingBalanceSchedule($loan, $principalAmount, $interestRate, $loanDuration, $interest_cycle, $startDate, $totalPayments)
    {
        $schedules = [];
        
        // TODO: Implement reducing balance calculation
        // For now, return empty array
        // This will be implemented later with proper reducing balance formula
        
        return $schedules;
    }

    /**
     * Get payments per year based on interest cycle
     */
    private static function getPaymentsPerYear($interest_cycle)
    {
        switch (strtolower($interest_cycle)) {
            case 'daily':
                return 365;
            case 'weekly':
                return 52;
            case 'monthly':
                return 12;
            case 'yearly':
                return 1;
            default:
                return 12; // Default to monthly
        }
    }

    /**
     * Calculate payment date based on cycle and payment number
     */
    private static function calculatePaymentDate($startDate, $paymentNumber, $interest_cycle)
    {
        $start = \Carbon\Carbon::parse($startDate);
        
        switch (strtolower($interest_cycle)) {
            case 'daily':
                return $start->addDays($paymentNumber)->toDateString();
            case 'weekly':
                return $start->addWeeks($paymentNumber)->toDateString();
            case 'monthly':
                return $start->addMonths($paymentNumber)->toDateString();
            case 'yearly':
                return $start->addYears($paymentNumber)->toDateString();
            default:
                return $start->addMonths($paymentNumber)->toDateString();
        }
    }
}