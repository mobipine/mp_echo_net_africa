<?php

namespace App\Filament\Pages;

use App\Filament\Widgets\SmsResponsesStatsOverview;
use App\Jobs\GenerateCreditUtilizationReportJob;
use App\Models\CreditReportExport;
use App\Models\Group;
use App\Models\Survey;
use App\Models\SurveyQuestion;
use App\Services\ComprehensiveSurveyReportService;
use Filament\Actions\Action;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Dashboard\Concerns\HasFiltersForm;
use Filament\Pages\Page;
use Illuminate\Support\Str;
use Throwable;

class SmsResponseReports extends Page
{
    use HasFiltersForm;

    protected static ?string $navigationIcon = 'heroicon-o-chart-bar';

    protected static string $view = 'filament.pages.survey-response-reports';

    protected static ?string $navigationGroup = 'Analytics';

    protected static ?string $title = 'SMS Response Reports';

    protected static ?int $navigationSort = 3;

    public static function shouldRegisterNavigation(): bool
    {
        return false;
    }

    public function getSubheading(): ?string
    {
        return 'Analyze survey engagement and generate complete response, drop-off, and SMS credit workbooks.';
    }

    protected function filtersForm(Form $form): Form
    {
        return $form->schema([
            Section::make('Survey reporting scope')
                ->description('Choose one survey and group for the comprehensive workbook. The question selector only drills into the on-screen analysis.')
                ->schema([
                    Select::make('survey_id')
                        ->label('Survey')
                        ->options(fn (): array => Survey::query()->orderBy('title')->pluck('title', 'id')->all())
                        ->placeholder('Select a survey')
                        ->searchable()
                        ->preload()
                        ->live()
                        ->afterStateUpdated(fn (callable $set) => $set('question_id', null)),

                    Select::make('group_id')
                        ->label('Group')
                        ->options(fn (): array => Group::query()->orderBy('name')->pluck('name', 'id')->all())
                        ->placeholder('Select a group')
                        ->searchable()
                        ->preload()
                        ->live(),

                    Select::make('question_id')
                        ->label('On-screen question')
                        ->options(function (callable $get): array {
                            $surveyId = $get('survey_id');

                            return SurveyQuestion::query()
                                ->when($surveyId, fn ($query) => $query->whereHas(
                                    'surveys',
                                    fn ($query) => $query->where('surveys.id', $surveyId)
                                ))
                                ->orderBy('question')
                                ->pluck('question', 'id')
                                ->all();
                        })
                        ->placeholder('All questions')
                        ->searchable()
                        ->live(),
                ])
                ->columns(3),
        ]);
    }

    public function getResponseWidgets(): array
    {
        return [
            SmsResponsesStatsOverview::make(['filters' => $this->filters]),
        ];
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('generate_comprehensive_report')
                ->label('Generate comprehensive report')
                ->icon('heroicon-o-document-arrow-down')
                ->color('success')
                ->visible(fn (): bool => filled($this->filters['survey_id'] ?? null)
                    && filled($this->filters['group_id'] ?? null))
                ->requiresConfirmation()
                ->modalHeading('Generate comprehensive survey workbook')
                ->modalDescription('This queues a private Excel workbook with all group members, every survey question and response, participation outcomes, question drop-offs, and SMS credit utilization across all available dates.')
                ->modalSubmitActionLabel('Queue workbook')
                ->action(fn () => $this->queueComprehensiveReport()),
        ];
    }

    public function getRecentExports()
    {
        return CreditReportExport::query()
            ->where('user_id', auth()->id())
            ->latest()
            ->limit(8)
            ->get();
    }

    public function exportStatusColor(string $status): string
    {
        return match ($status) {
            CreditReportExport::STATUS_COMPLETED => 'success',
            CreditReportExport::STATUS_FAILED => 'danger',
            CreditReportExport::STATUS_PROCESSING => 'warning',
            default => 'gray',
        };
    }

    public function exportStatusLabel(string $status): string
    {
        return $status === CreditReportExport::STATUS_PROCESSING
            ? 'Generating'
            : Str::headline($status);
    }

    public function formatFileSize(?int $bytes): string
    {
        if (! $bytes) {
            return 'Size pending';
        }

        return match (true) {
            $bytes >= 1_073_741_824 => number_format($bytes / 1_073_741_824, 2).' GB',
            $bytes >= 1_048_576 => number_format($bytes / 1_048_576, 2).' MB',
            $bytes >= 1024 => number_format($bytes / 1024, 1).' KB',
            default => number_format($bytes).' bytes',
        };
    }

    public function exportRowLabel(CreditReportExport $export): string
    {
        if ($export->row_count === null) {
            return 'Rows pending';
        }

        $unit = Str::contains($export->file_name, 'comprehensive_survey_report')
            ? 'members'
            : 'transactions';

        return number_format($export->row_count).' '.$unit;
    }

    private function queueComprehensiveReport(): void
    {
        $userId = auth()->id();
        abort_unless($userId, 403);

        try {
            $scope = app(ComprehensiveSurveyReportService::class)->scope([
                'survey_ids' => [$this->filters['survey_id'] ?? null],
                'group_ids' => [$this->filters['group_id'] ?? null],
            ]);
            $uuid = (string) Str::uuid();
            $fileName = collect([
                'echo_net_africa',
                Str::slug($scope['survey']->title, '_'),
                Str::slug($scope['group']->name, '_'),
                'comprehensive_survey_report',
                now()->format('Y_m_d_His'),
            ])->filter()->implode('_').'.xlsx';

            $report = CreditReportExport::query()->create([
                'uuid' => $uuid,
                'user_id' => $userId,
                'status' => CreditReportExport::STATUS_QUEUED,
                'filters' => $scope['credit_filters'],
                'disk' => 'local',
                'file_path' => "private/credit-reports/{$userId}/{$uuid}.xlsx",
                'file_name' => $fileName,
            ]);

            GenerateCreditUtilizationReportJob::dispatch($report->id)->afterCommit();

            Notification::make()
                ->title('Comprehensive survey report queued')
                ->body('The private workbook is being generated in the background. Progress appears in the report jobs section below.')
                ->success()
                ->send();
        } catch (Throwable $exception) {
            report($exception);

            Notification::make()
                ->title('Report could not be queued')
                ->body('Please verify the survey and group, then try again.')
                ->danger()
                ->send();
        }
    }
}
