<?php

namespace App\Filament\Resources;

use App\Filament\Resources\MemberFeeObligationResource\Pages;
use App\Models\MemberFeeObligation;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Support\Enums\FontWeight;

class MemberFeeObligationResource extends Resource
{
    protected static ?string $model = MemberFeeObligation::class;

    protected static ?string $navigationIcon = 'heroicon-o-document-text';
    
    protected static ?string $cluster = \App\Filament\Clusters\SaccoManagement::class;
    
    protected static ?int $navigationSort = 9;
    
    protected static ?string $navigationLabel = 'Fee Obligations';
    
    protected static ?string $pluralLabel = 'Fee Obligations';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Select::make('member_id')
                    ->label('Member')
                    ->relationship('member', 'name')
                    ->required()
                    ->searchable(),
                
                Forms\Components\Select::make('sacco_product_id')
                    ->label('Fee/Fine')
                    ->relationship('saccoProduct', 'name')
                    ->required()
                    ->searchable(),
                
                Forms\Components\TextInput::make('amount_due')
                    ->label('Amount Due')
                    ->numeric()
                    ->required()
                    ->prefix('KES'),
                
                Forms\Components\TextInput::make('amount_paid')
                    ->label('Amount Paid')
                    ->numeric()
                    ->default(0)
                    ->prefix('KES')
                    ->disabled(),
                
                Forms\Components\DatePicker::make('due_date')
                    ->required()
                    ->default(now()->addDays(30)),
                
                Forms\Components\Select::make('status')
                    ->options([
                        'pending' => 'Pending',
                        'partially_paid' => 'Partially Paid',
                        'paid' => 'Paid',
                        'waived' => 'Waived',
                    ])
                    ->required()
                    ->default('pending'),
                
                Forms\Components\Textarea::make('description')
                    ->maxLength(65535)
                    ->columnSpanFull(),
                
                Forms\Components\Textarea::make('notes')
                    ->maxLength(65535)
                    ->columnSpanFull(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('member.name')
                    ->searchable()
                    ->sortable()
                    ->weight(FontWeight::Bold),
                
                Tables\Columns\TextColumn::make('saccoProduct.name')
                    ->label('Fee/Fine')
                    ->badge()
                    ->color('info'),
                
                Tables\Columns\TextColumn::make('amount_due')
                    ->label('Amount Due')
                    ->money('KES')
                    ->sortable(),
                
                Tables\Columns\TextColumn::make('amount_paid')
                    ->label('Amount Paid')
                    ->money('KES')
                    ->sortable()
                    ->color('success'),
                
                Tables\Columns\TextColumn::make('balance_due')
                    ->label('Balance')
                    ->money('KES')
                    ->sortable()
                    ->weight(FontWeight::Bold)
                    ->color(fn ($record) => $record->balance_due > 0 ? 'warning' : 'success'),
                
                Tables\Columns\TextColumn::make('due_date')
                    ->date()
                    ->sortable()
                    ->color(fn ($record) => $record->due_date->isPast() && $record->balance_due > 0 ? 'danger' : null),
                
                Tables\Columns\BadgeColumn::make('status')
                    ->colors([
                        'warning' => 'pending',
                        'info' => 'partially_paid',
                        'success' => 'paid',
                        'secondary' => 'waived',
                    ]),
                
                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->options([
                        'pending' => 'Pending',
                        'partially_paid' => 'Partially Paid',
                        'paid' => 'Paid',
                        'waived' => 'Waived',
                    ]),
                
                Tables\Filters\SelectFilter::make('sacco_product_id')
                    ->label('Fee/Fine')
                    ->relationship('saccoProduct', 'name'),
                
                Tables\Filters\Filter::make('overdue')
                    ->query(fn ($query) => $query->overdue())
                    ->label('Overdue Only'),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
                Tables\Actions\Action::make('waive')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->form([
                        Forms\Components\Textarea::make('reason')
                            ->required()
                            ->label('Reason for waiving'),
                    ])
                    ->action(function (MemberFeeObligation $record, array $data) {
                        $service = app(\App\Services\FeeAccrualService::class);
                        $service->waiveObligation($record, $data['reason']);
                        
                        \Filament\Notifications\Notification::make()
                            ->title('Fee Waived')
                            ->success()
                            ->body("Fee obligation has been waived.")
                            ->send();
                    })
                    ->visible(fn ($record) => $record->status !== 'paid' && $record->status !== 'waived'),
            ])
            ->bulkActions([
                //
            ])
            ->defaultSort('due_date', 'asc');
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
            'index' => Pages\ListMemberFeeObligations::route('/'),
            'view' => Pages\ViewMemberFeeObligation::route('/{record}'),
        ];
    }
}

