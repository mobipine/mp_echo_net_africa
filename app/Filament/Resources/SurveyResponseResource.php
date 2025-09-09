<?php

namespace App\Filament\Resources;

use App\Filament\Resources\SurveyResponseResource\Pages;
use App\Filament\Resources\SurveyResponseResource\RelationManagers;
use App\Models\SurveyResponse;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use pxlrbt\FilamentExcel\Actions\Tables\ExportBulkAction;
use pxlrbt\FilamentExcel\Exports\ExcelExport;

class SurveyResponseResource extends Resource
{
    protected static ?string $model = SurveyResponse::class;

    protected static ?string $navigationIcon = 'heroicon-o-chat-bubble-left-ellipsis';
    protected static ?string $navigationGroup = 'Surveys';

    public static function canCreate(): bool
    {
        return false; // Disable the create action
    }


    public static function form(Form $form): Form
    {
        return $form
            ->schema([
            
                Forms\Components\Select::make('survey_id')
                    ->label('Survey Name')
                    ->options(\App\Models\Survey::pluck('title', 'id')) // Fetch directly from the Survey model
                    ->label('Survey')
                    ->required()
                    ->native(false)
                    ->searchable(),
                Forms\Components\TextInput::make('msisdn')
                    ->label('MSISDN')
                    ->required()
                    ->maxLength(15)
                    ->placeholder('e.g. 0712345678'),
                Forms\Components\Select::make('question_id')
                    ->label('Question')
                    // ->relationship('question', 'question')
                    ->options(\App\Models\SurveyQuestion::pluck('question', 'id'))
                    ->required()
                    ->native(false)
                    ->searchable(),
                Forms\Components\TextInput::make('survey_response')
                    ->label('Response')
                    ->required()
                    ->maxLength(500)
                    ->placeholder('e.g. Yes, No, 123'),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                // Tables\Columns\TextColumn::make('id')->sortable(),
                // Tables\Columns\TextColumn::make('participant_id')->sortable()->searchable(),
                // Tables\Columns\TextColumn::make('response_data')->limit(50),


                //i want columns for id	survey_name form the survey id, msisdn	question	survey_response	created_at	updated_at	
                Tables\Columns\TextColumn::make('id')->sortable(),
                Tables\Columns\TextColumn::make('survey.title')->label('Survey Name')->sortable()->searchable(),
                Tables\Columns\TextColumn::make('msisdn')->label('MSISDN')->sortable()->searchable(),
                Tables\Columns\TextColumn::make('question.question')->label('Question')->sortable()->searchable(),
                Tables\Columns\TextColumn::make('survey_response')->label('Response')->limit(50)->sortable(),
                Tables\Columns\TextColumn::make('created_at')->dateTime()->sortable(),
                Tables\Columns\TextColumn::make('updated_at')->dateTime()->sortable(),

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

                    // Use string keys for columns
                    ExportBulkAction::make()
                        ->exports([
                            ExcelExport::make()
                                ->fromTable()
                            
                        ])
                    ->label('Export to Excel'),
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
            'index' => Pages\ListSurveyResponses::route('/'),
            // 'create' => Pages\CreateSurveyResponse::route('/create'),
            // 'edit' => Pages\EditSurveyResponse::route('/{record}/edit'),
        ];
    }
}
