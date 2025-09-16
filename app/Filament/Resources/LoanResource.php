<?php

namespace App\Filament\Resources;

use App\Filament\Resources\LoanResource\Pages;
use App\Filament\Resources\LoanResource\RelationManagers;
use App\Models\Loan;
use App\Models\LoanAttribute;
use App\Models\LoanProduct;
use App\Models\Transaction;
use App\Models\LoanAmortizationSchedule;
use Filament\Forms;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Support\RawJs;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Tables\Actions\Action;
use Filament\Tables\Filters\SelectFilter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\Facades\Auth;
use Carbon\Carbon;

class LoanResource extends Resource
{
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
                        'blue' => 'Completed',
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
                        'Completed' => 'Completed',
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
                Tables\Actions\ViewAction::make(),
                Action::make('approve')
                    ->label('Approve')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->visible(fn (Loan $record): bool => $record->status === 'Pending Approval')
                    ->requiresConfirmation()
                    ->modalHeading('Approve Loan')
                    ->modalDescription('Are you sure you want to approve this loan? This will create the necessary transactions.')
                    ->action(function (Loan $record) {
                        $record->update([
                            'status' => 'Approved',
                            'approved_by' => Auth::id(),
                            'approved_at' => now(),
                        ]);
                        
                        // Create transactions only when loan is approved
                        static::createLoanTransactions($record);
                        
                        // Generate amortization schedule
                        LoanAmortizationSchedule::generateSchedule($record);
                        
                        \Filament\Notifications\Notification::make()
                            ->success()
                            ->title('Loan Approved')
                            ->body('The loan has been approved, transactions created, and amortization schedule generated.')
                            ->send();
                    }),
                Action::make('reject')
                    ->label('Reject')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->visible(fn (Loan $record): bool => $record->status === 'Pending Approval')
                    ->requiresConfirmation()
                    ->modalHeading('Reject Loan')
                    ->modalDescription('Are you sure you want to reject this loan application?')
                    ->action(function (Loan $record) {
                        $record->update([
                            'status' => 'Rejected',
                            'approved_by' => Auth::id(),
                            'approved_at' => now(),
                        ]);
                        
                        \Filament\Notifications\Notification::make()
                            ->success()
                            ->title('Loan Rejected')
                            ->body('The loan application has been rejected.')
                            ->send();
                    }),
                Action::make('complete_application')
                    ->label('Complete Application')
                    ->icon('heroicon-o-pencil-square')
                    ->color('warning')
                    ->visible(fn (Loan $record): bool => $record->is_incomplete_application)
                    ->url(fn (Loan $record): string => route('filament.admin.pages.loan-application', ['session_data' => $record->session_data]))
                    ->openUrlInNewTab(),
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
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListLoans::route('/'),
            'create' => Pages\CreateLoan::route('/create'),
            // 'edit' => Pages\EditLoan::route('/{record}/edit'),
        ];
    }

    public static function formatDuration($state, $record) {
        // return $state . ' ' . $record->loanProduct->interest_cycle;

        $slug = 'interest_cycle';
        $attributeId = LoanAttribute::where('slug', $slug)->first()->id;
        $loan_product = $record->loanProduct;


        $loanAttribute = $loan_product->loanProductAttributes()->where('loan_attribute_id', $attributeId)->first();
        // dd($loanAttribute, $loanAttribute->value);
        if($loanAttribute->value == "Daily") {
            $units = 'days';
        } elseif($loanAttribute->value == "Weekly") {
            $units = 'weeks';
        } elseif($loanAttribute->value == "Monthly") {
            $units = 'months';
        } elseif($loanAttribute->value == "Yearly") {
            $units = 'years';
        } else {
            $units = 'N/A';

        }
        return $state . ' ' . $units;
    }

    /**
     * Create transactions when loan is approved
     */
    private static function createLoanTransactions(Loan $loan)
    {
        // Create simplified transaction records
        // Debit: Loans Receivable Account
        Transaction::create([
            'account_name' => 'Loans Receivable',
            'loan_id' => $loan->id,
            'member_id' => $loan->member_id,
            'transaction_type' => 'loan_issue',
            'dr_cr' => 'dr',
            'amount' => $loan->principal_amount,
            'transaction_date' => $loan->release_date,
            'description' => "Loan issued to member {$loan->member->name}",
        ]);

        // Credit: Bank Account
        Transaction::create([
            'account_name' => 'Bank',
            'loan_id' => $loan->id,
            'member_id' => $loan->member_id,
            'transaction_type' => 'loan_issue',
            'dr_cr' => 'cr',
            'amount' => $loan->principal_amount,
            'transaction_date' => $loan->release_date,
            'description' => "Bank payment for loan issued to member {$loan->member->name}",
        ]);
    }
}
