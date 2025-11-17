<?php

namespace App\Filament\Resources;

use App\Filament\Resources\SurveyProgressResource\Pages;
use App\Models\SurveyProgress;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class SurveyProgressResource extends Resource
{
    protected static ?string $model = SurveyProgress::class;

    protected static ?string $navigationIcon = 'heroicon-s-list-bullet';
    protected static ?string $navigationGroup = 'Surveys';
    protected static ?string $navigationLabel = 'Survey Progress';
    protected static ?string $pluralLabel = 'Survey Progress';

    public static function form(Form $form): Form
    {
        $form->disabled();
        return $form->schema([
            Forms\Components\Select::make('survey_id')
                ->relationship('survey', 'title')
                ->required()
                ->searchable()
                ->preload(),

            Forms\Components\Select::make('member_id')
                ->relationship('member', 'name')
                ->required()
                ->searchable()
                ->preload(),

            Forms\Components\Select::make('current_question_id')
                ->relationship('currentQuestion', 'question')
                ->searchable()
                ->preload()
                ->label('Current Question'),

            Forms\Components\TextInput::make('number_of_reminders')
                ->numeric()
                ->default(0),

            Forms\Components\Toggle::make('has_responded')
                ->label('Has Responded'),

            Forms\Components\TextInput::make('status')
                ->default('ACTIVE'),

            Forms\Components\DateTimePicker::make('last_dispatched_at'),
            Forms\Components\DateTimePicker::make('completed_at'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('survey.title')->label('Survey')->sortable()->searchable(),
                Tables\Columns\TextColumn::make('member.name')->label('Member')->sortable()->searchable(),
                Tables\Columns\TextColumn::make('currentQuestion.question')->label('Current Question'),
                Tables\Columns\TextColumn::make('number_of_reminders')->label('Reminders'),
                Tables\Columns\IconColumn::make('has_responded')->boolean(),
                Tables\Columns\TextColumn::make('status'),
                Tables\Columns\TextColumn::make('last_dispatched_at')->dateTime(),
                Tables\Columns\TextColumn::make('created_at')->dateTime(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')->options([
                    'ACTIVE' => 'ACTIVE',
                    'UPDATING_DETAILS' => 'UPDATING_DETAILS',
                    'COMPLETED' => 'COMPLETED',
                ]),
                Tables\Filters\Filter::make('3_reminders')
                ->label('3 Reminders')
                ->query(fn ($query) => $query->where('number_of_reminders', '>=', 3)),

                Tables\Filters\Filter::make('has_responded'),
            ])
            ->defaultSort('created_at', 'desc')
            ->actions([
                Tables\Actions\EditAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\DeleteBulkAction::make(),
            ]);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListSurveyProgress::route('/'),
            
        ];
    }
}
