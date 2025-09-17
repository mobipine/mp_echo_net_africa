<?php

namespace App\Filament\Resources;

use App\Filament\Resources\SurveyResource\Pages;
use App\Filament\Resources\SurveyResource\RelationManagers\QuestionsRelationManager;
use App\Filament\Resources\SurveyResource\RelationManagers\ResponsesRelationManager;
use App\Models\Survey;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Tables;

class SurveyResource extends Resource
{
    protected static ?string $model = Survey::class;

    protected static ?string $navigationIcon = 'heroicon-o-clipboard';

    protected static ?string $navigationGroup = 'Surveys';

    public static function form(Forms\Form $form): Forms\Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('title')->required()->maxLength(255),
                Forms\Components\Textarea::make('description')->maxLength(500),
                Forms\Components\TextInput::make('trigger_word')->required()->maxLength(255),
                Forms\Components\Textarea::make('final_response')->maxLength(500),
                Forms\Components\Select::make('status')
                    ->options(['Active' => 'Active', 'Inactive' => 'Inactive'])->native(false)
                    ->required(),
                Forms\Components\DatePicker::make('start_date')->native(false),
                Forms\Components\DatePicker::make('end_date')->native(false),
                Forms\Components\Toggle::make('participant_uniqueness'),
                Forms\Components\TextInput::make('continue_confirmation_interval')
                            ->label('Continue Confirmation Interval')
                            ->numeric()
                            ->helperText('If a user does not respond, after how long would you want them sent a confirmation message to know if they wish to continue with the survey?')
                            ->required(),
                 Forms\Components\Select::make('continue_confirmation_interval_unit')
                            ->label('Continue Confirmation Interval Unit')
                            ->native(false)
                            ->options([
                                'minutes' => 'Minutes',
                                'hours' =>'Hours',
                                'days'   => 'Days',
                                'weeks'  => 'Weeks',
                                'months' => 'Months',
                            ])
                            ->required(),
                Forms\Components\TextInput::make('continue_confirmation_question')
                            ->label('Continue Confirmation Question')
                            ->helperText('If a user does not respond, what message would you want them to receive? Use {member} where you want the name of the member to appear and {group} where you want the group name to appear.')
                            ->required(),
            ]);
    }

    public static function table(Tables\Table $table): Tables\Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('id')->sortable(),
                Tables\Columns\TextColumn::make('title')->sortable()->searchable(),
                Tables\Columns\TextColumn::make('status')->sortable(),
                Tables\Columns\TextColumn::make('start_date')->date()->sortable(),
                Tables\Columns\TextColumn::make('end_date')->date()->sortable(),
            ])
            ->filters([
                //
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\DeleteBulkAction::make(),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            QuestionsRelationManager::class,
            ResponsesRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListSurveys::route('/'),
            'create' => Pages\CreateSurvey::route('/create'),
            'edit' => Pages\EditSurvey::route('/{record}/edit'),
        ];
    }
}
