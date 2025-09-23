<?php

namespace App\Filament\Resources;

use App\Filament\Resources\SurveyQuestionResource\Pages;
use App\Filament\Resources\SurveyQuestionResource\RelationManagers;
use App\Models\SurveyQuestion;
use Filament\Forms;
use App\Models\Group;
use Filament\Forms\Form;
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
                    ->options([
                        'regular' => 'Regular Question',
                        'edit_name'    => 'Edit Name',
                        'edit_id' =>'Edit ID',
                        'edit_gender' => 'Edit Gender',
                        'edit_group' => 'Edit to which Group a member belongs',
                        'edit_year_of_birth' => 'Edit Year of Birth',
                        'confirm' => 'Confirm Details',
                        'info'    => 'Informational',
                    ])
                    ->default('regular')
                    ->required()
                    ->reactive(),
                    // ->afterStateUpdated(function ($state, callable $set) {
                    //     if ($state === 'edit_group') {
                    //         // force Multiple Choice
                    //         $set('answer_strictness', 'Multiple Choice');

                    //         // fetch groups from DB
                    //         $groups = Group::all()->map(function ($group, $index) {
                    //             return [
                    //                 'letter' => chr(65 + $index), // A, B, C, ...
                    //                 'answer' => $group->name,     // Or whichever column you want
                    //             ];
                    //         })->toArray();

                    //         // prefill possible answers
                    //         $set('possible_answers', $groups);
                    //     }
                    // }),


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
                                'minutes' => 'Minutes',
                                'hours' =>'Hours',
                                'days'   => 'Days',
                                'weeks'  => 'Weeks',
                                'months' => 'Months',
                            ])
                            ->default('days'),
                
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
                Tables\Columns\TextColumn::make('id')->sortable(),
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
