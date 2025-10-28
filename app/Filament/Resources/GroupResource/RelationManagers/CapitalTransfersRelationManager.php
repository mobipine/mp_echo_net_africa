<?php

namespace App\Filament\Resources\GroupResource\RelationManagers;

use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

class CapitalTransfersRelationManager extends RelationManager
{
    protected static string $relationship = 'capitalTransfers';

    protected static ?string $title = 'Capital Transfers';

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Placeholder::make('info')
                    ->content('Capital transfers are managed through the Capital Transfers resource.')
                    ->columnSpanFull(),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('id')
                    ->label('ID')
                    ->sortable(),
                
                Tables\Columns\BadgeColumn::make('transfer_type')
                    ->label('Type')
                    ->colors([
                        'success' => 'advance',
                        'warning' => 'return',
                    ])
                    ->formatStateUsing(fn ($state) => $state === 'advance' ? 'Advance' : 'Return'),
                
                Tables\Columns\TextColumn::make('amount')
                    ->money('KES')
                    ->sortable(),
                
                Tables\Columns\TextColumn::make('transfer_date')
                    ->label('Date')
                    ->date()
                    ->sortable(),
                
                Tables\Columns\TextColumn::make('reference_number')
                    ->label('Reference')
                    ->searchable(),
                
                Tables\Columns\TextColumn::make('purpose')
                    ->limit(50)
                    ->searchable()
                    ->toggleable(),
                
                Tables\Columns\BadgeColumn::make('status')
                    ->colors([
                        'warning' => 'pending',
                        'success' => 'completed',
                        'danger' => 'rejected',
                    ]),
                
                Tables\Columns\TextColumn::make('approver.name')
                    ->label('Approved By')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('transfer_type')
                    ->options([
                        'advance' => 'Advance',
                        'return' => 'Return',
                    ]),
                
                Tables\Filters\SelectFilter::make('status')
                    ->options([
                        'pending' => 'Pending',
                        'completed' => 'Completed',
                        'rejected' => 'Rejected',
                    ]),
            ])
            ->headerActions([
                //
            ])
            ->actions([
                Tables\Actions\Action::make('view')
                    ->label('View')
                    ->url(fn ($record) => route('filament.admin.resources.capital-transfers.view', $record))
                    ->icon('heroicon-o-eye'),
            ])
            ->bulkActions([
                //
            ])
            ->defaultSort('transfer_date', 'desc');
    }
}

