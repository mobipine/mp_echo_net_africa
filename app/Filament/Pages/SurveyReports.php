<?php

namespace App\Filament\Pages;

use App\Jobs\GenerateCreditUtilizationReportJob;
use App\Models\CreditReportExport;
use App\Models\Group;
use App\Models\Survey;
use App\Models\SurveyProgress;
use App\Services\ComprehensiveSurveyReportService;
use Filament\Actions\Action;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Dashboard\Concerns\HasFiltersForm;
use Filament\Pages\Page;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

class SurveyReports extends Page
{
    use HasFiltersForm;

    protected static ?string $navigationIcon = 'heroicon-o-chart-pie';

    protected static ?string $navigationLabel = 'Survey Reports';

    protected static ?string $slug = 'survey-reports';

    protected static ?string $navigationGroup = 'Analytics';

    protected static string $view = 'filament.pages.survey-reports';

    /**
     * The user channel Livewire listens on for report-completion broadcasts.
     */
    public ?string $reportChannel = null;

    public function mount(): void
    {
        $this->mountHasFilters();

        $this->reportChannel = auth()->check()
            ? 'reports.'.auth()->id()
            : null;

        if (request()?->hasAny(['survey_id', 'group_id'])) {
            return;
        }

        $this->filters = [
            'survey_id' => null,
            'group_id' => null,
        ];

        $this->getFiltersForm()?->fill($this->filters);
    }

    /**
     * Livewire event listeners for Reverb broadcasts.
     *
     * @return array<string, string>
     */
    protected function getListeners(): array
    {
        $userId = auth()->id();

        return $userId
            ? ['echo-private:reports.'.$userId.',report.completed' => '$refresh']
            : [];
    }

    public function getSubheading(): ?string
    {
        return 'Track survey health, group performance, drop-offs, and question-level responses in one place.';
    }

    protected function filtersForm(Form $form): Form
    {
        return $form
            ->schema([
                Section::make('Survey scope')
                    ->description('Choose a survey to see results across all groups. Add a group to narrow the results.')
                    ->schema([
                        Select::make('survey_id')
                            ->label('Survey')
                            ->options(function (callable $get): array {
                                $groupId = $get('group_id');

                                return Survey::query()
                                    ->when($groupId, fn ($query) => $query->whereHas('groups', fn ($groups) => $groups->where('groups.id', $groupId)))
                                    ->orderBy('title')
                                    ->pluck('title', 'id')
                                    ->all();
                            })
                            ->placeholder('Select a survey')
                            ->searchable()
                            ->preload()
                            ->live(),

                        Select::make('group_id')
                            ->label('Group (optional)')
                            ->options(fn (): array => Group::query()->orderBy('name')->pluck('name', 'id')->all())
                            ->placeholder('All groups')
                            ->searchable()
                            ->preload()
                            ->live()
                            ->afterStateUpdated(function (callable $get, callable $set, $state): void {
                                $surveyId = $get('survey_id');

                                if ($state && $surveyId && ! Survey::query()
                                    ->whereKey($surveyId)
                                    ->whereHas('groups', fn ($query) => $query->where('groups.id', $state))
                                    ->exists()) {
                                    $set('survey_id', null);
                                }
                            }),
                    ])
                    ->columns(2),
            ]);
    }

    public function persistsFiltersInSession(): bool
    {
        return false;
    }

    public function resetFilters(): void
    {
        $this->filters = [
            'survey_id' => null,
            'group_id' => null,
        ];

        $this->getFiltersForm()?->fill($this->filters);
    }

    public function applyFilters(): void
    {
        // Livewire re-renders automatically; stats are computed inline.
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('generate_consolidated_report')
                ->label('Download consolidated report')
                ->icon('heroicon-o-document-chart-bar')
                ->color('info')
                ->visible(fn (): bool => ! filled($this->filters['survey_id'] ?? null))
                ->requiresConfirmation()
                ->modalHeading('Generate consolidated survey workbook')
                ->modalDescription('This queues a single Excel workbook covering ALL active surveys and ALL groups. The M&E team can filter by survey within the workbook. This may take several minutes to generate.')
                ->modalSubmitActionLabel('Queue consolidated workbook')
                ->action(fn () => $this->queueComprehensiveReport(consolidated: true)),

            Action::make('generate_comprehensive_report')
                ->label('Download comprehensive report')
                ->icon('heroicon-o-document-arrow-down')
                ->color('success')
                ->visible(fn (): bool => filled($this->filters['survey_id'] ?? null))
                ->requiresConfirmation()
                ->modalHeading('Generate comprehensive survey workbook')
                ->modalDescription('This queues a private Excel workbook for the selected survey and group scope, with responses, participation outcomes, question drop-offs, and SMS credit utilization.')
                ->modalSubmitActionLabel('Queue workbook')
                ->action(fn () => $this->queueComprehensiveReport()),
        ];
    }

    protected function getHeaderWidgets(): array
    {
        return [];
    }

    /**
     * Compute survey stats inline — no child Livewire components.
     */
    public function getStats(): array
    {
        $surveyId = $this->filters['survey_id'] ?? null;
        $groupId = $this->filters['group_id'] ?? null;

        if (! $surveyId) {
            return [];
        }

        $baseQuery = SurveyProgress::query()
            ->where('survey_id', $surveyId)
            ->when($groupId, fn ($query) => $query->whereHas('member.groups', fn ($groups) => $groups->where('groups.id', $groupId)));

        $total = (clone $baseQuery)->count();
        $completed = (clone $baseQuery)->whereNotNull('completed_at')->where('status', 'COMPLETED')->count();
        $inProgress = (clone $baseQuery)->whereNull('completed_at')->whereIn('status', ['ACTIVE', 'UPDATING_DETAILS', 'PENDING'])->count();
        $cancelled = (clone $baseQuery)->whereNull('completed_at')->where('status', 'CANCELLED')->count();

        $remindersSent = (clone $baseQuery)->where('number_of_reminders', '>', 0)->sum('number_of_reminders');
        $membersSentReminder = (clone $baseQuery)->distinct('member_id')->where('number_of_reminders', '>=', 1)->count('member_id');
        $repeatReminders = (clone $baseQuery)->distinct('member_id')->where('number_of_reminders', '>=', 3)->count('member_id');

        $completionRate = $total > 0 ? round(($completed / $total) * 100, 1) : 0;

        return [
            'total' => $total,
            'completed' => $completed,
            'in_progress' => $inProgress,
            'cancelled' => $cancelled,
            'reminders_sent' => $remindersSent,
            'members_sent_reminder' => $membersSentReminder,
            'repeat_reminders' => $repeatReminders,
            'completion_rate' => $completionRate,
        ];
    }

    /**
     * Group survey summary data for inline table.
     */
    public function getGroupSummary(): array
    {
        $surveyId = $this->filters['survey_id'] ?? null;
        $groupId = $this->filters['group_id'] ?? null;

        if (! $surveyId) {
            return [];
        }

        $progressByGroup = DB::table('group_member')
            ->join('members', 'members.id', '=', 'group_member.member_id')
            ->join('survey_progress', 'survey_progress.member_id', '=', 'members.id')
            ->whereNull('members.deleted_at')
            ->where('survey_progress.survey_id', $surveyId)
            ->when($groupId, fn ($query) => $query->where('group_member.group_id', $groupId))
            ->select('group_member.group_id')
            ->selectRaw('COUNT(*) as total_progresses')
            ->selectRaw("SUM(CASE WHEN survey_progress.completed_at IS NOT NULL AND survey_progress.status = 'COMPLETED' THEN 1 ELSE 0 END) as completed_progresses")
            ->selectRaw("SUM(CASE WHEN survey_progress.completed_at IS NULL AND survey_progress.status IN ('ACTIVE', 'UPDATING_DETAILS', 'PENDING') THEN 1 ELSE 0 END) as ongoing_progresses")
            ->selectRaw("SUM(CASE WHEN survey_progress.status = 'CANCELLED' THEN 1 ELSE 0 END) as cancelled_progresses")
            ->groupBy('group_member.group_id')
            ->get()
            ->keyBy('group_id');

        return Group::query()
            ->when($groupId,
                fn ($query) => $query->whereKey($groupId),
                fn ($query) => $query->where(function ($groups) use ($surveyId, $progressByGroup) {
                    $groups->whereHas('surveys', fn ($surveys) => $surveys->where('surveys.id', $surveyId))
                        ->orWhereIn('groups.id', $progressByGroup->keys()->all());
                }))
            ->withCount('members')
            ->orderBy('name')
            ->get()
            ->map(function (Group $group) use ($progressByGroup): array {
                $progress = $progressByGroup->get($group->id);

                return [
                    'name' => $group->name,
                    'total_members' => $group->members_count,
                    'total_progresses' => (int) ($progress->total_progresses ?? 0),
                    'completed_progresses' => (int) ($progress->completed_progresses ?? 0),
                    'ongoing_progresses' => (int) ($progress->ongoing_progresses ?? 0),
                    'cancelled_progresses' => (int) ($progress->cancelled_progresses ?? 0),
                ];
            })
            ->all();
    }

    /**
     * Dropout data for inline table.
     */
    public function getDropoutData(): array
    {
        $surveyId = $this->filters['survey_id'] ?? null;
        $groupId = $this->filters['group_id'] ?? null;

        if (! $surveyId) {
            return [];
        }

        $rows = SurveyProgress::query()
            ->select('current_question_id', DB::raw('COUNT(*) as total_stoppages'))
            ->whereNull('completed_at')
            ->whereIn('status', ['ACTIVE', 'PENDING', 'UPDATING_DETAILS'])
            ->where('survey_id', $surveyId)
            ->when($groupId, fn ($query) => $query->whereHas('member.groups', fn ($groups) => $groups->where('groups.id', $groupId)))
            ->groupBy('current_question_id')
            ->orderByRaw('COUNT(*) DESC')
            ->limit(20)
            ->get();

        return $rows->map(function ($row) {
            $question = $row->current_question_id
                ? \App\Models\SurveyQuestion::find($row->current_question_id)?->question
                : null;

            return [
                'question' => $question ?: 'Not Started / Error',
                'stoppages' => $row->total_stoppages,
            ];
        })->toArray();
    }

    public function hasFiltersApplied(): bool
    {
        return filled($this->filters['survey_id'] ?? null);
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

        $unit = (($export->filters['report_mode'] ?? null) === 'consolidated'
            || Str::contains($export->file_name, ['consolidated_survey_report', 'all_groups_survey_report']))
            ? 'responses'
            : (Str::contains($export->file_name, 'comprehensive_survey_report') ? 'members' : 'transactions');

        return number_format($export->row_count).' '.$unit;
    }

    private function queueComprehensiveReport(bool $consolidated = false): void
    {
        $userId = auth()->id();
        abort_unless($userId, 403);

        try {
            $scope = app(ComprehensiveSurveyReportService::class)->scope($consolidated ? [] : [
                'survey_ids' => [$this->filters['survey_id'] ?? null],
                'group_ids' => [$this->filters['group_id'] ?? null],
            ]);
            $uuid = (string) Str::uuid();

            if ($consolidated) {
                $fileName = collect([
                    'echo_net_africa',
                    'all_surveys',
                    'consolidated_survey_report',
                    now()->format('Y_m_d_His'),
                ])->implode('_').'.xlsx';
            } elseif ($scope['group'] === null) {
                $fileName = collect([
                    'echo_net_africa',
                    Str::slug($scope['survey']->title, '_'),
                    'all_groups',
                    'survey_report',
                    now()->format('Y_m_d_His'),
                ])->filter()->implode('_').'.xlsx';
            } else {
                $fileName = collect([
                    'echo_net_africa',
                    Str::slug($scope['survey']->title, '_'),
                    Str::slug($scope['group']->name, '_'),
                    'comprehensive_survey_report',
                    now()->format('Y_m_d_His'),
                ])->filter()->implode('_').'.xlsx';
            }

            $reportFilters = $scope['credit_filters'];
            if ($consolidated) {
                $reportFilters['report_mode'] = 'consolidated';
            }

            $report = CreditReportExport::query()->create([
                'uuid' => $uuid,
                'user_id' => $userId,
                'status' => CreditReportExport::STATUS_QUEUED,
                'filters' => $reportFilters,
                'disk' => 'local',
                'file_path' => "private/credit-reports/{$userId}/{$uuid}.xlsx",
                'file_name' => $fileName,
            ]);

            GenerateCreditUtilizationReportJob::dispatch($report->id)->afterCommit();

            Notification::make()
                ->title($consolidated ? 'Consolidated survey report queued' : 'Comprehensive survey report queued')
                ->body('The workbook is being generated in the background. A notification will appear when it is ready.')
                ->success()
                ->send();
        } catch (Throwable $exception) {
            report($exception);

            Notification::make()
                ->title('Report could not be queued')
                ->body('Please verify the selected survey and group, then try again.')
                ->danger()
                ->send();
        }
    }
}
