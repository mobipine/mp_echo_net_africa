<?php

namespace App\Filament\Resources;

use App\Filament\Resources\TransactionResource\Pages;
use App\Filament\Resources\TransactionResource\RelationManagers;
use App\Models\Transaction;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class TransactionResource extends Resource
{
    protected static ?string $model = Transaction::class;

    protected static ?string $navigationIcon = 'heroicon-o-rectangle-stack';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
               
                //no creation form for transactions, only editing existing ones

               
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('account_name')
                    ->label('Account')
                    ->sortable()
                    ->searchable(),
                Tables\Columns\TextColumn::make('loan.loan_number')
                    ->label('Loan Number')
                    ->sortable()
                    ->searchable(),
                Tables\Columns\TextColumn::make('member.name')
                    ->label('Member')
                    ->sortable()
                    ->searchable(),
                Tables\Columns\TextColumn::make('transaction_type')
                    ->label('Type')
                    ->sortable(),
                
                //badge column for dr_cr
                Tables\Columns\BadgeColumn::make('dr_cr')
                    ->label('Debit/Credit')
                    ->colors([
                        'yellow' => 'dr',
                        'blue' => 'cr',
                    ]),
                Tables\Columns\TextColumn::make('amount')
                    ->money('KES', true)
                    ->sortable(),
                Tables\Columns\TextColumn::make('transaction_date')
                    ->dateTime()
                    ->sortable(),
                Tables\Columns\TextColumn::make('description')
                    ->limit(50)
                    ->tooltip(function (Tables\Columns\TextColumn $column): ?string {
                        $state = $column->getState();
                        if (strlen($state) <= 50) {
                            return null;
                        }
                        return $state;
                    }),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('transaction_type')
                    ->options([
                        'loan_issue' => 'Loan Issue',
                        'loan_repayment' => 'Loan Repayment',
                    ]),
                Tables\Filters\SelectFilter::make('dr_cr')
                    ->options([
                        'dr' => 'Debit',
                        'cr' => 'Credit',
                    ]),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
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
            'index' => Pages\ListTransactions::route('/'),
        ];
    }
}
