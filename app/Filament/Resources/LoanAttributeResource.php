<?php

namespace App\Filament\Resources;

use App\Filament\Resources\LoanAttributeResource\Pages;
use App\Filament\Resources\LoanAttributeResource\RelationManagers;
use App\Models\LoanAttribute;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class LoanAttributeResource extends Resource
{
    protected static ?string $model = LoanAttribute::class;

    protected static ?string $navigationIcon = 'heroicon-o-link';

    protected static ?string $navigationGroup = 'Loan Management';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('name')
                    ->required()
                    ->maxLength(255),                
                Forms\Components\TextInput::make('slug')
                    ->required()
                    ->maxLength(255),
                Forms\Components\Select::make('type')
                    ->options([
                        'string' => 'String',
                        'integer' => 'Integer',
                        'decimal' => 'Decimal',
                        'boolean' => 'Boolean',
                        'date' => 'Date',
                        'file' => 'File',
                        'select' => 'Select',
                        'multiselect' => 'Multi Select',
                        'collateral_setup' => 'Collateral Setup',
                        'guarantor_setup' => 'Guarantor Setup',
                    ])->native(false)
                    ->required(),

                Forms\Components\Textarea::make('options')
                    ->label('Options (comma separated, for select/multiselect)')
                    ->nullable()
                    ->columnSpanFull()
                // //convert to repeater that will store an array of options
                // Forms\Components\Repeater::make('options')
                //     ->schema([
                //         Forms\Components\TextInput::make('option'),
                //     ])
                //     ->visible(fn ($get) => $get('type') === 'select' || $get('type') === 'multiselect'),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')->searchable(),
                Tables\Columns\TextColumn::make('slug')->searchable(),
                Tables\Columns\TextColumn::make('type')->searchable(),
                Tables\Columns\TextColumn::make('options')->limit(30),
                Tables\Columns\TextColumn::make('created_at')->dateTime()->sortable()->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('updated_at')->dateTime()->sortable()->toggleable(isToggledHiddenByDefault: true),
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
            'index' => Pages\ListLoanAttributes::route('/'),
            'create' => Pages\CreateLoanAttribute::route('/create'),
            'edit' => Pages\EditLoanAttribute::route('/{record}/edit'),
        ];
    }
}
