<?php

namespace App\Filament\Resources\SurveyRelationManagerResource\RelationManagers;

use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Actions\Action;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\Facades\Log;

class SurveysRelationManager extends RelationManager
{
    protected static string $relationship = 'surveys';

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                //create a dropdown that will allow the user to select from the available surveys
                // Forms\Components\Select::make('survey_id')
                //     ->label('Survey')
                //     ->relationship('surveys')
                //     ->searchable()
                //     ->required(),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('title')
            ->columns([
                Tables\Columns\TextColumn::make('title')->label('Survey Title'),
                

                Tables\Columns\IconColumn::make('automated')
                    ->label('Automated')
                    ->boolean()
                    ->trueIcon('heroicon-o-check-circle')
                    ->falseIcon('heroicon-o-x-circle')
                    ->sortable(),

            ])
            ->filters([
                //
            ])
            ->headerActions([
                Action::make('assignExistingSurvey')
                    ->label('Assign Survey')
                    ->action(function (array $data): void {
                        $this->getRelationship()->attach($data['survey_id'], [
                            'automated' => $data['automated'],
                        ]);
                    })
                    ->form([
                        Forms\Components\Select::make('survey_id')
                            ->label('Select Survey')
                            ->options(fn() => \App\Models\Survey::whereDoesntHave('groups', function ($query) {
                                $query->where('group_id', $this->ownerRecord->id);
                            })->pluck('title', 'id'))
                            ->searchable()
                            ->native(false)
                            ->required(),
                        Forms\Components\Toggle::make('automated')
                            ->label('Automated')
                            ->default(false)
                            ->helperText('If enabled, the survey will be sent automatically to group members based on the survey schedule.')
                            ->columnSpan(2),
                    ])
                    ->modalHeading('Assign Existing Survey')
                    ->modalButton('Assign'),
            ])
            ->actions([
                Tables\Actions\EditAction::make()
                    ->label('Edit')
                    ->action(function (array $data, $record): void {
                        // dd($data, $record);
                        Log::info('Editing pivot data', ['data' => $data, 'record' => $record]);
                        $this->getRelationship()->updateExistingPivot($record->id, [
                            'automated' => $data['automated'],
                        ]);
                    })
                    ->form([
                        Forms\Components\Toggle::make('automated')
                            ->label('Automated')
                            // ->default(fn ($record) => $record['automated'])
                            ->helperText('If enabled, the survey will be sent automatically to group members based on the survey schedule.')
                            ->columnSpan(2),

                       
                    ]),

                Tables\Actions\DeleteAction::make()
                    ->label('Detach')
                    ->action(function ($record): void {
                        // $this->getRelationship()->detach($record->id);
                        $this->getRelationship()->detach($record->id);
                    }),

            ])
            ->bulkActions([
                Tables\Actions\DetachBulkAction::make(),
            ]);
    }

    //change create button name to Assign Survey
    public function canCreate(): bool
    {
        return false;
    }
}
