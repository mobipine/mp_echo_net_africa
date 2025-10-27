<?php

namespace App\Filament\Resources\GroupResource\RelationManagers;

use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

class GroupAccountsRelationManager extends RelationManager
{
    protected static string $relationship = 'groupAccounts';

    protected static ?string $title = 'Group Accounts';

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('account_code')
                    ->required()
                    ->disabled(),
                
                Forms\Components\TextInput::make('account_name')
                    ->required()
                    ->disabled(),
                
                Forms\Components\Select::make('account_type')
                    ->required()
                    ->disabled()
                    ->options([
                        'group_bank' => 'Bank Account',
                        'group_loans_receivable' => 'Loans Receivable',
                        'group_interest_receivable' => 'Interest Receivable',
                        'group_loan_charges_receivable' => 'Loan Charges Receivable',
                        'group_member_savings' => 'Member Savings',
                        'group_contribution_liability' => 'Contribution Liability',
                        'group_capital_payable' => 'Capital Payable to Organization',
                        'group_interest_income' => 'Interest Income',
                        'group_loan_charges_income' => 'Loan Charges Income',
                        'group_contribution_income' => 'Contribution Income',
                        'group_savings_interest_expense' => 'Savings Interest Expense',
                    ]),
                
                Forms\Components\Select::make('account_nature')
                    ->required()
                    ->disabled()
                    ->options([
                        'asset' => 'Asset',
                        'liability' => 'Liability',
                        'equity' => 'Equity',
                        'revenue' => 'Revenue',
                        'expense' => 'Expense',
                    ]),
                
                Forms\Components\Toggle::make('is_active')
                    ->required(),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('account_code')
                    ->label('Account Code')
                    ->searchable()
                    ->sortable(),
                
                Tables\Columns\TextColumn::make('account_name')
                    ->label('Account Name')
                    ->searchable()
                    ->wrap(),
                
                Tables\Columns\BadgeColumn::make('account_nature')
                    ->label('Nature')
                    ->colors([
                        'success' => 'asset',
                        'danger' => 'liability',
                        'info' => 'equity',
                        'warning' => 'revenue',
                        'gray' => 'expense',
                    ]),
                
                Tables\Columns\TextColumn::make('balance')
                    ->label('Current Balance')
                    ->money('KES')
                    ->getStateUsing(function ($record) {
                        return $record->balance;
                    })
                    ->sortable(),
                
                Tables\Columns\IconColumn::make('is_active')
                    ->label('Active')
                    ->boolean(),
                
                Tables\Columns\TextColumn::make('opening_date')
                    ->label('Opened')
                    ->date()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('account_nature')
                    ->options([
                        'asset' => 'Asset',
                        'liability' => 'Liability',
                        'equity' => 'Equity',
                        'revenue' => 'Revenue',
                        'expense' => 'Expense',
                    ]),
                
                Tables\Filters\TernaryFilter::make('is_active')
                    ->label('Active Status'),
            ])
            ->headerActions([
                // Don't allow manual creation - accounts are auto-created
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
                Tables\Actions\EditAction::make(),
            ])
            ->bulkActions([
                // Don't allow bulk delete
            ])
            ->defaultSort('account_code', 'asc');
    }
}

