<?php

namespace App\Filament\Resources;

use App\Filament\Resources\RedoSurveyResource\Pages;
use App\Models\RedoSurvey;
use App\Models\Survey;
use App\Models\SurveyProgress;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Resources\Resource;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;

class RedoSurveyResource extends Resource
{
    protected static ?string $model = RedoSurvey::class;
    protected static ?string $navigationIcon = 'heroicon-o-arrow-path';
    protected static ?string $navigationGroup = 'Survey Management';
    protected static ?string $navigationLabel = 'Redo Surveys';

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('id')->sortable(),
                Tables\Columns\TextColumn::make('member.name')->label('Member Name')->searchable(),
                Tables\Columns\TextColumn::make('phone_number')->label('Phone')->searchable(),
                Tables\Columns\TextColumn::make('surveyToRedo.title')->label('Survey to Redo')->searchable(),
                Tables\Columns\TextColumn::make('reason')->wrap()->limit(50),
                Tables\Columns\BadgeColumn::make('action')
                    ->colors([
                        'warning' => 'pending',
                        'success' => 'approved',
                        'danger' => 'rejected',
                    ])
                    ->label('Status'),
                Tables\Columns\TextColumn::make('created_at')->label('Requested At')->dateTime('d M Y H:i'),
                Tables\Columns\TextColumn::make('updated_at')->label('Last Updated')->dateTime('d M Y H:i'),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('action')
                    ->options([
                        'pending' => 'Pending',
                        'approved' => 'Approved',
                        'rejected' => 'Rejected',
                    ])
            ])
            ->actions([
                Tables\Actions\Action::make('Approve')
                    ->color('success')
                    ->icon('heroicon-o-check-circle')
                    ->requiresConfirmation()
                    ->modalHeading('Approve Redo Request')
                    ->modalDescription('Are you sure you want to approve this redo request? This will resend the first question of the original survey.')
                    ->modalSubmitActionLabel('Yes, Approve')
                    ->visible(fn (RedoSurvey $record) => $record->action === 'pending')
                    ->action(function (RedoSurvey $record) {
                        try {
                            
                            $survey = $record->surveyToRedo;
                            $member = $record->member;
                            $msisdn = $record->phone_number;
                            $channel = $record->channel;

                            if (!$survey) {
                                Notification::make()
                                    ->title('Survey Missing')
                                    ->body('The survey to redo could not be found.')
                                    ->danger()
                                    ->send();
                                return;
                            }

                            // Get first question of the survey
                            $firstQuestion = getNextQuestion($survey->id);
                            if (!$firstQuestion) {
                                Notification::make()
                                    ->title('No Questions')
                                    ->body('The selected survey has no questions.')
                                    ->danger()
                                    ->send();
                                return;
                            }

                            // Cancel all existing uncompleted progress for this member
                            SurveyProgress::where('member_id', $member->id)
                                ->whereNull('completed_at')
                                ->update(['status' => 'CANCELLED']);

                            // Create new survey progress
                            SurveyProgress::create([
                                'survey_id' => $survey->id,
                                'member_id' => $member->id,
                                'current_question_id' => $firstQuestion->id,
                                'last_dispatched_at' => now(),
                                'has_responded' => false,
                                'source' => 'redo_approval',
                            ]);

                            // Format and send question
                            $message = formartQuestion($firstQuestion, $member);
                            sendSMS($msisdn, $message, $channel);

                            Notification::make()
                                ->title('Redo Approved')
                                ->body('Member has been approved and the first question has been sent.')
                                ->success()
                                ->send();
                                
                            // Update the redo request
                            $record->update(['action' => 'approved']);

                            Log::info("Redo approved for member {$member->id}, survey {$survey->id}, on channel {$channel}");
                        } catch (\Exception $e) {
                            Log::error("Redo approval failed: " . $e->getMessage());
                            Notification::make()
                                ->title('Error')
                                ->body('Something went wrong while approving the redo request.')
                                ->danger()
                                ->send();
                        }
                    }),

                Tables\Actions\Action::make('Reject')
                    ->color('danger')
                    ->icon('heroicon-o-x-circle')
                    ->requiresConfirmation()
                    ->modalHeading('Reject Redo Request')
                    ->modalDescription('Are you sure you want to reject this redo request?')
                    ->modalSubmitActionLabel('Yes, Reject')
                    ->visible(fn (RedoSurvey $record) => $record->action === 'pending')
                    ->action(function (RedoSurvey $record) {
                        $record->update(['action' => 'rejected']);
                        Notification::make()
                            ->title('Redo Request Rejected')
                            ->success()
                            ->send();
                    }),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListRedoSurveys::route('/'),
        ];
    }
}
