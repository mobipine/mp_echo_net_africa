<?php

namespace App\Filament\Resources;

use App\Filament\Resources\SurveyQuestionResource\Pages;
use App\Filament\Resources\SurveyQuestionResource\RelationManagers;
use App\Models\SurveyQuestion;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class SurveyQuestionResource extends Resource
{
    protected static ?string $model = SurveyQuestion::class;

    protected static ?string $navigationIcon = 'heroicon-o-question-mark-circle';

    protected static ?string $navigationGroup = 'Surveys';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('question')->required()->maxLength(255),
                Forms\Components\Select::make('answer_data_type')
                    ->options(['Alphanumeric' => 'Alphanumeric', 'Strictly Number' => 'Strictly Number'])
                    ->required(),
                Forms\Components\Textarea::make('data_type_violation_response')->maxLength(500),

                Forms\Components\Select::make('answer_strictness')
                    ->options(['Open-Ended' => 'Open-Ended', 'Multiple Choice' => 'Multiple Choice'])
                    ->required()
                    ->default('Open-Ended')
                    ->native(false)
                    ->reactive()
                    ->afterStateUpdated(fn (callable $set, $state) => $set('possible_answers', null)),

                //do a repeater for possible_answers that only shows if answer_strictness is Multiple Choice with  afield for the letter and the answer
                Forms\Components\Repeater::make('possible_answers')
                    ->schema([
                        Forms\Components\TextInput::make('letter')->required()->maxLength(1),
                        Forms\Components\TextInput::make('answer')->required()->maxLength(255),
                    ])
                    ->columns(2)
                    ->visible(fn ($get) => $get('answer_strictness') === 'Multiple Choice')
                    ->minItems(2)
                    ->maxItems(26)
                    ->required(fn ($get) => $get('answer_strictness') === 'Multiple Choice'),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('id')->sortable(),
                Tables\Columns\TextColumn::make('question')->sortable()->searchable(),
                Tables\Columns\TextColumn::make('answer_data_type')->sortable(),
            ])
            ->filters([
                //
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
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
            'index' => Pages\ListSurveyQuestions::route('/'),
            'create' => Pages\CreateSurveyQuestion::route('/create'),
            'edit' => Pages\EditSurveyQuestion::route('/{record}/edit'),
        ];
    }
}
