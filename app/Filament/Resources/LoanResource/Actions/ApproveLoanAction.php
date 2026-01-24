<?php

namespace App\Filament\Resources\LoanResource\Actions;

use App\Models\Loan;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Support\RawJs;
use Illuminate\Support\Facades\Auth;

class ApproveLoanAction
{
    /**
     * Get the approve loan action for ViewRecord context
     *
     * @param Loan $record The loan record
     * @return Action
     */
    public static function makeForViewRecord(Loan $record): Action
    {
        return Action::make('approve')
            ->label('Approve Loan')
            ->icon('heroicon-o-check-circle')
            ->color('success')
            ->visible(fn(): bool => $record->status === 'Pending Approval' && \Illuminate\Support\Facades\Gate::allows('approve_loan'))
            ->form([
                TextInput::make('approved_amount')
                    ->label('Approved Amount')
                    ->helperText('You can reduce the amount but cannot increase it. Leave as is to approve the full amount.')
                    ->numeric()
                    ->required()
                    ->default(fn() => $record->applied_amount ?? $record->principal_amount)
                    ->rules([
                        function () use ($record) {
                            return function (string $attribute, $value, \Closure $fail) use ($record) {
                                $appliedAmount = $record->applied_amount ?? $record->principal_amount;
                                if ((float) $value > (float) $appliedAmount) {
                                    $fail('The approved amount cannot be greater than the applied amount (' . number_format($appliedAmount, 2) . ').');
                                }
                            };
                        },
                    ])
                    ->mask(RawJs::make('$money($input)'))
                    ->stripCharacters(',')
                    ->columnSpanFull(),
            ])
            ->modalHeading('Approve Loan')
            ->modalDescription('Is this the amount you want to approve? You can reduce it but cannot increase it.')
            ->action(function (array $data) use ($record) {
                self::approveLoan($record, $data);
            });
    }

    /**
     * Get the approve loan action for RelationManager context
     *
     * @return \Filament\Tables\Actions\Action
     */
    public static function makeForRelationManager(): \Filament\Tables\Actions\Action
    {
        return \Filament\Tables\Actions\Action::make('approve')
            ->label('Approve Loan')
            ->icon('heroicon-o-check-circle')
            ->color('success')
            ->visible(fn(Loan $record): bool => $record->status === 'Pending Approval' && \Illuminate\Support\Facades\Gate::allows('approve_loan'))
            ->form([
                TextInput::make('approved_amount')
                    ->label('Approved Amount')
                    ->helperText('You can reduce the amount but cannot increase it. Leave as is to approve the full amount.')
                    ->numeric()
                    ->required()
                    ->default(fn(Loan $record) => $record->applied_amount ?? $record->principal_amount)
                    ->rules([
                        function (Loan $record) {
                            return function (string $attribute, $value, \Closure $fail) use ($record) {
                                $appliedAmount = $record->applied_amount ?? $record->principal_amount;
                                if ((float) $value > (float) $appliedAmount) {
                                    $fail('The approved amount cannot be greater than the applied amount (' . number_format($appliedAmount, 2) . ').');
                                }
                            };
                        },
                    ])
                    ->mask(RawJs::make('$money($input)'))
                    ->stripCharacters(',')
                    ->columnSpanFull(),
            ])
            ->modalHeading('Approve Loan')
            ->modalDescription('Is this the amount you want to approve? You can reduce it but cannot increase it.')
            ->action(function (Loan $record, array $data) {
                self::approveLoan($record, $data);
            });
    }

    /**
     * Approve a loan with the given data
     *
     * @param Loan $record
     * @param array $data
     * @return void
     */
    public static function approveLoan(Loan $record, array $data): void
    {
        $appliedAmount = $record->applied_amount ?? $record->principal_amount;
        $approvedAmount = (float) str_replace(',', '', $data['approved_amount']);

        // Validate approved amount is not greater than applied
        if ($approvedAmount > $appliedAmount) {
            Notification::make()
                ->danger()
                ->title('Invalid Amount')
                ->body('The approved amount cannot be greater than the applied amount.')
                ->send();
            return;
        }

        // Check if group has sufficient funds
        $group = $record->member->groups()->first() ?? $record->member->group;
        $groupTransactionService = app(\App\Services\GroupTransactionService::class);
        $bankAccount = $groupTransactionService->getGroupAccount($group, 'bank');
        $currentBalance = $groupTransactionService->getGroupAccountBalance($bankAccount);

        // Calculate loan charges to determine actual disbursement amount
        $attributes = $record->all_attributes;
        $loanCharges = (float) ($attributes['loan_charges']['value'] ?? 0);
        $applyChargesOnIssuance = config('repayment_priority.charges.apply_on_issuance', true);
        $deductFromPrincipal = config('repayment_priority.charges.deduct_from_principal', false);

        // Calculate charges based on approved amount (proportional if amount was reduced)
        $chargeRatio = $appliedAmount > 0 ? ($approvedAmount / $appliedAmount) : 1;
        $adjustedLoanCharges = $loanCharges * $chargeRatio;

        $netDisbursement = $approvedAmount;
        if ($applyChargesOnIssuance && $adjustedLoanCharges > 0 && $deductFromPrincipal) {
            $netDisbursement = $approvedAmount - $adjustedLoanCharges;
        }

        // Check if group has sufficient funds
        if ($currentBalance < $netDisbursement) {
            Notification::make()
                ->danger()
                ->title('Insufficient Group Funds')
                ->body("Cannot approve loan. Group '{$group->name}' has insufficient funds. Current balance: KES " . number_format($currentBalance, 2) . ", Required: KES " . number_format($netDisbursement, 2) . ". Please transfer capital to the group first.")
                ->persistent()
                ->send();
            return;
        }

        // Update loan with approved amount
        // Recalculate interest and repayment amounts based on approved amount
        $interestRate = $record->interest_rate;
        $loanDuration = $record->loan_duration;
        $interestAmount = ($approvedAmount * $interestRate * $loanDuration) / 100;
        $repaymentAmount = $approvedAmount + $interestAmount;

        $record->update([
            'status' => 'Approved',
            'approved_by' => Auth::id(),
            'approved_at' => now(),
            'applied_amount' => $appliedAmount, // Store original applied amount
            'principal_amount' => $approvedAmount, // Update to approved amount
            'interest_amount' => $interestAmount,
            'repayment_amount' => $repaymentAmount,
        ]);

        // Generate amortization schedule with approved amount
        \App\Models\LoanAmortizationSchedule::generateSchedule($record);

        // Create transactions only when loan is approved (using approved amount)
        \App\Filament\Resources\LoanResource::createLoanTransactions($record);

        Notification::make()
            ->success()
            ->title('Loan Approved')
            ->body('The loan has been approved with amount ' . number_format($approvedAmount, 2) . ', transactions created, and amortization schedule generated.')
            ->send();
    }
}

