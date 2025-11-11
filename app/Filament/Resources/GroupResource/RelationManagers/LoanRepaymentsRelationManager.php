<?php

namespace App\Filament\Resources\GroupResource\RelationManagers;

use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use App\Models\LoanRepayment;

class LoanRepaymentsRelationManager extends RelationManager
{
    protected static string $relationship = 'loanRepayments';

    protected static ?string $title = 'Loan Repayments';

    protected static ?string $label = 'Repayment';

    protected static ?string $pluralLabel = 'Repayments';

    public function isReadOnly(): bool
    {
        return true;
    }

    protected function getTableQuery(): Builder
    {
        // Override to use custom query since the relationship is complex (3 levels)
        $groupId = $this->getOwnerRecord()->id;
        return LoanRepayment::query()
            ->whereHas('loan.member', fn ($query) => $query->where('group_id', $groupId));
    }

    public function table(Table $table): Table
    {
        return $table
            ->query($this->getTableQuery())
            ->columns([
                Tables\Columns\TextColumn::make('loan.loan_number')
                    ->label('Loan Number')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('loan.member.name')
                    ->label('Member')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('amount')
                    ->label('Amount')
                    ->money('KES')
                    ->sortable(),

                Tables\Columns\TextColumn::make('repayment_date')
                    ->label('Repayment Date')
                    ->date()
                    ->sortable(),

                Tables\Columns\TextColumn::make('payment_method')
                    ->label('Payment Method')
                    ->searchable(),

                Tables\Columns\TextColumn::make('reference_number')
                    ->label('Reference Number')
                    ->searchable(),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Recorded At')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('payment_method')
                    ->options([
                        'cash' => 'Cash',
                        'bank_transfer' => 'Bank Transfer',
                        'mobile_money' => 'Mobile Money',
                        'cheque' => 'Cheque',
                    ]),
            ])
            ->headerActions([
                //
            ])
            ->actions([
                Tables\Actions\Action::make('view')
                    ->label('View Loan')
                    ->url(fn ($record) => route('filament.admin.resources.loans.view', $record->loan_id))
                    ->icon('heroicon-o-eye'),
            ])
            ->bulkActions([
                //
            ])
            ->defaultSort('repayment_date', 'desc');
    }
}

