<?php

namespace App\Filament\Resources\SurveyResource\RelationManagers;

use Filament\Forms;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Actions\Action;
use Illuminate\Database\Eloquent\Model;

class QuestionsRelationManager extends RelationManager
{
    protected static string $relationship = 'questions';

    protected static ?string $recordTitleAttribute = 'question';

    public function form(Forms\Form $form): Forms\Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('question')->required()->maxLength(255),
                Forms\Components\Select::make('answer_data_type')
                    ->options(['Alphanumeric' => 'Alphanumeric', 'Strictly Number' => 'Strictly Number'])
                    ->required(),
                Forms\Components\Textarea::make('data_type_violation_response')->maxLength(500),
            ]);
    }

    public function table(Tables\Table $table): Tables\Table
    {
        return $table
            ->reorderable('position') // Enable drag-and-drop reordering
            ->columns([
                Tables\Columns\TextColumn::make('position')->sortable(),
                Tables\Columns\TextColumn::make('question')->sortable()->searchable(),
                Tables\Columns\TextColumn::make('answer_data_type')->sortable(),
            ])
            ->filters([
                //
            ])
            ->headerActions([
                // Tables\Actions\CreateAction::make(),
                Action::make('assignExistingQuestion')
                    ->label('Assign Existing Question')
                    ->action(function (array $data): void {
                        $this->getRelationship()->attach($data['question_id'], ['position' => $this->getRelationship()->count() + 1]);
                    })
                    ->form([
                        Forms\Components\Select::make('question_id')
                            ->label('Select Question')
                            // ->options(\App\Models\SurveyQuestion::pluck('question', 'id'))
                            //in options make sure not to include questions that are already assigned to this survey
                            ->options(fn () => \App\Models\SurveyQuestion::whereDoesntHave('surveys', function ($query) {
                                $query->where('survey_id', $this->ownerRecord->id);
                            })->pluck('question', 'id'))
                            ->searchable()
                            ->native(false)
                            ->required(),
                    ])
                    ->modalHeading('Assign Existing Question')
                    ->modalButton('Assign'),

                    // Action::make('createNewQuestion')
                    // ->label('Create New Question')
                    // ->action(function (array $data): void {
                    //     $newQuestion = \App\Models\SurveyQuestion::create($data);
                    //     $this->getRelationship()->attach($newQuestion->id, ['position' => $this->getRelationship()->count() + 1]);
                    // })
                    // ->form([
                    //     Forms\Components\TextInput::make('question')->required()->maxLength(255),
                    //     Forms\Components\Select::make('answer_data_type')
                    //         ->options(['Alphanumeric' => 'Alphanumeric', 'Strictly Number' => 'Strictly Number'])
                    //         ->required(),
                    //     Forms\Components\Textarea::make('data_type_violation_response')->maxLength(500),
                    // ])
                    // ->modalHeading('Create New Question')
                    // ->modalButton('Create'),
            ])
            ->actions([
                Tables\Actions\EditAction::make()
                    ->url(fn (Model $record): string => \App\Filament\Resources\SurveyQuestionResource::getUrl('edit', ['record' => $record])),
                Action::make('remove')
                    ->label('Remove')
                    ->action(function (Model $record): void {
                        $this->getRelationship()->detach($record->id);
                    })
                    ->icon('heroicon-o-x-circle')
                    ->requiresConfirmation()
                    ->color('danger'),
            ])
            ->bulkActions([
                Tables\Actions\DeleteBulkAction::make(),
            ]);
    }
}
