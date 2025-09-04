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
                        Forms\Components\Select::make('question_interval_unit')
                            ->label('Interval Unit')
                            ->native(false)
                            ->options([
                                'minutes' => 'Minutes',
                                'hours' =>'Hours',
                                'days'   => 'Days',
                                'weeks'  => 'Weeks',
                                'months' => 'Months',
                            ])
                            ->default('days'),

                        Forms\Components\TextInput::make('question_interval')
                            ->label('Question Interval')
                            ->numeric()
                            ->helperText('The time between subsequent questions.'),

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
                            'question_interval'      => $data['question_interval'],
                            'question_interval_unit' => $data['question_interval_unit'],
                        ]);
                    })
                    ->form([
                        Forms\Components\Select::make('question_interval_unit')
                            ->label('Interval Unit')
                            ->options([
                                'minutes' => 'Minutes',
                                'hours' =>'Hours',
                                'days'   => 'Days',
                                'weeks'  => 'Weeks',
                                'months' => 'Months',
                            ])
                            ->default('days'),

                        Forms\Components\TextInput::make('question_interval')
                            ->label('Question Interval')
                            ->numeric()
                            ->helperText('The time between subsequent questions.'),

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
                

                Tables\Actions\Action::make('dispatchManually')
                    ->label('Send Now')
                    ->icon('heroicon-s-paper-airplane') // A "send" icon
                    ->color('success') // Green button
                    ->requiresConfirmation()
                    ->modalHeading('Send Survey Manually')
                    ->modalDescription("Are you sure you want to manually send the survey  to all members of this group? This will happen immediately and bypass the schedule.")
                    ->action(function (\App\Models\Survey $record): void {
                        // Dispatch the job immediately, passing the current group and this survey
                        \App\Jobs\SendSurveyToGroupJob::dispatch($this->ownerRecord, $record);
                        
                        // Optional: Update the pivot to mark it as dispatched to prevent auto-send later
                        $this->getRelationship()->updateExistingPivot($record->id, [
                            'was_dispatched' => true,
                            // You might also want to update the starts_at time to now?
                            // 'starts_at' => now(),
                        ]);
                        
                        // Send a notification to the admin within Filament
                        \Filament\Notifications\Notification::make()
                            ->title('Survey Dispatched')
                            ->body("The survey '{$record->title}' is being sent to the group in the background.")
                            ->success()
                            ->send();
                    }),
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
