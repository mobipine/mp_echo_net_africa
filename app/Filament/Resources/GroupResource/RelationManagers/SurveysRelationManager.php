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

                Tables\Columns\TextColumn::make('starts_at')
                    ->label('Starts At')
                    ->dateTime()
                    ->sortable(),

                Tables\Columns\TextColumn::make('ends_at')
                    ->label('Ends At')
                    ->dateTime()
                    ->sortable(),

                Tables\Columns\IconColumn::make('was_dispatched')
                    ->label('Dispatched')
                    ->boolean()
                    
                    
            ])
            ->filters([
                //
            ])
            ->headerActions([
                Action::make('assignExistingSurvey')
                    ->label('Assign Survey')
                    ->action(function (array $data): void {
                        $this->getRelationship()->attach($data['survey_id'], [
                            'automated'      => $data['automated'],
                            'starts_at'      => $data['starts_at'],
                            'ends_at'        => $data['ends_at'],
                            'was_dispatched' => $data['was_dispatched'],
                        ]);
                    })
                    ->form([
                        Forms\Components\Select::make('survey_id')
                            ->label('Select Survey')
                            ->options(fn() => \App\Models\Survey::whereDoesntHave('groups', 
                            function ($query) {
                                $query->where('group_id', $this->ownerRecord->id);
                            })->pluck('title', 'id'))
                            ->searchable()
                            ->native(false)
                            ->required(),

                        Forms\Components\Toggle::make('automated')
                            ->label('Automated')
                            ->default(false)
                            ->helperText('Enable if the survey should be sent automatically.'),

                        Forms\Components\DateTimePicker::make('starts_at')
                            ->label('Start Date')
                            ->native(false)
                            ->required(false),

                        Forms\Components\DateTimePicker::make('ends_at')
                            ->label('End Date')
                            ->native(false)
                            ->required(false),

                        Forms\Components\Toggle::make('was_dispatched')
                            ->label('Already Dispatched?')
                            ->default(false),
                    ])
                    ->modalHeading('Assign Existing Survey')
                    ->modalButton('Assign'),
            ])
            ->actions([
                Tables\Actions\EditAction::make()
                    ->label('Edit')
                    ->action(function (array $data, $record): void {
                        Log::info('Editing pivot data', ['data' => $data, 'record' => $record]);
                        $this->getRelationship()->updateExistingPivot($record->id, [
                            'automated'      => $data['automated'],
                            'starts_at'      => $data['starts_at'],
                            'ends_at'        => $data['ends_at'],
                            'was_dispatched' => $data['was_dispatched'],
                        ]);
                    })
                    ->form([
                        Forms\Components\Toggle::make('automated')
                            ->label('Automated')
                            ->helperText('Enable if the survey should be sent automatically.'),

                        Forms\Components\DateTimePicker::make('starts_at')
                            ->label('Start Date')
                            ->native(false),

                        Forms\Components\DateTimePicker::make('ends_at')
                            ->label('End Date')
                            ->native(false),

                        Forms\Components\Toggle::make('was_dispatched')
                            ->label('Already Dispatched?'),
                    ]),

                Tables\Actions\DeleteAction::make()
                    ->label('Detach')
                    ->action(fn ($record) => $this->getRelationship()->detach($record->id)),
            ])
            ->bulkActions([
                Tables\Actions\DetachBulkAction::make(),
            ]);
    }

    public function canCreate(): bool
    {
        return false;
    }
}
