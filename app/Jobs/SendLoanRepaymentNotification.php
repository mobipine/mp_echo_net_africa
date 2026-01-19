<?php

namespace App\Jobs;

use App\Models\LoanRepayment;
use App\Notifications\LoanRepaymentRecorded;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

class SendLoanRepaymentNotification implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    // Hard-coded for now as requested (admin/test inbox)
    private const ADMIN_EMAIL = 'royimwangi@gmail.com';

    public function __construct(private readonly int $repaymentId)
    {
        // In local environment, send immediately without queue
        if (config('app.env') === 'local') {
            $this->connection = 'sync';
        }
    }

    public function handle(): void
    {
        $repayment = LoanRepayment::with(['loan', 'member'])->find($this->repaymentId);

        if (!$repayment) {
            Log::warning('Loan repayment notification skipped: repayment not found', [
                'repayment_id' => $this->repaymentId,
            ]);
            return;
        }

        $memberEmail = $repayment->member?->email;

        if ($memberEmail) {
            Notification::route('mail', $memberEmail)
                ->notify(new LoanRepaymentRecorded($repayment));

            Log::info('Loan repayment notification sent to member', [
                'repayment_id' => $this->repaymentId,
                'member_id' => $repayment->member?->id,
                'member_email' => $memberEmail,
                'amount' => $repayment->amount,
            ]);
        } else {
            Log::info('Loan repayment notification skipped for member: missing email', [
                'member_id' => $repayment->member?->id,
                'repayment_id' => $this->repaymentId,
            ]);
        }

        Notification::route('mail', self::ADMIN_EMAIL)
            ->notify(new LoanRepaymentRecorded($repayment, true));

        Log::info('Loan repayment notification sent to admin', [
            'repayment_id' => $this->repaymentId,
            'admin_email' => self::ADMIN_EMAIL,
            'amount' => $repayment->amount,
        ]);
    }
}

