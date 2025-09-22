<?php

namespace App\Filament\Resources\SurveyResource\RelationManagers;

use Filament\Forms;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use pxlrbt\FilamentExcel\Actions\Tables\ExportBulkAction;
use pxlrbt\FilamentExcel\Exports\ExcelExport;

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
                Tables\Columns\TextColumn::make('survey.title')->label('Survey Name')->sortable()->searchable(),
                Tables\Columns\TextColumn::make('msisdn')->label('MSISDN')->sortable()->searchable()->toggleable(isToggledHiddenByDefault:true),
                Tables\Columns\TextColumn::make('question.question')->label('Question')->sortable()->searchable(),
                Tables\Columns\TextColumn::make('survey_response')->label('Response')->limit(50)->sortable(),
                Tables\Columns\TextColumn::make('created_at')->dateTime()->sortable()->toggleable(isToggledHiddenByDefault:true),
                Tables\Columns\TextColumn::make('updated_at')->dateTime()->sortable()->toggleable(isToggledHiddenByDefault:true),
            ])
            ->filters([
                //
            ])
            ->headerActions([
                // Tables\Actions\CreateAction::make(),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\DeleteBulkAction::make(),
                ExportBulkAction::make()
                        ->exports([
                            ExcelExport::make()
                                ->fromTable()
                            
                        ])
                    ->label('Export to Excel'),
            ]);
    }
}
