<?php

namespace App\Filament\Pages;

use App\Enums\ChannelType;
use App\Jobs\SendSurveyToGroupJob;
use App\Jobs\SendSurveyToMembersJob;
use App\Models\Group;
use App\Models\GroupSurvey;
use App\Models\Member;
use App\Models\Survey;
use App\Models\SurveyProgress;
use App\Services\SurveyDispatchService;
use App\Support\SurveyProgressState;
use Carbon\Carbon;
use Filament\Actions\Action;
use Filament\Forms;
use Filament\Forms\Components\Grid;
use Filament\Forms\Components\Select;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Log;

class DispatchSurveyToMultipleGroups extends Page implements Forms\Contracts\HasForms
{
    use Forms\Concerns\InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-chat-bubble-left-ellipsis';

    protected static string $view = 'filament.pages.send-survey';

    protected static ?string $navigationGroup = 'Surveys';

    protected static ?string $title = 'Dispatch Surveys';

    public ?array $data = [];

    public ?array $previewData = null;

    private const PREVIEW_SAMPLE_SIZE = 100;

    public static function shouldRegisterNavigation(): bool
    {
        return auth()->user()?->can('page_SendSMS') ?? false;
    }

    public function mount(): void
    {
        abort_unless(static::shouldRegisterNavigation(), 403);
        $this->form->fill();
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Grid::make(2)->schema([
                    Forms\Components\Group::make()->schema([
                        Select::make('recipient_type')
                            ->label('Recipients')
                            ->options([
                                'groups' => 'Groups',
                                'members' => 'Individual members',
                            ])
                            ->default('groups')
                            ->required()
                            ->reactive()
                            ->native(false)
                            ->afterStateUpdated(fn () => $this->previewData = null),

                        Select::make('group_ids')
                            ->label('Select Groups')
                            ->options(['all' => 'ALL GROUPS'] + Group::all()->pluck('name', 'id')->toArray())
                            ->multiple()
                            ->required(fn ($get) => ($get('recipient_type') ?? 'groups') === 'groups')
                            ->searchable()
                            ->native(false)
                            ->visible(fn ($get) => ($get('recipient_type') ?? 'groups') === 'groups'),

                        Select::make('member_ids')
                            ->label('Select Members')
                            ->multiple()
                            ->searchable()
                            ->native(false)
                            ->required(fn ($get) => ($get('recipient_type') ?? 'groups') === 'members')
                            ->getSearchResultsUsing(fn (string $search): array => Member::query()
                                ->where('is_active', true)
                                ->where(function ($query) use ($search) {
                                    $query->where('name', 'like', "%{$search}%")
                                        ->orWhere('phone', 'like', "%{$search}%")
                                        ->orWhere('national_id', 'like', "%{$search}%");
                                })
                                ->orderBy('name')
                                ->limit(50)
                                ->get()
                                ->mapWithKeys(fn (Member $member) => [$member->id => static::formatMemberOption($member)])
                                ->all())
                            ->getOptionLabelsUsing(fn (array $values): array => Member::query()
                                ->whereIn('id', $values)
                                ->get()
                                ->mapWithKeys(fn (Member $member) => [$member->id => static::formatMemberOption($member)])
                                ->all())
                            ->visible(fn ($get) => ($get('recipient_type') ?? 'groups') === 'members'),

                        Select::make('survey_id')
                            ->label('Select Survey')
                            ->options(Survey::all()->pluck('title', 'id'))
                            ->required()
                            ->searchable()
                            ->native(false),
                    ]),

                    Forms\Components\Group::make()->schema([
                        Forms\Components\Toggle::make('automated')
                            ->label('Automated')
                            ->default(false)
                            ->helperText('Enable if you want to schedule the survey.')
                            ->reactive()
                            ->visible(fn ($get) => ($get('recipient_type') ?? 'groups') === 'groups'),
                        Select::make('channel')
                            ->label('Channel')
                            ->options(ChannelType::options())
                            ->required()
                            ->default(ChannelType::SMS->value)
                            ->native(false),
                        Forms\Components\TextInput::make('limit')
                            ->label('Recipient limit (optional)')
                            ->numeric()
                            ->minValue(1)
                            ->integer()
                            ->placeholder('e.g. 2000')
                            ->helperText('Max number of people to send the survey to across all selected groups. Leave empty for no limit.')
                            ->nullable(),
                        Forms\Components\Toggle::make('restart_open_progress')
                            ->label('Restart stale open progress')
                            ->default(false)
                            ->helperText('Cancel matching open progress before this date and queue a fresh first question.')
                            ->reactive()
                            ->visible(fn ($get) => ! (($get('recipient_type') ?? 'groups') === 'groups' && (bool) $get('automated'))),
                    ]),
                    Forms\Components\Group::make()->schema([
                        Forms\Components\DateTimePicker::make('starts_at')
                            ->label('Start Date')
                            ->native(false)
                            ->required(false)
                            ->hidden(fn ($get) => ! $get('automated')),

                        Forms\Components\DateTimePicker::make('ends_at')
                            ->label('End Date')
                            ->native(false)
                            ->required(false)
                            ->hidden(fn ($get) => ! $get('automated')),

                        Forms\Components\DateTimePicker::make('restart_before')
                            ->label('Restart Progress Created Before')
                            ->native(false)
                            ->seconds(false)
                            ->default(now()->subDay())
                            ->required(fn ($get) => (bool) $get('restart_open_progress'))
                            ->visible(fn ($get) => (bool) $get('restart_open_progress') && ! (($get('recipient_type') ?? 'groups') === 'groups' && (bool) $get('automated'))),
                    ]),
                ]),
            ])
            ->statePath('data');
    }

    protected static function formatMemberOption(Member $member): string
    {
        $phone = $member->phone ?: 'no phone';

        return "{$member->name} ({$phone})";
    }

    public function preview(): void
    {
        set_time_limit(120);
        $this->form->validate();

        $validated = $this->form->getState();
        $survey = Survey::findOrFail($validated['survey_id']);
        $recipientType = $validated['recipient_type'] ?? 'groups';
        $selectedGroups = $validated['group_ids'] ?? [];
        $selectedMembers = $validated['member_ids'] ?? [];
        $isAutomated = $recipientType === 'groups' && (bool) ($validated['automated'] ?? false);
        $channel = $validated['channel'];
        $limit = ! empty($validated['limit']) ? (int) $validated['limit'] : null;
        $restartOpenProgress = (bool) ($validated['restart_open_progress'] ?? false);
        $restartBefore = $restartOpenProgress && ! empty($validated['restart_before'])
            ? Carbon::parse($validated['restart_before'])
            : null;

        $firstQuestion = getNextQuestion($survey->id, null, null);
        if (is_array($firstQuestion) || ! $firstQuestion instanceof \App\Models\SurveyQuestion) {
            Notification::make()
                ->danger()
                ->title('Survey has no questions')
                ->body('This survey has no valid questions or flow. Add questions before dispatching.')
                ->send();
            $this->previewData = null;

            return;
        }

        $dispatchService = app(SurveyDispatchService::class);
        $groupIds = [];
        $groups = collect();
        $groupBreakdown = [];

        if ($recipientType === 'groups') {
            $groupIds = in_array('all', $selectedGroups, true)
                ? Group::pluck('id')->all()
                : $dispatchService->normalizeGroupIds(array_map('intval', $selectedGroups));

            $groups = Group::whereIn('id', $groupIds)->orderBy('name')->get()->keyBy('id');

            foreach ($groups as $group) {
                $activeQuery = $group->members()->where('is_active', true);
                $activeCount = $activeQuery->count();
                $withPhoneCount = (clone $activeQuery)->whereNotNull('phone')->where('phone', '!=', '')->count();
                $noPhoneCount = $activeCount - $withPhoneCount;

                $completedCount = (clone $activeQuery)
                    ->whereNotNull('phone')->where('phone', '!=', '')
                    ->whereHas('surveyProgresses', fn ($q) => $q->where('survey_id', $survey->id)->whereNotNull('completed_at'))
                    ->count();

                $groupBreakdown[$group->id] = [
                    'name' => $group->name,
                    'active' => $activeCount,
                    'with_phone' => $withPhoneCount,
                    'no_phone' => $noPhoneCount,
                    'completed' => $completedCount,
                    'incomplete_skipped' => 0,
                    'restartable' => 0,
                    'to_send' => 0,
                ];
            }

            $memberIds = $dispatchService->eligibleMembersQuery($groupIds)->pluck('members.id')->all();
            $members = Member::with(['groups' => fn ($query) => $query->whereIn('groups.id', $groupIds)])
                ->whereIn('id', $memberIds)
                ->orderBy('id')
                ->get();
        } else {
            $memberIds = $dispatchService->eligibleMemberIdsQuery($selectedMembers)->pluck('members.id')->all();
            $members = Member::with('groups')
                ->whereIn('id', $memberIds)
                ->orderBy('id')
                ->get();
        }

        $completedMemberIds = SurveyProgress::query()
            ->where('survey_id', $survey->id)
            ->whereIn('member_id', $memberIds)
            ->whereNotNull('completed_at')
            ->distinct()
            ->pluck('member_id')
            ->flip();

        $openProgressByMember = collect();
        if ($survey->participant_uniqueness) {
            $openProgressByMember = SurveyProgress::query()
                ->where('survey_id', $survey->id)
                ->whereIn('member_id', $memberIds)
                ->whereNull('completed_at')
                ->whereIn('status', SurveyProgressState::OPEN_STATUSES)
                ->get(['member_id', 'created_at'])
                ->groupBy('member_id');
        }

        $totalActive = count($memberIds);
        $totalEligible = 0;
        $totalSkippedNoPhone = 0;
        $totalSkippedCompleted = 0;
        $totalSkippedIncomplete = 0;
        $totalRestartable = 0;
        $toSendTotal = 0;
        $sampleRows = [];
        $sampleForCredits = collect();
        $selectedGroupOrder = array_flip($groupIds);
        $overlappingMembersCount = 0;

        foreach ($members as $member) {
            $memberGroupIds = $recipientType === 'groups'
                ? $member->groups
                    ->pluck('id')
                    ->filter(fn ($id) => isset($selectedGroupOrder[$id]))
                    ->sortBy(fn ($id) => $selectedGroupOrder[$id])
                    ->values()
                : collect();

            if ($recipientType === 'groups' && $memberGroupIds->count() > 1) {
                $overlappingMembersCount++;
            }

            if (empty($member->phone)) {
                $totalSkippedNoPhone++;

                continue;
            }

            if ($completedMemberIds->has($member->id)) {
                $totalSkippedCompleted++;

                continue;
            }

            $willRestartOpenProgress = false;
            if ($survey->participant_uniqueness) {
                $openRows = $openProgressByMember->get($member->id, collect());

                if ($openRows->isNotEmpty()) {
                    if ($restartOpenProgress) {
                        $restartableRows = $openRows->filter(fn ($row) => ! $restartBefore || Carbon::parse($row->created_at)->lt($restartBefore));
                        $blockingRows = $openRows->reject(fn ($row) => ! $restartBefore || Carbon::parse($row->created_at)->lt($restartBefore));

                        if ($restartableRows->isNotEmpty() && $blockingRows->isEmpty()) {
                            $willRestartOpenProgress = true;
                        } else {
                            $totalSkippedIncomplete++;
                            $assignedGroupId = $memberGroupIds->first();
                            if ($assignedGroupId && isset($groupBreakdown[$assignedGroupId])) {
                                $groupBreakdown[$assignedGroupId]['incomplete_skipped']++;
                            }

                            continue;
                        }
                    } else {
                        $totalSkippedIncomplete++;
                        $assignedGroupId = $memberGroupIds->first();
                        if ($assignedGroupId && isset($groupBreakdown[$assignedGroupId])) {
                            $groupBreakdown[$assignedGroupId]['incomplete_skipped']++;
                        }

                        continue;
                    }
                }
            }

            $totalEligible++;
            if ($limit !== null && $toSendTotal >= $limit) {
                continue;
            }

            $assignedGroupId = $memberGroupIds->first();
            if ($assignedGroupId && isset($groupBreakdown[$assignedGroupId])) {
                $groupBreakdown[$assignedGroupId]['to_send']++;
                if ($willRestartOpenProgress) {
                    $groupBreakdown[$assignedGroupId]['restartable']++;
                }
            }

            if ($willRestartOpenProgress) {
                $totalRestartable++;
            }

            $toSendTotal++;
            if ($sampleForCredits->count() < self::PREVIEW_SAMPLE_SIZE) {
                $sampleForCredits->push($member);
            }

            if (count($sampleRows) < 20) {
                $sampleRows[] = [
                    'member' => $member->name,
                    'phone' => $member->phone,
                    'group' => $recipientType === 'groups'
                        ? ($assignedGroupId ? $groups[$assignedGroupId]->name : 'Unassigned')
                        : ($member->groups->pluck('name')->join(', ') ?: 'Unassigned'),
                ];
            }
        }

        if ($toSendTotal === 0) {
            Notification::make()
                ->warning()
                ->title('No eligible members')
                ->body('No members will receive this survey based on your selection. Check that groups have active members with phone numbers who have not completed this survey.')
                ->send();
            $this->previewData = null;

            return;
        }

        $totalCredits = 0;
        $sampleCount = 0;
        foreach ($sampleForCredits as $member) {
            $message = formartQuestion($firstQuestion, $member, $survey);
            $totalCredits += (int) ceil(strlen($message) / 160);
            $sampleCount++;
        }

        $avgCredits = $sampleCount > 0 ? $totalCredits / $sampleCount : 1;
        $estimatedCredits = (int) ceil($avgCredits * $toSendTotal);

        $sampleMessage = null;
        $sampleMember = $sampleForCredits->first();
        if ($sampleMember) {
            $sampleMessage = formartQuestion($firstQuestion, $sampleMember, $survey);
        }

        $this->previewData = [
            'recipient_type' => $recipientType,
            'recipient_label' => $recipientType === 'groups' ? 'Groups' : 'Individual members',
            'survey_title' => $survey->title,
            'channel' => $channel,
            'is_automated' => $isAutomated,
            'starts_at' => $validated['starts_at'] ?? null,
            'ends_at' => $validated['ends_at'] ?? null,
            'limit' => $limit,
            'group_count' => count($groups),
            'total_active' => $totalActive,
            'total_skipped_no_phone' => $totalSkippedNoPhone,
            'total_skipped_completed' => $totalSkippedCompleted,
            'total_skipped_incomplete' => $totalSkippedIncomplete,
            'total_restartable' => $totalRestartable,
            'total_eligible' => $totalEligible,
            'to_send' => $toSendTotal,
            'estimated_credits' => $estimatedCredits,
            'participant_uniqueness' => $survey->participant_uniqueness,
            'restart_open_progress' => $restartOpenProgress,
            'restart_before' => $restartBefore?->toDateTimeString(),
            'group_breakdown' => array_values($groupBreakdown),
            'overlapping_members_count' => $overlappingMembersCount,
            'sample_rows' => $sampleRows,
            'sample_message' => $sampleMessage,
            'more_count' => max(0, $toSendTotal - 20),
        ];

        Notification::make()
            ->success()
            ->title('Preview ready')
            ->body($recipientType === 'groups'
                ? "Found {$toSendTotal} eligible members across ".count($groups).' group(s).'
                : "Found {$toSendTotal} eligible selected member(s).")
            ->send();
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('preview')
                ->label('Preview')
                ->icon('heroicon-o-eye')
                ->color('gray')
                ->action('preview'),

            Action::make('submit')
                ->label(fn () => (($this->data['recipient_type'] ?? 'groups') === 'groups' && ($this->data['automated'] ?? false)) ? 'Schedule Survey' : 'Send Survey')
                ->icon(fn () => (($this->data['recipient_type'] ?? 'groups') === 'groups' && ($this->data['automated'] ?? false)) ? 'heroicon-o-clock' : 'heroicon-o-paper-airplane')
                ->color('success')
                ->requiresConfirmation()
                ->modalHeading('Confirm Dispatch')
                ->modalDescription(fn () => $this->getSubmitModalDescription())
                ->modalSubmitActionLabel(fn () => (($this->data['recipient_type'] ?? 'groups') === 'groups' && ($this->data['automated'] ?? false)) ? 'Yes, schedule' : 'Yes, send now')
                ->action('submit'),
        ];
    }

    protected function getSubmitModalDescription(): string
    {
        if (! $this->previewData) {
            return 'Run Preview first to see what will happen, then confirm to dispatch.';
        }

        $d = $this->previewData;
        $action = $d['is_automated'] ? 'schedule' : 'send';
        $scope = ($d['recipient_type'] ?? 'groups') === 'groups'
            ? "{$d['to_send']} members across {$d['group_count']} group(s)"
            : "{$d['to_send']} selected member(s)";

        $msg = "You are about to {$action} survey '{$d['survey_title']}' to {$scope}. ";
        $msg .= "Estimated SMS credits: {$d['estimated_credits']}. ";
        if (($d['total_restartable'] ?? 0) > 0) {
            $msg .= "This will restart {$d['total_restartable']} stale open progress record(s). ";
        }
        $msg .= $d['is_automated'] ? 'The survey will be dispatched at the scheduled time.' : 'Messages will be queued shortly.';

        return $msg;
    }

    public function submit(): void
    {
        if (! $this->previewData) {
            Notification::make()
                ->warning()
                ->title('Preview required')
                ->body('Run Preview first so you can confirm the exact recipient and skip counts.')
                ->send();

            return;
        }

        $validated = $this->form->getState();
        $survey = Survey::findOrFail($validated['survey_id']);

        $recipientType = $validated['recipient_type'] ?? 'groups';
        $selectedGroups = $validated['group_ids'] ?? [];
        $selectedMembers = $validated['member_ids'] ?? [];
        $isAutomated = $recipientType === 'groups' && (bool) ($validated['automated'] ?? false);
        $channel = $validated['channel'];
        $limit = ! empty($validated['limit']) ? (int) $validated['limit'] : null;
        $restartOpenProgress = (bool) ($validated['restart_open_progress'] ?? false);
        $restartBefore = $restartOpenProgress && ! empty($validated['restart_before'])
            ? Carbon::parse($validated['restart_before'])->toDateTimeString()
            : null;

        if ($recipientType === 'members') {
            Log::info("{$survey->title} manual dispatch started for individual members.", [
                'selected_members' => count($selectedMembers),
                'limit' => $limit,
                'restart_open_progress' => $restartOpenProgress,
                'restart_before' => $restartBefore,
            ]);

            SendSurveyToMembersJob::dispatch(
                array_map('intval', $selectedMembers),
                $survey,
                $channel,
                $limit,
                null,
                auth()->id(),
                $restartOpenProgress,
                $restartBefore
            );

            Notification::make()
                ->title('Survey dispatch queued')
                ->body('The survey is being sent to the selected member(s). You will get a notification with the final counts.')
                ->success()
                ->send();

            $this->previewData = null;
            $this->form->fill(['recipient_type' => 'groups']);

            return;
        }

        // ---- HANDLE "ALL GROUPS" OPTION ----
        if (in_array('all', $selectedGroups)) {

            Log::info("{$survey->title} → ALL GROUPS selected. Dispatching consolidated job.");

            SendSurveyToGroupJob::dispatch(
                'all', // Special flag for ALL GROUPS
                $survey,
                $channel,
                $isAutomated,
                $validated['starts_at'] ?? null,
                $validated['ends_at'] ?? null,
                $limit,
                null,
                auth()->id(),
                $restartOpenProgress,
                $restartBefore
            );

            Notification::make()
                ->title('Success!')
                ->body(
                    $isAutomated ?
                        'Survey scheduled for ALL groups.' :
                        'Survey dispatch to ALL groups has started in the background.'
                )
                ->success()
                ->send();

            $this->previewData = null;
            $this->form->fill();

            return;
        }

        // ---- HANDLE SPECIFIC GROUPS ----
        if ($isAutomated) {
            // Save scheduling - use firstOrCreate to prevent duplicates
            foreach ($selectedGroups as $groupId) {
                GroupSurvey::firstOrCreate(
                    [
                        'group_id' => $groupId,
                        'survey_id' => $survey->id,
                        'starts_at' => $validated['starts_at'],
                    ],
                    [
                        'automated' => true,
                        'ends_at' => $validated['ends_at'],
                        'channel' => $channel,
                    ]
                );
            }

            Log::info("{$survey->title} scheduled for specific groups.");

            Notification::make()
                ->title('Success!')
                ->body('Your survey has been scheduled.')
                ->success()
                ->send();

            $this->previewData = null;
        } else {
            Log::info("{$survey->title} manual dispatch started for specific groups.");

            // Send to groups
            SendSurveyToGroupJob::dispatch(
                $selectedGroups,
                $survey,
                $channel,
                false,
                null,
                null,
                $limit,
                null,
                auth()->id(),
                $restartOpenProgress,
                $restartBefore
            );

            Log::info("{$survey->title} dispatch job queued for selected groups.");

            Notification::make()
                ->title('Success!')
                ->body('Your survey is being sent now.')
                ->success()
                ->send();
        }

        $this->previewData = null;
        $this->form->fill(['recipient_type' => 'groups']);
    }
}
