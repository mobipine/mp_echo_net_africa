<?php

namespace App\Filament\Resources\LoanResource\RelationManagers;

use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Forms\Components\Grid;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Textarea;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\BadgeColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Actions\ViewAction;
use Filament\Tables\Actions\EditAction;
use Filament\Tables\Actions\DeleteAction;
use Filament\Tables\Actions\CreateAction;
use Filament\Tables\Actions\ActionGroup;
use Filament\Tables\Actions\BulkActionGroup;
use Filament\Tables\Actions\DeleteBulkAction;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use App\Models\Transaction;
use App\Services\RepaymentAllocationService;
use Illuminate\Support\Facades\Log;

class LoanRepaymentsRelationManager extends RelationManager
{
    protected static string $relationship = 'repayments';

    protected static ?string $title = 'Loan Repayments';

    protected static ?string $modelLabel = 'Repayment';

    protected static ?string $pluralModelLabel = 'Repayments';
    
    // Override the default read-only behavior when viewed from ViewRecord
    public function isReadOnly(): bool
    {
        return false; // Always allow editing regardless of parent page
    }
       

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Grid::make(2)->schema([
                    TextInput::make('amount')
                        ->label('Repayment Amount')
                        ->numeric()
                        ->prefix('KES')
                        ->required()
                        ->minValue(0.01)
                        ->step(0.01),
                        
                    DatePicker::make('repayment_date')
                        ->label('Repayment Date')
                        ->required()
                        ->default(now())
                        ->maxDate(now()),
                        
                    Select::make('payment_method')
                        ->label('Payment Method')
                        ->options([
                            'cash' => 'Cash',
                            'bank_transfer' => 'Bank Transfer',
                            'mobile_money' => 'Mobile Money',
                            'cheque' => 'Cheque',
                            'other' => 'Other',
                        ])
                        ->required()
                        ->native(false),
                        
                    TextInput::make('reference_number')
                        ->label('Reference Number')
                        ->maxLength(255)
                        ->placeholder('Transaction reference or receipt number'),
                        
                    Textarea::make('notes')
                        ->label('Notes')
                        ->rows(3)
                        ->maxLength(1000)
                        ->placeholder('Additional notes about this repayment')
                        ->columnSpanFull(),
                ]),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('amount')
            ->modifyQueryUsing(function (Builder $query) {
                // Debug: Log the query to see if records are being loaded
                Log::info('LoanRepayments Query:', ['count' => $query->count()]);
                return $query->orderBy('repayment_date', 'desc');
            })
            ->columns([
                TextColumn::make('amount')
                    ->label('Amount')
                    ->money('KES')
                    ->weight('bold')
                    ->sortable(),
                    
                TextColumn::make('repayment_date')
                    ->label('Date')
                    ->date('M j, Y')
                    ->sortable(),
                    
                BadgeColumn::make('payment_method')
                    ->label('Payment Method')
                    ->colors([
                        'primary' => 'cash',
                        'success' => 'bank_transfer',
                        'warning' => 'mobile_money',
                        'secondary' => 'cheque',
                        'gray' => 'other',
                    ])
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'cash' => 'Cash',
                        'bank_transfer' => 'Bank Transfer',
                        'mobile_money' => 'Mobile Money',
                        'cheque' => 'Cheque',
                        'other' => 'Other',
                        default => ucfirst(str_replace('_', ' ', $state)),
                    }),
                    
                TextColumn::make('reference_number')
                    ->label('Reference')
                    ->searchable()
                    ->placeholder('N/A')
                    ->toggleable(),
                    
                TextColumn::make('notes')
                    ->label('Notes')
                    ->limit(30)
                    ->tooltip(function (TextColumn $column): ?string {
                        $state = $column->getState();
                        return strlen($state) > 30 ? $state : null;
                    })
                    ->placeholder('No notes')
                    ->toggleable(isToggledHiddenByDefault: true),
                    
                TextColumn::make('created_at')
                    ->label('Recorded')
                    ->dateTime('M j, Y g:i A')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('payment_method')
                    ->label('Payment Method')
                    ->options([
                        'cash' => 'Cash',
                        'bank_transfer' => 'Bank Transfer',
                        'mobile_money' => 'Mobile Money',
                        'cheque' => 'Cheque',
                        'other' => 'Other',
                    ]),
                    
                Filter::make('repayment_date')
                    ->form([
                        DatePicker::make('from')
                            ->label('From Date'),
                        DatePicker::make('until')
                            ->label('Until Date'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when(
                                $data['from'],
                                fn (Builder $query, $date): Builder => $query->whereDate('repayment_date', '>=', $date),
                            )
                            ->when(
                                $data['until'],
                                fn (Builder $query, $date): Builder => $query->whereDate('repayment_date', '<=', $date),
                            );
                    }),
                    
                Filter::make('recent')
                    ->label('Recent (Last 30 days)')
                    ->query(fn (Builder $query): Builder => $query->where('repayment_date', '>=', now()->subDays(30))),
            ])
            ->headerActions([])
            ->actions([
                ActionGroup::make([
                    ViewAction::make()
                        ->label('View Details')
                        ->icon('heroicon-o-eye')
                        ->modalHeading(fn ($record) => 'Repayment Details - KES ' . number_format($record->amount, 2)),
                        
                    EditAction::make()
                    //TODO: create a custom permission for this action
                        ->label('Edit Repayment')
                        ->icon('heroicon-o-pencil')
                        ->modalHeading('Edit Repayment')
                        ->modalSubmitActionLabel('Update Repayment')
                        ->mutateFormDataUsing(function (array $data, $record): array {
                            // Store the original amount for comparison
                            $data['original_amount'] = $record->amount;
                            return $data;
                        })
                        ->using(function (Model $record, array $data): Model {
                            $originalAmount = $record->amount;
                            $newAmount = (float) $data['amount'];
                            $difference = $newAmount - $originalAmount;
                            
                            // Remove the temporary field before updating
                            unset($data['original_amount']);
                            
                            // Update the repayment record
                            $record->update($data);
                            
                            // If there's a difference, create adjustment transactions
                            if ($difference != 0) {
                                $this->createAdjustmentTransactions($record, $difference, $originalAmount, $newAmount);
                            }
                            
                            // Check and update loan status if needed
                            $this->updateLoanStatusIfNeeded($record);
                            
                            return $record;
                        })
                        ->after(function ($record) {
                            $loan = $record->loan ?? $this->ownerRecord;
                            $loan->refresh();
                            
                            $message = 'The repayment has been updated successfully.';
                            
                            // Add status change notification if applicable
                            if ($loan->status === 'Approved' && $loan->remaining_balance > 0) {
                                $message .= ' Note: The loan status has been reverted from "Fully Repaid" to "Approved" due to outstanding balance.';
                            } elseif ($loan->status === 'Fully Repaid' && $loan->remaining_balance <= 0) {
                                $message .= ' Note: The loan status has been updated to "Fully Repaid".';
                            }
                            
                            Notification::make()
                                ->title('Repayment Updated')
                                ->body($message)
                                ->success()
                                ->send();
                        }),
                        
                    // DeleteAction::make()
                    //     ->label('Delete Repayment')
                    //     ->icon('heroicon-o-trash')
                    //     ->requiresConfirmation()
                    //     ->modalHeading('Delete Repayment')
                    //     ->modalDescription('Are you sure you want to delete this repayment? This action cannot be undone.')
                    //     ->modalSubmitActionLabel('Yes, delete it')
                    //     ->before(function ($record) {
                    //         // Delete related transactions before deleting the repayment
                    //         Transaction::where('repayment_id', $record->id)->delete();
                    //     })
                    //     ->after(function () {
                    //         Notification::make()
                    //             ->title('Repayment Deleted')
                    //             ->body('The repayment and its related transactions have been deleted successfully.')
                    //             ->success()
                    //             ->send();
                    //     }),
                ])
                ->label('Actions')
                ->icon('heroicon-m-ellipsis-vertical')
                ->size('sm')
                ->color('gray')
                ->button(),
            ])
            ->bulkActions([
                BulkActionGroup::make([
                    // DeleteBulkAction::make()
                    //     ->requiresConfirmation()
                    //     ->modalHeading('Delete Selected Repayments')
                    //     ->modalDescription('Are you sure you want to delete the selected repayments? This action cannot be undone.')
                    //     ->before(function ($records) {
                    //         // Delete related transactions for all selected repayments
                    //         $repaymentIds = $records->pluck('id');
                    //         Transaction::whereIn('repayment_id', $repaymentIds)->delete();
                    //     })
                    //     ->after(function () {
                    //         Notification::make()
                    //             ->title('Repayments Deleted')
                    //             ->body('The selected repayments and their related transactions have been deleted successfully.')
                    //             ->success()
                    //             ->send();
                    //     }),
                ]),
            ])
            ->defaultSort('repayment_date', 'desc')
            ->emptyStateHeading('No Repayments Recorded')
            ->emptyStateDescription('This loan has no repayment records yet. Click "Record Repayment" to add the first repayment.')
            ->emptyStateIcon('heroicon-o-currency-dollar')
            ->striped()
            ->paginated([10, 25, 50])
            ->poll('30s'); // Auto-refresh every 30 seconds
    }

    /**
     * Create adjustment transactions when repayment amount is edited
     */
    private function createAdjustmentTransactions($repayment, float $difference, float $originalAmount, float $newAmount): void
    {
        $loan = $repayment->loan ?? $this->ownerRecord;
        $adjustmentDescription = "Repayment adjustment: KES " . number_format($originalAmount, 2) . 
                               " → KES " . number_format($newAmount, 2) . 
                               " (Difference: KES " . number_format(abs($difference), 2) . ")";
        
        try {
            if ($difference > 0) {
                // Increase in repayment amount - create additional transactions
                $this->createAdditionalRepaymentTransactions($repayment, abs($difference), $adjustmentDescription);
            } else {
                // Decrease in repayment amount - create reversal transactions
                $this->createReversalTransactions($repayment, abs($difference), $adjustmentDescription);
            }
        } catch (\Exception $e) {
            Log::error('Error creating adjustment transactions: ' . $e->getMessage(), [
                'repayment_id' => $repayment->id,
                'loan_id' => $loan->id,
                'difference' => $difference,
            ]);
        }
    }
    
    /**
     * Create additional repayment transactions for increased amount
     */
    private function createAdditionalRepaymentTransactions($repayment, float $amount, string $description): void
    {
        $loan = $repayment->loan ?? $this->ownerRecord;
        
        // Get account info from loan product, fallback to config
        $bankAccountName = $this->getAccountNameFromLoanProduct($loan, 'bank') ?? config('repayment_priority.accounts.bank');
        $bankAccountNumber = $this->getAccountNumberFromLoanProduct($loan, 'bank');
        
        // For fully repaid loans, we need to determine what the additional amount should go to
        // Since the loan is fully repaid, any additional payment should go to principal
        $loansReceivableName = $this->getAccountNameFromLoanProduct($loan, 'loans_receivable') ?? config('repayment_priority.accounts.loans_receivable');
        $loansReceivableNumber = $this->getAccountNumberFromLoanProduct($loan, 'loans_receivable');
        
        // Create principal payment transactions for the additional amount
        Transaction::create([
            'account_name' => $bankAccountName,
            'account_number' => $bankAccountNumber,
            'loan_id' => $loan->id,
            'member_id' => $repayment->member_id,
            'repayment_id' => $repayment->id,
            'transaction_type' => 'principal_payment',
            'dr_cr' => 'dr',
            'amount' => $amount,
            'transaction_date' => $repayment->repayment_date,
            'description' => $description,
        ]);
        
        Transaction::create([
            'account_name' => $loansReceivableName,
            'account_number' => $loansReceivableNumber,
            'loan_id' => $loan->id,
            'member_id' => $repayment->member_id,
            'repayment_id' => $repayment->id,
            'transaction_type' => 'principal_payment',
            'dr_cr' => 'cr',
            'amount' => $amount,
            'transaction_date' => $repayment->repayment_date,
            'description' => $description,
        ]);
    }
    
    /**
     * Create reversal transactions for decreased amount
     */
    private function createReversalTransactions($repayment, float $amount, string $description): void
    {
        $loan = $repayment->loan ?? $this->ownerRecord;
        
        // Get account info from loan product, fallback to config
        $bankAccountName = $this->getAccountNameFromLoanProduct($loan, 'bank') ?? config('repayment_priority.accounts.bank');
        $bankAccountNumber = $this->getAccountNumberFromLoanProduct($loan, 'bank');
        
        // For reversal transactions, we need to reverse the principal payment
        // This will create an outstanding balance on the loan
        $loansReceivableName = $this->getAccountNameFromLoanProduct($loan, 'loans_receivable') ?? config('repayment_priority.accounts.loans_receivable');
        $loansReceivableNumber = $this->getAccountNumberFromLoanProduct($loan, 'loans_receivable');
        
        // Create principal reversal transactions
        Transaction::create([
            'account_name' => $bankAccountName,
            'account_number' => $bankAccountNumber,
            'loan_id' => $loan->id,
            'member_id' => $repayment->member_id,
            'repayment_id' => $repayment->id,
            'transaction_type' => 'principal_payment_reversal',
            'dr_cr' => 'cr',
            'amount' => $amount,
            'transaction_date' => $repayment->repayment_date,
            'description' => $description,
        ]);
        
        Transaction::create([
            'account_name' => $loansReceivableName,
            'account_number' => $loansReceivableNumber,
            'loan_id' => $loan->id,
            'member_id' => $repayment->member_id,
            'repayment_id' => $repayment->id,
            'transaction_type' => 'principal_payment_reversal',
            'dr_cr' => 'dr',
            'amount' => $amount,
            'transaction_date' => $repayment->repayment_date,
            'description' => $description,
        ]);
    }
    
    /**
     * Update loan status if needed after repayment edit
     */
    private function updateLoanStatusIfNeeded($repayment): void
    {
        $loan = $repayment->loan ?? $this->ownerRecord;
        
        // Refresh the loan to get updated outstanding balance
        $loan->refresh();
        
        // Check if loan was fully repaid and now has outstanding balance
        if ($loan->status === 'Fully Repaid' && $loan->remaining_balance > 0) {
            $loan->update(['status' => 'Approved']);
            
            Log::info('Loan status reverted from Fully Repaid to Approved', [
                'loan_id' => $loan->id,
                'repayment_id' => $repayment->id,
                'remaining_balance' => $loan->remaining_balance,
            ]);
        }
        // Check if loan is now fully repaid after increase
        elseif ($loan->status === 'Approved' && $loan->remaining_balance <= 0) {
            $loan->update(['status' => 'Fully Repaid']);
            
            Log::info('Loan status updated to Fully Repaid', [
                'loan_id' => $loan->id,
                'repayment_id' => $repayment->id,
                'remaining_balance' => $loan->remaining_balance,
            ]);
        }
    }

    /**
     * Get account name for a given account type from loan product
     */
    private function getAccountNameFromLoanProduct($loan, string $accountType): ?string
    {
        return $loan->loanProduct->getAccountName($accountType);
    }

    /**
     * Get account number for a given account type from loan product
     */
    private function getAccountNumberFromLoanProduct($loan, string $accountType): ?string
    {
        return $loan->loanProduct->getAccountNumber($accountType);
    }

    /**
     * Get the appropriate account name based on payment method
     */
    private function getAccountNameForPaymentMethod(string $paymentMethod): string
    {
        return match ($paymentMethod) {
            'cash' => config('repayment_priority.accounts.cash'),
            'bank_transfer' => config('repayment_priority.accounts.bank'),
            'mobile_money' => config('repayment_priority.accounts.mobile_money'),
            'cheque' => config('repayment_priority.accounts.bank'),
            default => config('repayment_priority.accounts.bank'),
        };
    }
}