<?php

namespace App\Filament\Resources\GroupResource\RelationManagers;

use App\Models\Transaction;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class TransactionsRelationManager extends RelationManager
{
    protected static string $relationship = 'transactions';

    protected static ?string $title = 'Group Transactions';
    
    protected static ?string $label = 'Transaction';
    
    protected static ?string $pluralLabel = 'Transactions';
    
    public function isReadOnly(): bool
    {
        return true;
    }

    protected function getTableQuery(): Builder
    {
        // Only show transactions for GROUP accounts (not organization accounts)
        // Group account codes start with G{id}- (e.g., G1-1001, G2-2301)
        $groupId = $this->getOwnerRecord()->id;
        $groupPrefix = "G{$groupId}-";
        
        return $this->getOwnerRecord()
            ->transactions()
            ->getQuery()
            ->where('account_number', 'LIKE', $groupPrefix . '%');
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('id')
                    ->label('Tx ID')
                    ->sortable(),
                
                Tables\Columns\TextColumn::make('transaction_date')
                    ->label('Date')
                    ->date()
                    ->sortable(),
                
                Tables\Columns\TextColumn::make('account_name')
                    ->label('Account')
                    ->searchable()
                    ->wrap(),
                
                Tables\Columns\TextColumn::make('transaction_type')
                    ->label('Type')
                    ->badge()
                    ->searchable(),
                
                Tables\Columns\BadgeColumn::make('dr_cr')
                    ->label('Dr/Cr')
                    ->colors([
                        'success' => 'dr',
                        'danger' => 'cr',
                    ]),
                
                Tables\Columns\TextColumn::make('amount')
                    ->money('KES')
                    ->sortable(),
                
                Tables\Columns\TextColumn::make('member.name')
                    ->label('Member')
                    ->searchable()
                    ->toggleable(),
                
                Tables\Columns\TextColumn::make('loan.loan_number')
                    ->label('Loan')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),
                
                Tables\Columns\TextColumn::make('description')
                    ->label('Description')
                    ->limit(50)
                    ->searchable()
                    ->toggleable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('transaction_type')
                    ->options([
                        'capital_received' => 'Capital Received',
                        'capital_returned' => 'Capital Returned',
                        'loan_issue' => 'Loan Issue',
                        'principal_payment' => 'Principal Payment',
                        'interest_payment' => 'Interest Payment',
                        'interest_accrual' => 'Interest Accrual',
                        'savings_deposit' => 'Savings Deposit',
                        'savings_withdrawal' => 'Savings Withdrawal',
                    ]),
                
                Tables\Filters\SelectFilter::make('dr_cr')
                    ->label('Debit/Credit')
                    ->options([
                        'dr' => 'Debit',
                        'cr' => 'Credit',
                    ]),
            ])
            ->headerActions([
                //
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
            ])
            ->bulkActions([
                //
            ])
            ->defaultSort('id', 'desc');
    }
}

