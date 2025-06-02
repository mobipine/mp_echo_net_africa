<?php

namespace App\Filament\Resources;

use App\Filament\Resources\LoanResource\Pages;
use App\Filament\Resources\LoanResource\RelationManagers;
use App\Models\Loan;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class LoanResource extends Resource
{
    protected static ?string $model = Loan::class;

    protected static ?string $navigationIcon = 'heroicon-o-rectangle-stack';

    protected static ?string $navigationGroup = 'Loan Management';

    public static function getNavigationBadge(): ?string
    {
        return static::getModel()::count();
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Select::make('loan_product_id')
                    ->relationship('loanProduct', 'name')
                    ->required()
                    ->reactive()
                    ->afterStateUpdated(function ($state, $set) {
                        // Fetch interest rate from LoanProduct
                        $interestRate = 0.05; // Example value, replace with actual fetch logic
                        $interestCycle = 'monthly'; // Example value, replace with actual fetch logic
                        $set('interest_rate', $interestRate);
                        $set('interest_cycle', $interestCycle);
                        
                    }),
                Forms\Components\Select::make('member_id')
                    ->relationship('member', 'name')
                    ->required(),
                Forms\Components\TextInput::make('status')
                    ->default('Pending Approval')
                    ->disabled(),
                Forms\Components\TextInput::make('principal_amount')
                    ->required()
                    ->numeric(),
                Forms\Components\TextInput::make('interest_cycle')
                    ->label('Interest Cycle')
                    ->disabled(),
                Forms\Components\Select::make('loan_duration')
                    ->label('Loan Duration')
                    ->required()
                    ->native(false)
                    ->options([
                        '1' => '1 Month',
                        '2' => '2 Months',
                        '3' => '3 Months',
                        '4' => '4 Months',
                        '5' => '5 Months',
                    ])
                    ->afterStateUpdated(function ($state, $set) {
                        \Illuminate\Support\Facades\Log::info('State updated: ' . $state);
                        $set('repayment_amount', 20000);
                        $set('interest_amount', 1000);
                    }),
                Forms\Components\DatePicker::make('release_date')
                    ->label('Release Date')
                    ->required(),

                Forms\Components\TextInput::make('repayment_amount')
                    ->label('Repayment Amount')
                    ->numeric()
                    ->required()
                    ->disabled(),
                    
                Forms\Components\TextInput::make('interest_amount')
                    ->label('Interest Amount')
                    ->numeric()
                    ->required()
                    ->disabled(),
                Forms\Components\TextInput::make('interest_rate')
                    ->label('Interest Rate')
                    ->numeric()
                    ->required()
                    ->disabled(),

                Forms\Components\TextInput::make('loan_number')
                    ->label('Loan Number')
                    ->default(fn () => 'LN-' . strtoupper(uniqid()))
                    ->required()
                    ->disabled(),

                Forms\Components\DatePicker::make('due_date')
                    ->label('Due Date')
                    ->required()
                    // ->hidden()
                    ->disabled(),
            ]);
    }

    protected function calculateLoanAmount($loanProductId)
    {
        // Fetch attributes and perform calculations
        // Placeholder logic for demonstration
        $interestRate = 0.05; // Example value
        $loanCharges = 100; // Example value
        $maxLoanAmount = 10000; // Example value

        return $maxLoanAmount + ($maxLoanAmount * $interestRate) + $loanCharges;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('member_id')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('loan_product_id')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('amount')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('status')
                    ->searchable(),
                Tables\Columns\TextColumn::make('issued_at')
                    ->dateTime()
                    ->sortable(),
                Tables\Columns\TextColumn::make('due_at')
                    ->dateTime()
                    ->sortable(),
                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                //
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
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
            'index' => Pages\ListLoans::route('/'),
            'create' => Pages\CreateLoan::route('/create'),
            'edit' => Pages\EditLoan::route('/{record}/edit'),
        ];
    }
}
