<?php

namespace App\Filament\Resources;

use App\Filament\Resources\SurveyResponseResource\Pages;
use App\Models\SurveyResponse;
use App\Models\Survey;
use App\Models\SurveyQuestion;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use pxlrbt\FilamentExcel\Actions\Tables\ExportBulkAction;
use pxlrbt\FilamentExcel\Exports\ExcelExport;

class SurveyResponseResource extends Resource
{
    protected static ?string $model = SurveyResponse::class;

    protected static ?string $navigationIcon = 'heroicon-o-chat-bubble-left-ellipsis';
    protected static ?string $navigationGroup = 'Surveys';

    public static function canCreate(): bool
    {
        return false;
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist
            ->schema([
                Infolists\Components\Section::make('Response Details')
                    ->schema([
                        Infolists\Components\TextEntry::make('survey.title')
                            ->label('Survey')
                            ->size(Infolists\Components\TextEntry\TextEntrySize::Large),
                        Infolists\Components\TextEntry::make('member.name')
                            ->label('Participant Name')
                            ->placeholder('Unknown / unmatched')
                            ->size(Infolists\Components\TextEntry\TextEntrySize::Large),
                        Infolists\Components\TextEntry::make('msisdn')
                            ->label('Phone Number')
                            ->size(Infolists\Components\TextEntry\TextEntrySize::Large),
                        Infolists\Components\TextEntry::make('question.question')
                            ->label('Question')
                            ->wrap()
                            ->columnSpanFull(),
                        Infolists\Components\TextEntry::make('survey_response')
                            ->label('Response')
                            ->wrap()
                            ->size(Infolists\Components\TextEntry\TextEntrySize::Large)
                            ->color('primary')
                            ->columnSpanFull(),
                        Infolists\Components\TextEntry::make('created_at')
                            ->label('Responded At')
                            ->dateTime('M d, Y H:i'),
                    ])
                    ->columns(3),
            ]);
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Select::make('survey_id')
                    ->label('Survey')
                    ->options(fn (): array => Survey::pluck('title', 'id')->all())
                    ->required()
                    ->native(false)
                    ->searchable(),

                Forms\Components\TextInput::make('msisdn')
                    ->label('Phone Number')
                    ->required()
                    ->maxLength(15)
                    ->placeholder('e.g. 0712345678'),

                Forms\Components\Select::make('question_id')
                    ->label('Question')
                    ->options(fn (): array => SurveyQuestion::pluck('question', 'id')->all())
                    ->required()
                    ->native(false)
                    ->searchable(),

                Forms\Components\TextInput::make('survey_response')
                    ->label('Response')
                    ->required()
                    ->maxLength(500)
                    ->placeholder('e.g. Yes, No, 123'),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('id')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('survey.title')
                    ->label('Survey')
                    ->sortable()
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: false),

                Tables\Columns\TextColumn::make('member.name')
                    ->label('Participant')
                    ->sortable()
                    ->searchable()
                    ->placeholder('Unknown')
                    ->toggleable(isToggledHiddenByDefault: false),

                Tables\Columns\TextColumn::make('msisdn')
                    ->label('Phone Number')
                    ->sortable()
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('question.question')
                    ->label('Question')
                    ->sortable()
                    ->searchable()
                    ->wrap()
                    ->limit(60)
                    ->toggleable(isToggledHiddenByDefault: false),

                Tables\Columns\TextColumn::make('survey_response')
                    ->label('Response')
                    ->limit(50)
                    ->wrap()
                    ->sortable()
                    ->badge()
                    ->color('primary'),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Responded At')
                    ->dateTime('M d, Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: false),
            ])
            ->filters([
                SelectFilter::make('survey_id')
                    ->label('Survey')
                    ->relationship('survey', 'title')
                    ->searchable()
                    ->preload(),

                SelectFilter::make('question_id')
                    ->label('Question')
                    ->relationship('question', 'question')
                    ->searchable()
                    ->preload(),

                Filter::make('created_at')
                    ->form([
                        Forms\Components\DatePicker::make('created_from')
                            ->label('From date'),
                        Forms\Components\DatePicker::make('created_until')
                            ->label('Until date'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when($data['created_from'], fn (Builder $q, $date) => $q->whereDate('created_at', '>=', $date))
                            ->when($data['created_until'], fn (Builder $q, $date) => $q->whereDate('created_at', '<=', $date));
                    }),
            ])
            ->actions([
                Tables\Actions\ViewAction::make()
                    ->infolist(fn (Infolist $infolist): Infolist => static::infolist($infolist))
                    ->modalHeading(fn ($record): string => "Response from {$record->msisdn}"),

                Tables\Actions\Action::make('view_participant_responses')
                    ->label('View all responses')
                    ->icon('heroicon-o-user-circle')
                    ->color('info')
                    ->modalHeading(fn ($record): string => "All survey responses from {$record->msisdn}")
                    ->modalContent(fn ($record) => view('filament.resources.survey-response-resource.participant-responses', [
                        'responses' => SurveyResponse::where('msisdn', $record->msisdn)
                            ->with(['survey', 'question', 'member'])
                            ->orderBy('survey_id')
                            ->orderBy('created_at')
                            ->get(),
                        'msisdn' => $record->msisdn,
                    ]))
                    ->modalSubmitActionLabel('Close')
                    ->modalCancelAction(false)
                    ->modalWidth('2xl'),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),

                    ExportBulkAction::make()
                        ->exports([
                            ExcelExport::make()->fromTable(),
                        ])
                        ->label('Export to Excel'),
                ]),
            ])
            ->defaultSort('created_at', 'desc');
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
            'index' => Pages\ListSurveyResponses::route('/'),
        ];
    }
}