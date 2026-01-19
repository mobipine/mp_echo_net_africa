<?php

namespace App\Notifications;

use App\Models\LoanRepayment;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class LoanRepaymentRecorded extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly LoanRepayment $repayment,
        private readonly bool $forAdmin = false
    ) {
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $loan = $this->repayment->loan;
        $member = $this->repayment->member;

        $memberName = $member?->name ?? 'Member';
        $loanNumber = $loan?->loan_number ?? 'N/A';
        $amount = number_format((float) $this->repayment->amount, 2);
        $paymentMethod = ucfirst(str_replace('_', ' ', $this->repayment->payment_method ?? ''));
        $repaymentDate = optional($this->repayment->repayment_date)->format('M d, Y');

        $mail = (new MailMessage())
            ->subject($this->forAdmin
                ? "New loan repayment recorded for {$memberName}"
                : 'Your loan repayment has been received'
            )
            ->greeting($this->forAdmin ? 'Hello Admin,' : "Hello {$memberName},")
            ->line($this->forAdmin
                ? 'A new loan repayment has been submitted.'
                : 'We have recorded your loan repayment. Here are the details:'
            )
            ->line("Loan Number: {$loanNumber}")
            ->line("Amount Paid: KES {$amount}")
            ->line("Payment Method: {$paymentMethod}")
            ->line("Repayment Date: {$repaymentDate}");

        if ($this->forAdmin && $member) {
            $mail->line("Member Email: {$member->email}")
                ->line("Member Phone: {$member->phone}");
        }

        return $mail->line('Thank you for your prompt payment.');
    }
}

