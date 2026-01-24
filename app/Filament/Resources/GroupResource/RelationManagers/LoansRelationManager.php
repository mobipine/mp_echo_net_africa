<?php

namespace App\Filament\Resources\GroupResource\RelationManagers;

use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use App\Models\Loan;
use App\Filament\Resources\LoanResource\Actions\ApproveLoanAction;

class LoansRelationManager extends RelationManager
{
    protected static string $relationship = 'members';

    protected static ?string $title = 'Group Loans';

    protected static ?string $label = 'Loan';

    protected static ?string $pluralLabel = 'Loans';

    public function isReadOnly(): bool
    {
        return true;
    }

    protected function getTableQuery(): Builder
    {
        // Get all loans for members of this group (using many-to-many relationship)
        return Loan::query()
            ->whereHas('member.groups', fn ($query) => $query->where('groups.id', $this->getOwnerRecord()->id));
    }

    public function table(Table $table): Table
    {
        return $table
            ->query($this->getTableQuery())
            ->columns([
                Tables\Columns\TextColumn::make('loan_number')
                    ->label('Loan No.')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('member.name')
                    ->label('Member')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('loanProduct.name')
                    ->label('Product')
                    ->searchable(),

                Tables\Columns\TextColumn::make('principal_amount')
                    ->label('Principal')
                    ->money('KES')
                    ->sortable(),

                Tables\Columns\TextColumn::make('remaining_balance')
                    ->label('Outstanding')
                    ->money('KES')
                    ->sortable(),

                Tables\Columns\BadgeColumn::make('status')
                    ->label('Status')
                    ->colors([
                        'warning' => 'Pending Approval',
                        'success' => 'Disbursed',
                        'info' => 'Approved',
                        'danger' => 'Rejected',
                        'success' => 'Fully Repaid',
                    ]),

                Tables\Columns\TextColumn::make('release_date')
                    ->label('Release Date')
                    ->date()
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->options([
                        'Pending Approval' => 'Pending Approval',
                        'Approved' => 'Approved',
                        'Disbursed' => 'Disbursed',
                        'Fully Repaid' => 'Fully Repaid',
                        'Rejected' => 'Rejected',
                    ]),
            ])
            ->headerActions([
                //
            ])
            ->actions([
                ApproveLoanAction::makeForRelationManager(),

                Tables\Actions\Action::make('view')
                    ->label('View Loan')
                    ->color('blue')
                    ->url(fn ($record) => route('filament.admin.resources.loans.view', $record))
                    ->icon('heroicon-o-eye'),
            ])
            ->bulkActions([
                //
            ])
            ->defaultSort('release_date', 'desc');
    }
}

