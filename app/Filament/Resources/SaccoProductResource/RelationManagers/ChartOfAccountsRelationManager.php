<?php

namespace App\Filament\Resources\SaccoProductResource\RelationManagers;

use App\Models\ChartofAccounts;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

class ChartOfAccountsRelationManager extends RelationManager
{
    protected static string $relationship = 'chartOfAccounts';

    protected static ?string $title = 'Chart of Accounts Mapping';
    
    protected static ?string $description = 'Map this SACCO product to specific GL accounts for transaction recording';

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
                        'savings_account' => 'Member Savings Liability',
                        'contribution_receivable' => 'Contribution Receivable',
                        'contribution_income' => 'Contribution Income',
                        'fee_receivable' => 'Fee Receivable',
                        'fee_income' => 'Fee Income',
                        'fine_receivable' => 'Fine Receivable',
                        'fine_income' => 'Fine Income',
                        'savings_interest_expense' => 'Savings Interest Expense',
                    ])
                    ->required()
                    ->native(false)
                    ->searchable()
                    ->helperText('Select the type of account this mapping is for'),

                Forms\Components\Select::make('account_number')
                    ->label('Account')
                    ->options(ChartofAccounts::all()->pluck('name', 'account_code'))
                    ->required()
                    ->native(false)
                    ->searchable()
                    ->helperText('Select the specific GL account from your chart of accounts'),
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
                            'savings_account' => 'Member Savings Liability',
                            'contribution_receivable' => 'Contribution Receivable',
                            'contribution_income' => 'Contribution Income',
                            'fee_receivable' => 'Fee Receivable',
                            'fee_income' => 'Fee Income',
                            'fine_receivable' => 'Fine Receivable',
                            'fine_income' => 'Fine Income',
                            'savings_interest_expense' => 'Savings Interest Expense',
                        ];
                        return $types[$state] ?? $state;
                    })
                    ->sortable()
                    ->searchable()
                    ->badge()
                    ->color('info'),

                Tables\Columns\TextColumn::make('chartOfAccount.name')
                    ->label('Account Name')
                    ->sortable()
                    ->searchable(),

                Tables\Columns\TextColumn::make('account_number')
                    ->label('Account Number')
                    ->sortable()
                    ->searchable()
                    ->copyable(),

                Tables\Columns\TextColumn::make('chartOfAccount.account_type')
                    ->label('Account Category')
                    ->badge()
                    ->colors([
                        'primary' => 'Asset',
                        'success' => 'Revenue',
                        'warning' => 'Liability',
                        'danger' => 'Expense',
                        'secondary' => 'Equity',
                    ])
                    ->sortable(),
            ])
            ->filters([
                //
            ])
            ->headerActions([
                Tables\Actions\CreateAction::make()
                    ->label('Add Account Mapping'),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ])
            ->emptyStateHeading('No account mappings')
            ->emptyStateDescription('Add GL account mappings to enable transactions for this product')
            ->emptyStateIcon('heroicon-o-banknotes');
    }
}

