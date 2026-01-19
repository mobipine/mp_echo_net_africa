<?php

namespace App\Models;

use App\Jobs\SendLoanRepaymentNotification;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Log;

class LoanRepayment extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'loan_id',
        'member_id',
        'amount',
        'repayment_date',
        'payment_method',
        'reference_number',
        'notes',
        'recorded_by',
    ];

    protected $casts = [
        'repayment_date' => 'date',
        'amount' => 'decimal:2',
    ];

    protected static function boot()
    {
        parent::boot();

        static::created(function ($repayment) {
            Log::info('LoanRepaymentObserver: created event triggered', [
                'repayment_id' => $repayment->id,
                'member_id' => $repayment->member_id,
                'loan_id' => $repayment->loan_id,
                'amount' => $repayment->amount,
            ]);

            // Dispatch notification job
            SendLoanRepaymentNotification::dispatch($repayment->id);
            
            Log::info('Loan repayment notification job dispatched', [
                'repayment_id' => $repayment->id,
                'member_id' => $repayment->member_id,
                'loan_id' => $repayment->loan_id,
            ]);

            // Process queue immediately in local environment
            if (config('app.env') === 'local') {
                \Illuminate\Support\Facades\Artisan::call('queue:work', [
                    '--once' => true,
                    '--timeout' => 60
                ]);
                Log::info('Queue processed automatically in local environment');
            }
        });
    }

    public function loan()
    {
        return $this->belongsTo(Loan::class);
    }

    public function member()
    {
        return $this->belongsTo(Member::class);
    }

    public function recordedBy()
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    public function transactions()
    {
        return $this->hasMany(Transaction::class);
    }

    /**
     * Calculate the remaining balance for a loan
     */
    public static function calculateRemainingBalance($loanId)
    {
        $loan = Loan::find($loanId);
        if (!$loan) {
            return 0;
        }

        $totalRepaid = self::where('loan_id', $loanId)->sum('amount');
        return $loan->repayment_amount - $totalRepaid;
    }

    /**
     * Get total repaid amount for a loan
     */
    public static function getTotalRepaid($loanId)
    {
        return self::where('loan_id', $loanId)->sum('amount');
    }
}