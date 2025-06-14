<?php

namespace App\Filament\Resources\SurveyResource\RelationManagers;

use Filament\Forms;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;

class ResponsesRelationManager extends RelationManager
{
    protected static string $relationship = 'responses';

    protected static ?string $recordTitleAttribute = 'id';

    public function form(Forms\Form $form): Forms\Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('participant_id')->required(),
                Forms\Components\Textarea::make('response_data')->required(),
            ]);
    }

    public function table(Tables\Table $table): Tables\Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('id')->sortable(),
                Tables\Columns\TextColumn::make('participant_id')->sortable()->searchable(),
                Tables\Columns\TextColumn::make('response_data')->limit(50),
            ])
            ->filters([
                //
            ])
            ->headerActions([
                Tables\Actions\CreateAction::make(),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\DeleteBulkAction::make(),
            ]);
    }
}
