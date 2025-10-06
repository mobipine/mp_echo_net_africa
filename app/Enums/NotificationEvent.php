<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum NotificationEvent: string implements HasLabel
{
    // Case Name (What you use in code) => Value (What's saved in DB)
    case LoanApproved = 'loan_approved';
    case LoanRejected = 'loan_rejected';
    case LoanDisbursed = 'loan_disbursed';
    case RepaymentDue = 'repayment_due';
    case RepaymentReceived = 'repayment_received';
    case LoanMatured = 'loan_matured';

    /**
     * Define the human-readable label for each case.
     */
    public function getLabel(): ?string
    {
        return match ($this) {
            self::LoanApproved => 'Loan Approved',
            self::LoanRejected => 'Loan Rejected',
            self::LoanDisbursed => 'Loan Disbursed to Account',
            self::RepaymentDue => 'Repayment Due (Reminder)',
            self::RepaymentReceived => 'Repayment Received',
            self::LoanMatured => 'Loan Matured / Final Payment',
        };
    }
}