<?php

namespace App\Filament\Pages;


use App\Filament\Widgets\SmsResponsesStatsOverview;
use Filament\Pages\Page;
use App\Models\Survey;
use Filament\Pages\Dashboard\Concerns\HasFiltersForm;
use Filament\Forms\Form;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Section;
use Filament\Pages\Actions\Action;
use Maatwebsite\Excel\Facades\Excel;
use App\Exports\ExportSurveyReport;

class SmsResponseReports extends Page
{

    protected static ?string $navigationIcon = 'heroicon-o-chart-bar';
    protected static string $view = 'filament.pages.survey-response-reports';
    protected static ?string $navigationGroup = 'Analytics';
    protected static ?string $title = 'Sms Response Reports';
    protected static ?int $navigationSort = 3;

    use HasFiltersForm;
    protected function filtersForm(Form $form): Form
    {
        return $form
            ->schema([
                Section::make('Filters')
                    ->schema([

                        Select::make('survey_id')
                            ->label('Filter by Survey')
                            ->options(Survey::pluck('title', 'id'))
                            ->placeholder('All Surveys')
                            ->reactive()
                            ->afterStateUpdated(function (callable $set) {
                                $set('question_id', null);
                            })
                            ->nullable(),

                        Select::make('question_id')
                            ->label('Filter by Question')
                            ->options(function (callable $get) {
                                $surveyId = $get('survey_id');

                                // If no survey selected → show all questions
                                if (!$surveyId) {
                                    return \App\Models\SurveyQuestion::pluck('question', 'id');
                                }

                                // Fetch questions mapped to this survey via pivot
                                return \App\Models\SurveyQuestion::query()
                                    ->whereIn('id', function ($query) use ($surveyId) {
                                        $query->select('survey_question_id')
                                            ->from('survey_question_survey')
                                            ->where('survey_id', $surveyId);
                                    })
                                    ->pluck('question', 'id');
                            })
                            ->placeholder('All Questions')
                            ->nullable()
                            ->reactive(),

                    ])
                    ->columns(3),
            ]);
    }


    protected function getHeaderWidgets(): array
    {

        return [
            SmsResponsesStatsOverview::make(['filters' => $this->filters]),
        ];
    }

    protected function getActions(): array
    {
        // Get survey_id from filters (HasFiltersForm provides $this->filters)
        $surveyId = $this->filters['survey_id'] ?? null;

        // Only show download button if a survey is selected
        if (!$surveyId) {
            return [];
        }

        $survey = Survey::find($surveyId);
        if (!$survey) {
            return [];
        }

        // Sanitize survey title for filename
        $sanitizedTitle = preg_replace('/[^a-zA-Z0-9_-]/', '_', $survey->title);
        $sanitizedTitle = str_replace(' ', '_', $sanitizedTitle);

        return [
            Action::make('download_survey_report')
                ->label("Download {$survey->title} Report")
                ->icon('heroicon-o-document-arrow-down')
                ->color('success')
                ->action(function () use ($surveyId, $sanitizedTitle) {
                    $filename = strtolower($sanitizedTitle) . '_report_' . now()->format('Y_m_d_H_i_s') . '.xlsx';
                    return Excel::download(new ExportSurveyReport($surveyId), $filename);
                }),
        ];
    }
}

