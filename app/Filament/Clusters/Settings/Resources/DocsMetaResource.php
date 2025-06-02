<?php

namespace App\Filament\Clusters\Settings\Resources;

use App\Filament\Clusters\Settings;
use App\Filament\Clusters\Settings\Resources\DocsMetaResource\Pages;
use App\Filament\Clusters\Settings\Resources\DocsMetaResource\RelationManagers;
use App\Models\DocsMeta;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class DocsMetaResource extends Resource
{
    protected static ?string $model = DocsMeta::class;

    protected static ?string $navigationIcon = 'heroicon-o-rectangle-stack';

    protected static ?string $cluster = Settings::class;


    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('name')
                    ->required()
                    ->maxLength(255),
                //make a select for tags
                Forms\Components\Select::make('tags')
                    ->options([
                        'member_kyc' => 'Member KYC',
                        'loan_application' => 'Loan Application',  
                    ])
                    ->required(),
                Forms\Components\Select::make('expiry')
                    ->options([
                        'yes' => 'Yes',
                        'no' => 'No',
                    ])
                    ->required(),
                Forms\Components\TextInput::make('description')
                    ->maxLength(255),
                Forms\Components\TextInput::make('max_file_count')
                    ->required()
                    ->maxLength(255),
                    
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name'),
                Tables\Columns\TextColumn::make('tags'),
                Tables\Columns\TextColumn::make('expiry'),
                Tables\Columns\TextColumn::make('description'),
                Tables\Columns\TextColumn::make('max_file_count'),
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
            'index' => Pages\ListDocsMetas::route('/'),
            'create' => Pages\CreateDocsMeta::route('/create'),
            'edit' => Pages\EditDocsMeta::route('/{record}/edit'),
        ];
    }

    //change title of the resource
    public static function getModelLabel(): string
    {
        return 'Document Management';
    }

    //change label of the resource
    public static function getPluralModelLabel(): string
    {
        return 'Document Management';
    }
    
    
}
