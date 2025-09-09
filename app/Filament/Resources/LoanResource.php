<?php

namespace App\Filament\Resources;

use App\Filament\Resources\LoanResource\Pages;
use App\Filament\Resources\LoanResource\RelationManagers;
use App\Models\Loan;
use App\Models\LoanAttribute;
use App\Models\LoanProduct;
use Filament\Forms;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Support\RawJs;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class LoanResource extends Resource
{
    protected static ?string $model = Loan::class;

    protected static ?string $navigationIcon = 'heroicon-o-banknotes';

    protected static ?string $navigationGroup = 'Loan Management';

    public static function getNavigationBadge(): ?string
    {
        return static::getModel()::count();
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Select::make('loan_product_id')
                    ->label('Loan Product')
                    ->native(false)
                    ->options(LoanProduct::all()->pluck('name', 'id')->toArray())
                    ->reactive()
                    
                    ->required(),

                TextInput::make('loan_number')
                    ->label('Loan Number')
                    ->default(function () {
                        return 'LN' . str_pad(Loan::count() + 1, 6, '0', STR_PAD_LEFT);
                    })
                    ->required()
                    ->readOnly(),

                Forms\Components\TextInput::make('status')
                    ->default('Pending Approval')
                    ->readOnly(),

               

                Forms\Components\TextInput::make('loan_duration')
                    ->label('Loan Duration')
                    // ->numeric()
                    ->placeholder('Enter Loan Duration depending on the interest cycle')
                    ->formatStateUsing(function ($state, $record) {
                       return self::formatDuration($state, $record);
                    })
                    // ->helperText('Enter Loan Duration depending on the interest cycle')
                    ->required(),

                Forms\Components\TextInput::make('principal_amount')
                    ->label('Principal Amount')
                    ->placeholder('Enter requested loan amount')
                    ->required()
                    ->reactive()
                    ->mask(RawJs::make('$money($input)'))
                    ->stripCharacters(',')
                    ->numeric(),
                    
                Forms\Components\DatePicker::make('release_date')
                    ->label('Release Date')
                    ->reactive()
                    ->native(false)
                    ->displayFormat('d/m/Y')
                    ->timezone('Africa/Nairobi')
                    ->locale('en')
                    ->required(),

                Forms\Components\DatePicker::make('due_at')
                    ->label('Due Date')
                    ->displayFormat('d/m/Y')
                    ->timezone('Africa/Nairobi')
                    ->locale('en')
                    ->required()
                    ->native(false)
                    // ->hidden()
                    ->readOnly(),

                Forms\Components\TextInput::make('repayment_amount')
                    ->label('Repayment Amount')
                    ->numeric()
                    ->required()
                    ->mask(RawJs::make('$money($input)'))
                    ->stripCharacters(',')
                    ->readOnly(),

                Forms\Components\TextInput::make('interest_amount')
                    ->label('Interest Amount')
                    ->numeric()
                    ->required()
                    ->mask(RawJs::make('$money($input)'))
                    ->stripCharacters(',')
                    ->readOnly(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('member.name')
                    ->label('Member Name')
                    ->sortable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('loanProduct.name')
                    ->label('Loan Product')
                    ->numeric()
                    ->sortable(),

                Tables\Columns\TextColumn::make('principal_amount')
                    ->numeric()
                    ->sortable(),

                // Tables\Columns\TextColumn::make('status')
                //     ->searchable(),
                Tables\Columns\BadgeColumn::make('status')
                    ->colors([
                        'yellow' => 'Pending Approval',
                        'primary' => 'Approved',
                        'danger' => 'Rejected',
                        'blue' => 'Completed',
                    ])
                    ->sortable()
                    ->searchable(),

                
                Tables\Columns\TextColumn::make('release_date')
                    ->date()
                    ->searchable()
                    // ->format('Y-m-d')
                    ->sortable(),
                    //loan duration is a select field, so we can use it directly
                Tables\Columns\TextColumn::make('loan_duration')
                //get the loan cycle of the loan product and append it to the duration
                    ->formatStateUsing(function ($state, $record) {
                        return self::formatDuration($state, $record);
                    })
                    ->label('Duration')
                    ->sortable()
                    ->searchable(),

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
                // Tables\Actions\EditAction::make(),
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
            'index' => Pages\ListLoans::route('/'),
            'create' => Pages\CreateLoan::route('/create'),
            // 'edit' => Pages\EditLoan::route('/{record}/edit'),
        ];
    }

    public static function formatDuration($state, $record) {
        // return $state . ' ' . $record->loanProduct->interest_cycle;

        $slug = 'interest_cycle';
        $attributeId = LoanAttribute::where('slug', $slug)->first()->id;
        $loan_product = $record->loanProduct;


        $loanAttribute = $loan_product->loanProductAttributes()->where('loan_attribute_id', $attributeId)->first();
        // dd($loanAttribute, $loanAttribute->value);
        if($loanAttribute->value == "Daily") {
            $units = 'days';
        } elseif($loanAttribute->value == "Weekly") {
            $units = 'weeks';
        } elseif($loanAttribute->value == "Monthly") {
            $units = 'months';
        } elseif($loanAttribute->value == "Yearly") {
            $units = 'years';
        } else {
            $units = 'N/A';

        }
        return $state . ' ' . $units;
    }
}
