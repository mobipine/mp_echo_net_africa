<?php

namespace App\Filament\Resources;

use App\Filament\Resources\LoanResource\Pages;
use App\Filament\Resources\LoanResource\RelationManagers;
use App\Models\Loan;
use App\Models\LoanAttribute;
use App\Models\LoanProduct;
use App\Models\Transaction;
use App\Models\LoanAmortizationSchedule;
use App\Models\ChartofAccounts;
use BezhanSalleh\FilamentShield\Contracts\HasShieldPermissions;
use Filament\Forms;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Support\RawJs;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Tables\Actions\Action;
use Filament\Tables\Actions\ActionGroup;
use Filament\Tables\Filters\SelectFilter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Carbon\Carbon;

class LoanResource extends Resource implements HasShieldPermissions
{
    public static function getPermissionPrefixes(): array
    {
        return [
            'view',
            'view_any',
            'create',
            'update',
            'delete',
            'delete_any',
            'force_delete',
            'force_delete_any',
            'restore',
            'restore_any',
            'replicate',
            'reorder',
            'approve',
            'reject',
            'reverse_reject',
        ];
    }

    protected static ?string $model = Loan::class;

    protected static ?string $navigationIcon = 'heroicon-o-banknotes';

    protected static ?string $navigationGroup = 'Loan Management';

    public static function getNavigationBadge(): ?string
    {
        return static::getModel()::count();
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Select::make('loan_product_id')
                    ->label('Loan Product')
                    ->native(false)
                    ->options(LoanProduct::all()->pluck('name', 'id')->toArray())
                    ->reactive()

                    ->required(),

                TextInput::make('loan_number')
                    ->label('Loan Number')
                    ->default(function () {
                        return 'LN' . str_pad(Loan::count() + 1, 6, '0', STR_PAD_LEFT);
                    })
                    ->required()
                    ->readOnly(),

                Forms\Components\TextInput::make('status')
                    ->default('Pending Approval')
                    ->readOnly(),



                Forms\Components\TextInput::make('loan_duration')
                    ->label('Loan Duration')
                    // ->numeric()
                    ->placeholder('Enter Loan Duration depending on the interest cycle')
                    ->formatStateUsing(function ($state, $record) {
                        return self::formatDuration($state, $record);
                    })
                    // ->helperText('Enter Loan Duration depending on the interest cycle')
                    ->required(),

                Forms\Components\TextInput::make('principal_amount')
                    ->label('Principal Amount')
                    ->placeholder('Enter requested loan amount')
                    ->required()
                    ->reactive()
                    ->mask(RawJs::make('$money($input)'))
                    ->stripCharacters(',')
                    ->numeric(),

                Forms\Components\DatePicker::make('release_date')
                    ->label('Release Date')
                    ->reactive()
                    ->native(false)
                    ->displayFormat('d/m/Y')
                    ->timezone('Africa/Nairobi')
                    ->locale('en')
                    ->required(),

                Forms\Components\DatePicker::make('due_at')
                    ->label('Due Date')
                    ->displayFormat('d/m/Y')
                    ->timezone('Africa/Nairobi')
                    ->locale('en')
                    ->required()
                    ->native(false)
                    // ->hidden()
                    ->readOnly(),

                Forms\Components\TextInput::make('repayment_amount')
                    ->label('Repayment Amount')
                    ->numeric()
                    ->required()
                    ->mask(RawJs::make('$money($input)'))
                    ->stripCharacters(',')
                    ->readOnly(),

                Forms\Components\TextInput::make('interest_amount')
                    ->label('Interest Amount')
                    ->numeric()
                    ->required()
                    ->mask(RawJs::make('$money($input)'))
                    ->stripCharacters(',')
                    ->readOnly(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('member.name')
                    ->label('Member Name')
                    ->sortable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('loanProduct.name')
                    ->label('Loan Product')
                    ->numeric()
                    ->sortable(),

                Tables\Columns\TextColumn::make('principal_amount')
                    ->numeric()
                    ->sortable(),

                // Tables\Columns\TextColumn::make('status')
                //     ->searchable(),
                Tables\Columns\BadgeColumn::make('status')
                    ->colors([
                        'yellow' => 'Pending Approval',
                        'primary' => 'Approved',
                        'danger' => 'Rejected',
                        'blue' => 'Fully Repaid',
                        'fuchsia' => 'Incomplete Application',
                    ])
                    ->sortable()
                    ->searchable(),


                Tables\Columns\TextColumn::make('release_date')
                    ->date()
                    ->searchable()
                    // ->format('Y-m-d')
                    ->sortable(),
                //loan duration is a select field, so we can use it directly
                Tables\Columns\TextColumn::make('loan_duration')
                    //get the loan cycle of the loan product and append it to the duration
                    ->formatStateUsing(function ($state, $record) {
                        return self::formatDuration($state, $record);
                    })
                    ->label('Duration')
                    ->sortable()
                    ->searchable(),

                Tables\Columns\TextColumn::make('due_at')
                    ->dateTime()
                    ->sortable(),
                Tables\Columns\TextColumn::make('approvedBy.name')
                    ->label('Approved By')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('approved_at')
                    ->label('Approved At')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('remaining_balance')
                    ->label('Remaining Balance')
                    ->money('KES')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options([
                        'Pending Approval' => 'Pending Approval',
                        'Approved' => 'Approved',
                        'Rejected' => 'Rejected',
                        'Fully Repaid' => 'Fully Repaid',
                        'Incomplete Application' => 'Incomplete Application',
                    ]),
                SelectFilter::make('is_completed')
                    ->label('Application Status')
                    ->options([
                        true => 'Complete',
                        false => 'Incomplete',
                    ]),
                // ->query(fn (Builder $query): Builder => $query->whereNotNull('session_data')),
            ])
            ->actions([
                ActionGroup::make([
                    Tables\Actions\ViewAction::make(),

                    Action::make('approve')
                        ->label('Approve')
                        ->icon('heroicon-o-check-circle')
                        ->color('success')
                        ->visible(fn(Loan $record): bool => $record->status === 'Pending Approval' && Gate::allows('approve_loan'))
                        ->form([
                            Forms\Components\TextInput::make('approved_amount')
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
                                ->mask(\Filament\Support\RawJs::make('$money($input)'))
                                ->stripCharacters(',')
                                ->columnSpanFull(),
                        ])
                        ->modalHeading('Approve Loan')
                        ->modalDescription('Is this the amount you want to approve? You can reduce it but cannot increase it.')
                        ->action(function (Loan $record, array $data) {
                            $appliedAmount = $record->applied_amount ?? $record->principal_amount;
                            $approvedAmount = (float) str_replace(',', '', $data['approved_amount']);

                            // Validate approved amount is not greater than applied
                            if ($approvedAmount > $appliedAmount) {
                                \Filament\Notifications\Notification::make()
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
                                \Filament\Notifications\Notification::make()
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
                            LoanAmortizationSchedule::generateSchedule($record);

                            // Create transactions only when loan is approved (using approved amount)
                            static::createLoanTransactions($record);

                            \Filament\Notifications\Notification::make()
                                ->success()
                                ->title('Loan Approved')
                                ->body('The loan has been approved with amount ' . number_format($approvedAmount, 2) . ', transactions created, and amortization schedule generated.')
                                ->send();
                        }),

                    Action::make('reject')
                        ->label('Reject')
                        ->icon('heroicon-o-x-circle')
                        ->color('danger')
                        ->visible(fn(Loan $record): bool => $record->status === 'Pending Approval' && Gate::allows('reject_loan'))
                        ->form([
                            Forms\Components\Textarea::make('rejection_reason')
                                ->label('Rejection Reason')
                                ->placeholder('Please provide a reason for rejecting this loan application...')
                                ->required()
                                ->rows(4)
                                ->columnSpanFull(),
                        ])
                        ->modalHeading('Reject Loan Application')
                        ->modalDescription('Please provide a reason for rejecting this loan application.')
                        ->action(function (Loan $record, array $data) {
                            $record->update([
                                'status' => 'Rejected',
                                'rejected_by' => Auth::id(),
                                'rejected_at' => now(),
                                'rejection_reason' => $data['rejection_reason'] ?? '',
                            ]);

                            \Filament\Notifications\Notification::make()
                                ->success()
                                ->title('Loan Rejected')
                                ->body('The loan application has been rejected with the provided reason.')
                                ->send();
                        }),

                    Action::make('complete_application')
                        ->label('Complete Application')
                        ->icon('heroicon-o-pencil-square')
                        ->color('warning')
                        ->visible(fn(Loan $record): bool => !$record->is_completed)
                        ->url(fn(Loan $record): string => route('filament.admin.pages.loan-application', ['loan_id' => $record->id])),

                    Action::make('reverse_reject')
                        ->label('Reverse Rejection')
                        ->icon('heroicon-o-arrow-left')
                        ->color('warning')
                        ->visible(fn(Loan $record): bool => $record->status === 'Rejected' && Gate::allows('reverse_reject_loan'))
                        ->action(function (Loan $record) {
                            $record->update([
                                'status' => 'Pending Approval',
                            ]);
                        }),
                ]),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            RelationManagers\LoanRepaymentsRelationManager::class,
            RelationManagers\TransactionsRelationManager::class,
            RelationManagers\LoanAmortizationScheduleRelationManager::class,
        ];
    }

    //check if user has member_id and oly show loans for members in the same group
    public static function getEloquentQuery(): Builder
    {
        $member_id = Auth::user()->member_id;
        if ($member_id) {
            $member = \App\Models\Member::find($member_id);
            if ($member) {
                // Get all group IDs the member belongs to
                $groupIds = $member->groups()->pluck('groups.id')->toArray();
                // Fallback to legacy group_id if no groups found
                if (empty($groupIds) && $member->group_id) {
                    $groupIds = [$member->group_id];
                }
                
                return parent::getEloquentQuery()
                    ->where('member_id', $member_id)
                    ->orWhereHas('member.groups', function ($query) use ($groupIds) {
                        $query->whereIn('groups.id', $groupIds);
                    });
            }
        }
        return parent::getEloquentQuery();
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListLoans::route('/'),
            'create' => Pages\CreateLoan::route('/create'),
            'view' => Pages\ViewLoan::route('/{record}'),
            'edit' => Pages\EditLoan::route('/{record}/edit'),
        ];
    }

    public static function formatDuration($state, $record)
    {
        // return $state . ' ' . $record->loanProduct->interest_cycle;

        $slug = 'interest_cycle';
        $attributeId = LoanAttribute::where('slug', $slug)->first()->id;
        $loan_product = $record->loanProduct;


        $loanAttribute = $loan_product->loanProductAttributes()->where('loan_attribute_id', $attributeId)->first();
        // dd($loanAttribute, $loanAttribute->value);
        if ($loanAttribute?->value == "Daily") {
            $units = 'days';
        } elseif ($loanAttribute?->value == "Weekly") {
            $units = 'weeks';
        } elseif ($loanAttribute?->value == "Monthly") {
            $units = 'months';
        } elseif ($loanAttribute?->value == "Yearly") {
            $units = 'years';
        } else {
            $units = 'N/A';
        }
        return $state . ' ' . $units;
    }

    /**
     * Get account name for a given account type from loan product
     */
    private static function getAccountNameFromLoanProduct(Loan $loan, string $accountType): ?string
    {
        return $loan->loanProduct->getAccountName($accountType);
    }

    /**
     * Get account number for a given account type from loan product
     */
    private static function getAccountNumberFromLoanProduct(Loan $loan, string $accountType): ?string
    {
        return $loan->loanProduct->getAccountNumber($accountType);
    }

    /**
     * Get account number for a given account name
     */
    private static function getAccountNumber(string $accountName): ?string
    {
        $account = ChartofAccounts::where('name', $accountName)->first();
        return $account?->account_code;
    }

    /**
     * Create transactions when loan is approved
     */
    public static function createLoanTransactions(Loan $loan)
    {
        $attributes = $loan->all_attributes;
        $loanCharges = (float) ($attributes['loan_charges']['value'] ?? 0);
        $applyChargesOnIssuance = config('repayment_priority.charges.apply_on_issuance', true);
        $deductFromPrincipal = config('repayment_priority.charges.deduct_from_principal', false);

        // Calculate net disbursement amount
        $netDisbursement = $loan->principal_amount;
        if ($applyChargesOnIssuance && $loanCharges > 0) {
            if ($deductFromPrincipal) {
                $netDisbursement = $loan->principal_amount - $loanCharges;
            }
        }

        // Get member's group for group-level accounting (use first group)
        $group = $loan->member->groups()->first() ?? $loan->member->group;
        $groupId = $group ? $group->id : null;

        // Create loan receivable transaction (GROUP ACCOUNT)
        Transaction::create([
            'account_name' => "{$group->name} - Loans Receivable",
            'account_number' => "G{$groupId}-1101",//loans receivable account number
            'loan_id' => $loan->id,
            'member_id' => $loan->member_id,
            'group_id' => $groupId,
            'transaction_type' => 'loan_issue',//very important!
            'dr_cr' => 'dr',
            'amount' => $loan->principal_amount,
            'transaction_date' => $loan->release_date,
            'description' => "Loan issued to member {$loan->member->name}",
        ]);

        // Create bank disbursement transaction (GROUP ACCOUNT)
        Transaction::create([
            'account_name' => "{$group->name} - Bank Account",
            'account_number' => "G{$groupId}-1001",//bank account number
            'loan_id' => $loan->id,
            'member_id' => $loan->member_id,
            'group_id' => $groupId,
            'transaction_type' => 'loan_issue',
            'dr_cr' => 'cr',
            'amount' => $netDisbursement,
            'transaction_date' => $loan->release_date,
            'description' => "Bank disbursement for loan issued to member {$loan->member->name}",
        ]);

        // Create loan charges transactions if applicable
        if ($applyChargesOnIssuance && $loanCharges > 0) {
            // Credit loan charges income (GROUP ACCOUNT)
            Transaction::create([
                'account_name' => "{$group->name} - Loan Charges Income",
                'account_number' => "G{$groupId}-4001",//loan charges income account number
                'loan_id' => $loan->id,
                'member_id' => $loan->member_id,
                'group_id' => $groupId,
                'transaction_type' => 'loan_charges',
                'dr_cr' => 'cr',
                'amount' => $loanCharges,
                'transaction_date' => $loan->release_date,
                'description' => "Loan charges income for loan issued to member {$loan->member->name}",
            ]);

            // If charges are not deducted from principal, create receivable entry (GROUP ACCOUNT)
            if (!$deductFromPrincipal) {
                Transaction::create([
                    'account_name' => "{$group->name} - Loan Charges Receivable",
                    'account_number' => "G{$groupId}-1102",//loan charges receivable account number
                    'loan_id' => $loan->id,
                    'member_id' => $loan->member_id,
                    'group_id' => $groupId,
                    'transaction_type' => 'loan_charges',
                    'dr_cr' => 'dr',
                    'amount' => $loanCharges,
                    'transaction_date' => $loan->release_date,
                    'description' => "Loan charges receivable for loan issued to member {$loan->member->name}",
                ]);
            }
        }
    }
}
