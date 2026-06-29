<?php

namespace App\Filament\Pages;


use App\Filament\Widgets\SmsResponsesStatsOverview;
use Filament\Pages\Page;
use App\Models\Survey;
use App\Models\Group;
use Filament\Pages\Dashboard\Concerns\HasFiltersForm;
use Filament\Forms\Form;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Section;
use Filament\Pages\Actions\Action;
use Filament\Notifications\Notification;
use App\Jobs\GenerateSurveyReportJob;

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

                        Select::make('group_id')
                            ->label('Filter by Group')
                            ->options(Group::orderBy('name')->pluck('name', 'id'))
                            ->placeholder('All Groups')
                            ->searchable()
                            ->nullable()
                            ->reactive(),

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
        // Get filters (HasFiltersForm provides $this->filters)
        $surveyId = $this->filters['survey_id'] ?? null;
        $groupId = $this->filters['group_id'] ?? null;

        // Only show download button once BOTH a survey and a group are selected.
        // The complete report exports every question as a column for the members
        // of the chosen group, so both filters are required.
        if (!$surveyId || !$groupId) {
            return [];
        }

        $survey = Survey::find($surveyId);
        $group = Group::find($groupId);
        if (!$survey || !$group) {
            return [];
        }

        // Sanitize survey + group names for the filename
        $sanitizedTitle = preg_replace('/[^a-zA-Z0-9_-]/', '_', $survey->title);
        $sanitizedGroup = preg_replace('/[^a-zA-Z0-9_-]/', '_', $group->name);

        return [
            Action::make('download_survey_report')
                ->label("Export {$group->name} Complete Survey Report")
                ->icon('heroicon-o-document-arrow-down')
                ->color('success')
                ->action(function () use ($surveyId, $groupId, $sanitizedTitle, $sanitizedGroup, $survey, $group) {
                    try {
                        $diskName = 'public';
                        $directory = 'exports';
                        $filenameOnly = strtolower($sanitizedGroup . '_' . $sanitizedTitle) . '_report_' . now()->format('Y_m_d_H_i_s') . '.xlsx';
                        $fullFilePath = $directory . '/' . $filenameOnly;
                        $userId = auth()->id();

                        // Create unique progress key (scoped to survey + group)
                        $progressKey = "export_progress_{$userId}_{$surveyId}_{$groupId}_" . md5($fullFilePath . now()->timestamp);

                        // Check if a job for this survey/group/user is already in progress
                        $uniqueJobId = "survey_export_{$userId}_{$surveyId}_{$groupId}_" . md5($fullFilePath);
                        $cacheKey = "export_job_running_{$uniqueJobId}";

                        if (\Illuminate\Support\Facades\Cache::has($cacheKey)) {
                            Notification::make()
                                ->title('Export Already in Progress')
                                ->body("An export for {$survey->title} ({$group->name}) is already being generated. Please wait for it to complete.")
                                ->warning()
                                ->send();
                            return;
                        }

                        // Mark job as running (expires in 1 hour)
                        \Illuminate\Support\Facades\Cache::put($cacheKey, true, 3600);

                        // Dispatch the job to queue (returns immediately)
                        // The job implements ShouldBeUnique to prevent duplicate runs
                        // The job will clear the cache key in its finally block
                        GenerateSurveyReportJob::dispatch($surveyId, $userId, $diskName, $fullFilePath, $progressKey, $groupId);

                        // Log for debugging
                        \Illuminate\Support\Facades\Log::info('Survey report job dispatched', [
                            'survey_id' => $surveyId,
                            'group_id' => $groupId,
                            'user_id' => $userId,
                            'file_path' => $fullFilePath,
                            'queue_connection' => config('queue.default'),
                        ]);

                        // Send immediate notification
                        Notification::make()
                            ->title('Export Started')
                            ->body("Your {$survey->title} report for {$group->name} is being generated in the background. You will be notified when it's ready for download.")
                            ->success()
                            ->send();
                    } catch (\Exception $e) {
                        \Illuminate\Support\Facades\Log::error('Failed to queue survey report export: ' . $e->getMessage());
                        Notification::make()
                            ->title('Export Failed')
                            ->body('Failed to start the export. Please try again or contact support.')
                            ->danger()
                            ->send();
                    }
                })
                ->requiresConfirmation(false),
        ];
    }
}

