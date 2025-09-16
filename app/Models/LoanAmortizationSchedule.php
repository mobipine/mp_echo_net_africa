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

        $principalAmount = $loan->principal_amount;
        $interestRate = $loan->interest_rate / 100; // Convert percentage to decimal
        $loanDuration = $loan->loan_duration;
        $interestCycle = $loan->interest_cycle;
        $startDate = $loan->release_date;

        // Calculate payment frequency based on interest cycle
        $paymentsPerYear = self::getPaymentsPerYear($interestCycle);
        $totalPayments = $loanDuration * $paymentsPerYear;

        // Calculate periodic interest rate
        $periodicRate = $interestRate / $paymentsPerYear;

        // Calculate payment amount using PMT formula
        if ($periodicRate > 0) {
            $paymentAmount = $principalAmount * ($periodicRate * pow(1 + $periodicRate, $totalPayments)) / (pow(1 + $periodicRate, $totalPayments) - 1);
        } else {
            $paymentAmount = $principalAmount / $totalPayments;
        }

        $remainingBalance = $principalAmount;
        $schedules = [];

        for ($i = 1; $i <= $totalPayments; $i++) {
            // Calculate interest payment
            $interestPayment = $remainingBalance * $periodicRate;
            
            // Calculate principal payment
            $principalPayment = $paymentAmount - $interestPayment;
            
            // Adjust for final payment to ensure exact balance
            if ($i === $totalPayments) {
                $principalPayment = $remainingBalance;
                $paymentAmount = $principalPayment + $interestPayment;
            }

            // Calculate payment date
            $paymentDate = self::calculatePaymentDate($startDate, $i, $interestCycle);

            // Update remaining balance
            $remainingBalance -= $principalPayment;

            $schedules[] = [
                'loan_id' => $loan->id,
                'payment_number' => $i,
                'payment_date' => $paymentDate,
                'principal_payment' => round($principalPayment, 2),
                'interest_payment' => round($interestPayment, 2),
                'total_payment' => round($paymentAmount, 2),
                'remaining_balance' => round(max($remainingBalance, 0), 2),
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        // Insert all schedules
        self::insert($schedules);
    }

    /**
     * Get payments per year based on interest cycle
     */
    private static function getPaymentsPerYear($interestCycle)
    {
        switch (strtolower($interestCycle)) {
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
    private static function calculatePaymentDate($startDate, $paymentNumber, $interestCycle)
    {
        $start = \Carbon\Carbon::parse($startDate);
        
        switch (strtolower($interestCycle)) {
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