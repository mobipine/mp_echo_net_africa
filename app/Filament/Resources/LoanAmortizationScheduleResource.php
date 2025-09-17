<?php

namespace App\Filament\Resources;

use App\Filament\Resources\LoanAmortizationScheduleResource\Pages;
use App\Models\LoanAmortizationSchedule;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class LoanAmortizationScheduleResource extends Resource
{
    protected static ?string $model = LoanAmortizationSchedule::class;

    protected static ?string $navigationIcon = 'heroicon-o-calendar-days';
    protected static ?string $navigationGroup = 'Loan Management';
    protected static ?string $navigationLabel = 'Amortization Schedules';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Select::make('loan_id')
                    ->relationship('loan', 'loan_number')
                    ->required(),
                Forms\Components\TextInput::make('payment_number')
                    ->numeric()
                    ->required(),
                Forms\Components\DatePicker::make('payment_date')
                    ->required(),
                Forms\Components\TextInput::make('principal_payment')
                    ->numeric()
                    ->prefix('KES')
                    ->required(),
                Forms\Components\TextInput::make('interest_payment')
                    ->numeric()
                    ->prefix('KES')
                    ->required(),
                Forms\Components\TextInput::make('total_payment')
                    ->numeric()
                    ->prefix('KES')
                    ->required(),
                Forms\Components\TextInput::make('remaining_balance')
                    ->numeric()
                    ->prefix('KES')
                    ->required(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('loan.loan_number')
                    ->label('Loan Number')
                    ->sortable()
                    ->searchable(),
                Tables\Columns\TextColumn::make('loan.member.name')
                    ->label('Member')
                    ->sortable()
                    ->searchable(),
                Tables\Columns\TextColumn::make('payment_number')
                    ->label('Payment #')
                    ->sortable(),
                Tables\Columns\TextColumn::make('payment_date')
                    ->date()
                    ->sortable(),
                Tables\Columns\TextColumn::make('principal_payment')
                    ->money('KES')
                    ->sortable(),
                Tables\Columns\TextColumn::make('interest_payment')
                    ->money('KES')
                    ->sortable(),
                Tables\Columns\TextColumn::make('total_payment')
                    ->money('KES')
                    ->sortable(),
                Tables\Columns\TextColumn::make('remaining_balance')
                    ->money('KES')
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('loan_id')
                    ->relationship('loan', 'loan_number')
                    ->label('Loan'),
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
            'index' => Pages\ListLoanAmortizationSchedules::route('/'),
            'view' => Pages\ViewLoanAmortizationSchedule::route('/{record}'),
        ];
    }
}
