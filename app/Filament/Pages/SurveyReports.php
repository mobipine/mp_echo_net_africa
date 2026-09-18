<?php

namespace App\Filament\Pages;

use App\Jobs\GenerateCreditUtilizationReportJob;
use App\Models\CreditReportExport;
use App\Models\Group;
use App\Models\Survey;
use App\Models\SurveyProgress;
use App\Services\ComprehensiveSurveyReportService;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Section;
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

    public function getSubheading(): ?string
    {
        return 'Track survey health, group performance, drop-offs, and question-level responses in one place.';
    }

    protected function filtersForm(Form $form): Form
    {
        return $form
            ->schema([
                Section::make('Survey scope')
                    ->description('Select a group first, then pick a survey that was dispatched to it.')
                    ->schema([
                        Select::make('group_id')
                            ->label('Group')
                            ->options(fn (): array => Group::query()->orderBy('name')->pluck('name', 'id')->all())
                            ->placeholder('Select a group')
                            ->searchable()
                            ->preload()
                            ->live()
                            ->afterStateUpdated(fn (callable $set) => $set('survey_id', null)),

                        Select::make('survey_id')
                            ->label('Survey')
                            ->options(function (callable $get): array {
                                $groupId = $get('group_id');

                                if (! $groupId) {
                                    return [];
                                }

                                return Survey::query()
                                    ->whereHas('groups', fn ($query) => $query->where('groups.id', $groupId))
                                    ->orderBy('title')
                                    ->pluck('title', 'id')
                                    ->all();
                            })
                            ->placeholder('Select a group first')
                            ->searchable()
                            ->live()
                            ->disabled(fn (callable $get): bool => blank($get('group_id'))),
                    ])
                    ->columns(2),
            ]);
    }

    public function mount(): void
    {
        $this->mountHasFilters();

        if (request()?->hasAny(['survey_id', 'group_id'])) {
            return;
        }

        $this->filters = [
            'survey_id' => null,
            'group_id' => null,
        ];

        $this->getFiltersForm()?->fill($this->filters);
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
            Action::make('generate_comprehensive_report')
                ->label('Download comprehensive report')
                ->icon('heroicon-o-document-arrow-down')
                ->color('success')
                ->visible(fn (): bool => filled($this->filters['survey_id'] ?? null)
                    && filled($this->filters['group_id'] ?? null))
                ->requiresConfirmation()
                ->modalHeading('Generate comprehensive survey workbook')
                ->modalDescription('This queues a private Excel workbook with all group members, every survey question and response, participation outcomes, question drop-offs, and SMS credit utilization.')
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

        if (! $surveyId || ! $groupId) {
            return [];
        }

        $baseQuery = SurveyProgress::query()
            ->where('survey_id', $surveyId)
            ->whereHas('member.groups', fn ($q) => $q->where('groups.id', $groupId));

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

        if (! $surveyId || ! $groupId) {
            return [];
        }

        $group = Group::find($groupId);
        if (! $group) {
            return [];
        }

        $members = $group->members()->count();

        $totalProgresses = SurveyProgress::query()
            ->where('survey_id', $surveyId)
            ->whereHas('member.groups', fn ($q) => $q->where('groups.id', $groupId))
            ->count();

        $completedProgresses = SurveyProgress::query()
            ->where('survey_id', $surveyId)
            ->whereNotNull('completed_at')
            ->where('status', 'COMPLETED')
            ->whereHas('member.groups', fn ($q) => $q->where('groups.id', $groupId))
            ->count();

        $ongoingProgresses = SurveyProgress::query()
            ->where('survey_id', $surveyId)
            ->whereNull('completed_at')
            ->whereIn('status', ['ACTIVE', 'UPDATING_DETAILS', 'PENDING'])
            ->whereHas('member.groups', fn ($q) => $q->where('groups.id', $groupId))
            ->count();

        $cancelledProgresses = SurveyProgress::query()
            ->where('survey_id', $surveyId)
            ->where('status', 'CANCELLED')
            ->whereHas('member.groups', fn ($q) => $q->where('groups.id', $groupId))
            ->count();

        return [
            'name' => $group->name,
            'total_members' => $members,
            'total_progresses' => $totalProgresses,
            'completed_progresses' => $completedProgresses,
            'ongoing_progresses' => $ongoingProgresses,
            'cancelled_progresses' => $cancelledProgresses,
        ];
    }

    /**
     * Dropout data for inline table.
     */
    public function getDropoutData(): array
    {
        $surveyId = $this->filters['survey_id'] ?? null;
        $groupId = $this->filters['group_id'] ?? null;

        if (! $surveyId || ! $groupId) {
            return [];
        }

        $rows = SurveyProgress::query()
            ->select('current_question_id', DB::raw('COUNT(*) as total_stoppages'))
            ->whereNull('completed_at')
            ->whereIn('status', ['ACTIVE', 'PENDING', 'UPDATING_DETAILS'])
            ->where('survey_id', $surveyId)
            ->whereHas('member.groups', fn ($q) => $q->where('groups.id', $groupId))
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
        return filled($this->filters['survey_id'] ?? null)
            && filled($this->filters['group_id'] ?? null);
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
                ->body('The workbook is being generated in the background. A notification will appear when it is ready.')
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