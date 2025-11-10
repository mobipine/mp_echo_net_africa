<?php

namespace App\Filament\Pages;

use App\Filament\Widgets\GroupSurveySummaryTable;
use App\Models\Group;
use App\Models\Survey;
use Filament\Forms\Form;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Section;
use Filament\Pages\Page;
use Filament\Pages\Dashboard\Concerns\HasFiltersForm; 
use App\Filament\Widgets\SurveyStatsOverview; 
use App\Filament\Widgets\SurveyDropoutAnalysis; 
use App\Filament\Widgets\SurveyDropoutTable; 

class SurveyReports extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-chart-pie';
    protected static ?string $navigationLabel = 'Survey Reports';
    protected static ?string $slug = 'survey-reports';
    protected static ?string $navigationGroup = 'Analytics';
    protected static string $view = 'filament.pages.survey-reports';

    use HasFiltersForm;

    protected function filtersForm(Form $form): Form
    {
        return $form
            ->schema([
                Section::make('Filters')
                    ->schema([
                        Select::make('group_id')
                            ->label('Filter by Group(s)')
                            ->options(Group::pluck('name', 'id'))
                            ->placeholder('All Groups')
                            ->multiple()
                            ->nullable(),

                        Select::make('survey_id')
                            ->label('Filter by Survey')
                            ->options(Survey::pluck('title', 'id'))
                            ->placeholder('All Surveys')
                            ->nullable(),
                    ])
                    ->columns(2),
            ]);
    }

    public function applyFilters(): void
    {
        // This just refreshes the page using current query parameters
        $this->redirect(request()->fullUrl());
    }

    protected function getHeaderWidgets(): array
    {
        return [
            SurveyStatsOverview::make(['filters' => $this->filters]),
            SurveyDropoutAnalysis::make(['filters' => $this->filters]),
            SurveyDropoutTable::make(['filters' => $this->filters]), 
            GroupSurveySummaryTable::make(['filters' => $this->filters]),
        ];
    }
}
