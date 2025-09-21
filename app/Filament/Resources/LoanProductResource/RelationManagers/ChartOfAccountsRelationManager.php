<?php

namespace App\Filament\Resources\LoanProductResource\RelationManagers;

use App\Models\ChartofAccounts;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

class ChartOfAccountsRelationManager extends RelationManager
{
    protected static string $relationship = 'chartOfAccounts';

    protected static ?string $title = 'Chart of Accounts';

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Select::make('account_type')
                    ->label('Account Type')
                    ->options([
                        'bank' => 'Bank Account',
                        'cash' => 'Cash Account',
                        'mobile_money' => 'Mobile Money Account',
                        'loans_receivable' => 'Loans Receivable',
                        'interest_receivable' => 'Interest Receivable',
                        'loan_charges_receivable' => 'Loan Charges Receivable',
                        'interest_income' => 'Interest Income',
                        'loan_charges_income' => 'Loan Charges Income',
                    ])
                    ->required()
                    ->native(false)
                    ->searchable(),

                Forms\Components\Select::make('account_number')
                    ->label('Account')
                    ->options(ChartofAccounts::all()->pluck('name', 'account_code'))
                    ->required()
                    ->native(false)
                    ->searchable()
                    ->helperText('Select the specific account for this account type'),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('account_type')
                    ->label('Account Type')
                    ->formatStateUsing(function ($state) {
                        $types = [
                            'bank' => 'Bank Account',
                            'cash' => 'Cash Account',
                            'mobile_money' => 'Mobile Money Account',
                            'loans_receivable' => 'Loans Receivable',
                            'interest_receivable' => 'Interest Receivable',
                            'loan_charges_receivable' => 'Loan Charges Receivable',
                            'interest_income' => 'Interest Income',
                            'loan_charges_income' => 'Loan Charges Income',
                        ];
                        return $types[$state] ?? $state;
                    })
                    ->sortable()
                    ->searchable(),

                Tables\Columns\TextColumn::make('chartOfAccount.name')
                    ->label('Account Name')
                    ->sortable()
                    ->searchable(),

                Tables\Columns\TextColumn::make('account_number')
                    ->label('Account Number')
                    ->sortable()
                    ->searchable(),

                Tables\Columns\TextColumn::make('chartOfAccount.account_type')
                    ->label('Account Category')
                    ->badge()
                    ->colors([
                        'primary' => 'asset',
                        'success' => 'revenue',
                        'warning' => 'liability',
                        'danger' => 'expense',
                        'secondary' => 'equity',
                    ])
                    ->sortable(),
            ])
            ->filters([
                //
            ])
            ->headerActions([
                Tables\Actions\CreateAction::make(),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }
}
