<?php

namespace App\Jobs;

use App\Models\LoanRepayment;
use App\Models\Setting;
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

        // Check if notifications are enabled (master switch)
        if (!Setting::get('loan_notifications.enabled', true)) {
            Log::info('Loan repayment notifications are disabled');
            return;
        }

        // Check if email notifications for repayments are enabled
        if (!Setting::get('loan_repayment.email_enabled', true)) {
            Log::info('Email notifications for loan repayments are disabled');
            return;
        }

        $memberEmail = $repayment->member?->email;

        // Send notification to member if enabled and email exists
        if (Setting::get('loan_notifications.member_notifications', true) && $memberEmail) {
            Notification::route('mail', $memberEmail)
                ->notify(new LoanRepaymentRecorded($repayment));

            Log::info('Loan repayment notification sent to member', [
                'repayment_id' => $this->repaymentId,
                'member_id' => $repayment->member?->id,
                'member_email' => $memberEmail,
                'amount' => $repayment->amount,
            ]);
        } else {
            Log::info('Loan repayment notification skipped for member', [
                'member_id' => $repayment->member?->id,
                'repayment_id' => $this->repaymentId,
                'reason' => !Setting::get('loan_notifications.member_notifications', true) ? 'member_notifications_disabled' : 'missing_email',
            ]);
        }

        // Send notification to admin if enabled
        if (Setting::get('loan_notifications.admin_notifications', true)) {
            $adminEmail = Setting::get('loan_notifications.admin_email');
            
            if ($adminEmail && $adminEmail !== 'admin@example.com') {
                Notification::route('mail', $adminEmail)
                    ->notify(new LoanRepaymentRecorded($repayment, true));

                Log::info('Loan repayment notification sent to admin', [
                    'repayment_id' => $this->repaymentId,
                    'admin_email' => $adminEmail,
                    'amount' => $repayment->amount,
                ]);
            } else {
                Log::warning('Admin email not configured for loan repayment notifications', [
                    'repayment_id' => $this->repaymentId,
                    'admin_email' => $adminEmail,
                ]);
            }
        } else {
            Log::info('Admin notifications are disabled for loan repayments');
        }
    }
}

