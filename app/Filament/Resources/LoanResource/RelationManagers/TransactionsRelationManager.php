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
use Filament\Tables\Actions\ViewAction;
use Filament\Tables\Actions\EditAction;
use Filament\Tables\Actions\DeleteAction;

class TransactionsRelationManager extends RelationManager
{
    protected static string $relationship = 'transactions';

    protected static ?string $title = 'Loan Transactions';

    protected static ?string $modelLabel = 'Transaction';

    protected static ?string $pluralModelLabel = 'Transactions';

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Grid::make(2)->schema([
                    TextInput::make('account_name')
                        ->label('Account Name')
                        ->required()
                        ->maxLength(255),
                        
                    Select::make('transaction_type')
                        ->label('Transaction Type')
                        ->options([
                            'loan_issue' => 'Loan Issue',
                            'principal_payment' => 'Principal Payment',
                            'interest_payment' => 'Interest Payment',
                            'charges_payment' => 'Charges Payment',
                            'principal_payment_reversal' => 'Principal Payment Reversal',
                            'interest_payment_reversal' => 'Interest Payment Reversal',
                            'charges_payment_reversal' => 'Charges Payment Reversal',
                            'interest_accrual' => 'Interest Accrual',
                            'penalty' => 'Penalty',
                            'adjustment' => 'Adjustment',
                        ])
                        ->required(),
                        
                    Select::make('dr_cr')
                        ->label('Debit/Credit')
                        ->options([
                            'dr' => 'Debit',
                            'cr' => 'Credit',
                        ])
                        ->required(),
                        
                    TextInput::make('amount')
                        ->label('Amount')
                        ->numeric()
                        ->prefix('KES')
                        ->required(),
                        
                    DatePicker::make('transaction_date')
                        ->label('Transaction Date')
                        ->required()
                        ->default(now()),
                        
                    Textarea::make('description')
                        ->label('Description')
                        ->rows(3)
                        ->maxLength(1000),
                ]),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('account_name')
            ->columns([
                TextColumn::make('id')
                    ->label('ID')
                    ->sortable()
                    ->weight('bold')
                    ->toggleable(),
                    
                TextColumn::make('account_name')
                    ->label('Account')
                    ->searchable()
                    ->sortable(),
                    
                BadgeColumn::make('transaction_type')
                    ->label('Type')
                    ->colors([
                        'primary' => 'loan_issue',
                        'success' => 'principal_payment',
                        'warning' => 'interest_payment',
                        'info' => 'charges_payment',
                        'danger' => 'principal_payment_reversal',
                        'secondary' => 'interest_payment_reversal',
                        'gray' => 'charges_payment_reversal',
                        'purple' => 'interest_accrual',
                        'orange' => 'penalty',
                        'slate' => 'adjustment',
                    ])
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'loan_issue' => 'Loan Issue',
                        'principal_payment' => 'Principal Payment',
                        'interest_payment' => 'Interest Payment',
                        'charges_payment' => 'Charges Payment',
                        'principal_payment_reversal' => 'Principal Payment Reversal',
                        'interest_payment_reversal' => 'Interest Payment Reversal',
                        'charges_payment_reversal' => 'Charges Payment Reversal',
                        'interest_accrual' => 'Interest Accrual',
                        'penalty' => 'Penalty',
                        'adjustment' => 'Adjustment',
                        default => $state,
                    }),
                    
                BadgeColumn::make('dr_cr')
                    ->label('Debit/Credit')
                    ->colors([
                        'rose' => 'dr',
                        'success' => 'cr',
                    ])
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'dr' => 'Debit',
                        'cr' => 'Credit',
                        default => $state,
                    }),
                    
                TextColumn::make('amount')
                    ->label('Amount')
                    ->money('KES')
                    ->weight('bold')
                    ->sortable(),
                    
                TextColumn::make('transaction_date')
                    ->label('Date')
                    ->dateTime()
                    ->sortable(),
                    
                TextColumn::make('description')
                    ->label('Description')
                    ->limit(50)
                    ->tooltip(function (TextColumn $column): ?string {
                        $state = $column->getState();
                        return strlen($state) > 50 ? $state : null;
                    }),
                    
                TextColumn::make('created_at')
                    ->label('Created')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('transaction_type')
                    ->label('Transaction Type')
                    ->options([
                        'loan_issue' => 'Loan Issue',
                        'principal_payment' => 'Principal Payment',
                        'interest_payment' => 'Interest Payment',
                        'charges_payment' => 'Charges Payment',
                        'principal_payment_reversal' => 'Principal Payment Reversal',
                        'interest_payment_reversal' => 'Interest Payment Reversal',
                        'charges_payment_reversal' => 'Charges Payment Reversal',
                        'interest_accrual' => 'Interest Accrual',
                        'penalty' => 'Penalty',
                        'adjustment' => 'Adjustment',
                    ]),
                    
                SelectFilter::make('dr_cr')
                    ->label('Debit/Credit')
                    ->options([
                        'dr' => 'Debit',
                        'cr' => 'Credit',
                    ]),
            ])
            ->headerActions([
                Tables\Actions\CreateAction::make()
                    ->label('Add Transaction')
                    ->icon('heroicon-o-plus'),
            ])
            ->actions([
                ViewAction::make(),
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('id', 'asc');
    }
}
