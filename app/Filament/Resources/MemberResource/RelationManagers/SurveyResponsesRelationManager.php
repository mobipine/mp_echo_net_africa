<?php

namespace App\Filament\Resources\MemberResource\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

class SurveyResponsesRelationManager extends RelationManager
{
    protected static string $relationship = 'surveyResponses'; // from Member model
    protected static ?string $title = 'Survey Responses';

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('survey.title')->label('Survey')->sortable()->searchable(),
                Tables\Columns\TextColumn::make('question.question')->label('Question')->limit(50)->sortable(),
                Tables\Columns\TextColumn::make('survey_response')->label('Response')->limit(50)->wrap(),
                Tables\Columns\TextColumn::make('created_at')->label('Date')->dateTime()->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->headerActions([]) // hide create button if you want read-only
            ->actions([])       // disable edit/delete
            ->bulkActions([]);
    }
}
