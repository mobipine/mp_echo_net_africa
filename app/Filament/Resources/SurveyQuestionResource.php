<?php

namespace App\Filament\Resources;

use App\Enums\QuestionPurpose;
use App\Filament\Resources\SurveyQuestionResource\Pages;
use App\Filament\Resources\SurveyQuestionResource\RelationManagers;
use App\Models\SurveyQuestion;
use Filament\Forms;
use App\Models\Group;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class SurveyQuestionResource extends Resource
{
    protected static ?string $model = SurveyQuestion::class;

    protected static ?string $navigationIcon = 'heroicon-o-question-mark-circle';

    protected static ?string $navigationGroup = 'Surveys';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('question')->required()->maxLength(255),
                Forms\Components\Select::make('answer_data_type')
                    ->options(['Alphanumeric' => 'Alphanumeric', 'Strictly Number' => 'Strictly Number'])
                    ->required(),
                Forms\Components\Textarea::make('data_type_violation_response')->maxLength(500),
                Forms\Components\Select::make('purpose')
                    ->label('Question Purpose')
                    ->options(QuestionPurpose::options())
                    ->default(QuestionPurpose::REGULAR->value)
                    ->required()
                    ->reactive()
                    ->afterStateUpdated(function (Set $set, $state) {
                        $purposeEnum = QuestionPurpose::tryFrom($state);
                        $set('purpose_slug_display', $purposeEnum?->slug());
                    })
                    // FIX: Set the initial slug display value when the form loads (hydration)
                    ->afterStateHydrated(function (Set $set, $state) {
                        $purposeEnum = QuestionPurpose::tryFrom($state);
                        $set('purpose_slug_display', $purposeEnum?->slug());
                    }), // Makes this field trigger updates on other fields

                // 2. The read-only TEXT INPUT field (Displays the slug for confirmation)
                Forms\Components\TextInput::make('purpose_slug_display')
                    ->label('System Slug Confirmation')
                    ->readOnly()
                    ->dehydrated(false) // CRITICAL: This prevents the field from saving to the database.
                    ->extraAttributes(['class' => 'bg-gray-100 dark:bg-gray-800']) // Add styling to show it's read-only
                    ->visible(fn (Get $get): bool => $get('purpose') !== null),

                Forms\Components\Select::make('answer_strictness')
                    ->options(['Open-Ended' => 'Open-Ended', 'Multiple Choice' => 'Multiple Choice'])
                    ->required()
                    ->default('Open-Ended')
                    ->native(false)
                    ->reactive()
                    ->afterStateUpdated(fn (callable $set, $state) => $set('possible_answers', null)),

                    // Add question interval and its unit
                Forms\Components\TextInput::make('question_interval')
                            ->label('Question Interval')
                            ->numeric()
                            ->helperText('After how long would you want the next question after this is dispatched?'),
                Forms\Components\Select::make('question_interval_unit')
                            ->label('Interval Unit')
                            ->native(false)
                            ->options([
                                'seconds' => 'Seconds',
                                'minutes' => 'Minutes',
                                'hours' =>'Hours',
                                'days'   => 'Days',
                                'weeks'  => 'Weeks',
                                'months' => 'Months',
                            ])
                            ->default('days'),
                Forms\Components\Toggle::make('is_recurrent')
                    ->label('Is Recurrent?')
                    ->reactive(), // reactive to trigger conditional fields

                Forms\Components\TextInput::make('recur_interval')
                    ->label('Recur Interval')
                    ->numeric()
                    ->minValue(1)
                    ->visible(fn ($get) => $get('is_recurrent')) // show only if recurrent
                    ->helperText('How often should this question be repeated?'),

                Forms\Components\Select::make('recur_unit')
                    ->label('Recur Interval Unit')
                    ->options([
                        'seconds' => 'Seconds',
                        'minutes' => 'Minutes',
                        'hours'   => 'Hours',
                        'days'    => 'Days',
                        'weeks'   => 'Weeks',
                        'months'  => 'Months',
                    ])
                    ->visible(fn ($get) => $get('is_recurrent')) // show only if recurrent
                    ->required(fn ($get) => $get('is_recurrent')),

                Forms\Components\TextInput::make('recur_times')
                    ->label('Number of Repeats')
                    ->numeric()
                    ->minValue(1)
                    ->visible(fn ($get) => $get('is_recurrent')) // show only if recurrent
                    ->helperText('How many times should this question be repeated?')
                    ->required(fn ($get) => $get('is_recurrent')),



                //do a repeater for possible_answers that only shows if answer_strictness is Multiple Choice with  afield for the letter and the answer
                Forms\Components\Repeater::make('possible_answers')
                    ->schema([
                        Forms\Components\TextInput::make('letter')->required()->maxLength(1),
                        Forms\Components\TextInput::make('answer')->required()->maxLength(255),
                    ])
                    ->columns(2)
                    ->visible(fn ($get) => $get('answer_strictness') === 'Multiple Choice')
                    ->minItems(2)
                    ->maxItems(26)
                    ->required(fn ($get) => $get('answer_strictness') === 'Multiple Choice'),


            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('id')->sortable()->searchable(),
                Tables\Columns\TextColumn::make('question')->sortable()->searchable(),
                Tables\Columns\TextColumn::make('answer_data_type')->sortable(),
            ])
            ->filters([
                //
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
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
            'index' => Pages\ListSurveyQuestions::route('/'),
            'create' => Pages\CreateSurveyQuestion::route('/create'),
            'edit' => Pages\EditSurveyQuestion::route('/{record}/edit'),
        ];
    }
}
