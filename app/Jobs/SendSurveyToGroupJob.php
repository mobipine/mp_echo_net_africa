<?php

namespace App\Jobs;

use App\Filament\Resources\SMSResource;
use App\Models\Group;
use App\Models\GroupSurvey;
use App\Models\Member;
use App\Models\Survey;
use App\Models\User;
use App\Services\SurveyDispatchService;
use Carbon\Carbon;
use Filament\Notifications\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class SendSurveyToGroupJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * SURVEY DISPATCH JOB - OVERVIEW
     *
     * 1. Triggered from Filament UI (manual survey dispatch)
     * 2. Handles 'all' groups OR specific group IDs
     * 3. For ALL groups: Creates group_survey assignments, dispatches if not automated
     * 4. Fetches first question, sends to all active members (participant uniqueness optional)
     * 5. Creates survey_progress records and queues SMS messages
     * 6. Does NOT send SMS directly - creates records for dispatch:sms command
     *
     * IMPORTANT: uniqueFor must be >= timeout so that while this job runs, no second job
     * with the same survey+groups can run (otherwise duplicate SurveyProgress and SMSInbox
     * records are created and the recipient limit can be exceeded).
     */

    /**
     * The number of seconds the job's unique lock will be maintained.
     * MUST be >= $timeout so a second job cannot start while this one is still running
     * (otherwise duplicate SurveyProgress and SMSInbox records can be created).
     */
    public int $uniqueFor = 7200;

    /**
     * The number of seconds the job can run before timing out.
     * Survey dispatch to large groups can take several minutes.
     */
    public int $timeout = 3600;

    public function __construct(
        public array|string $groupIds, // Can be array of IDs or 'all'
        public Survey $survey,
        public $channel,
        public $automated = false,
        public $startsAt = null,
        public $endsAt = null,
        public ?int $limit = null, // Optional max recipients across all groups (e.g. 2000 for monitoring)
        public ?string $dispatchBatchUuid = null,
        public ?int $userId = null,
        public bool $restartOpenProgress = false,
        public ?string $restartBefore = null
    ) {
        if (! $this->automated && $this->startsAt === null) {
            $this->startsAt = now()->startOfSecond()->toDateTimeString();
        }

        $this->dispatchBatchUuid ??= Str::uuid()->toString();
    }

    /**
     * Get the unique ID for the job.
     */
    public function uniqueId(): string
    {
        if ($this->groupIds === 'all') {
            $startsAtStr = $this->startsAt ? date('Y-m-d-H-i', strtotime($this->startsAt)) : 'now';

            return "send-survey-all-groups-{$this->survey->id}-{$startsAtStr}-{$this->channel}";
        }

        $normalizedGroupIds = app(SurveyDispatchService::class)->normalizeGroupIds($this->groupIds);
        $groupIdsStr = implode('-', $normalizedGroupIds);

        return "send-survey-{$this->survey->id}-groups-{$groupIdsStr}-{$this->channel}";
    }

    public function handle(SurveyDispatchService $dispatchService): void
    {
        Log::info("Starting SendSurveyToGroupJob for survey '{$this->survey->title}'", [
            'survey_id' => $this->survey->id,
            'group_ids' => $this->groupIds,
            'limit' => $this->limit,
            'restart_open_progress' => $this->restartOpenProgress,
            'restart_before' => $this->restartBefore,
            'dispatch_batch_uuid' => $this->dispatchBatchUuid,
        ]);

        // Handle "ALL GROUPS" case
        if ($this->groupIds === 'all') {
            $summary = $this->processAllGroups($dispatchService);
            $this->notifyCompletion($summary);

            return;
        }

        // Handle specific groups case
        $summary = $this->processSpecificGroups($dispatchService);
        $this->notifyCompletion($summary);
    }

    /**
     * Process ALL groups in the system
     */
    protected function processAllGroups(SurveyDispatchService $dispatchService): array
    {
        Log::info("Processing ALL groups for survey '{$this->survey->title}'");

        $assignmentIds = [];

        // First, create group_survey assignments for all groups
        Group::chunk(300, function ($groups) use (&$assignmentIds) {
            foreach ($groups as $group) {
                $assignment = GroupSurvey::firstOrCreate(
                    [
                        'group_id' => $group->id,
                        'survey_id' => $this->survey->id,
                        'starts_at' => $this->startsAt ?? now(),
                    ],
                    [
                        'automated' => $this->automated,
                        'ends_at' => $this->endsAt,
                        'channel' => $this->channel,
                        'was_dispatched' => ! $this->automated,
                    ]
                );

                $assignmentIds[] = $assignment->id;
            }
        });

        Log::info('Finished creating group_survey assignments for ALL groups');

        // If automated, stop here - the scheduler will handle dispatch
        if ($this->automated) {
            Log::info('Survey is automated - scheduler will dispatch at scheduled time');

            return $this->summary(0, 0, [], 0, 0);
        }

        // If not automated, process all groups now
        $groupIds = Group::pluck('id')->toArray();
        $summary = $this->processGroupIds($dispatchService->normalizeGroupIds($groupIds), $dispatchService);
        $this->storeGroupSurveySummary($assignmentIds, $summary);

        return $summary;
    }

    /**
     * Process specific groups
     */
    protected function processSpecificGroups(SurveyDispatchService $dispatchService): array
    {
        Log::info("Processing specific groups for survey '{$this->survey->title}'");

        $assignmentIds = [];

        // First, create group_survey assignments for the selected groups
        foreach ($this->groupIds as $groupId) {
            $group = Group::find($groupId);
            if (! $group) {
                Log::warning("Group with ID {$groupId} not found. Skipping group_survey creation.");

                continue;
            }

            $assignment = GroupSurvey::firstOrCreate(
                [
                    'group_id' => $groupId,
                    'survey_id' => $this->survey->id,
                    'starts_at' => $this->startsAt ?? now(),
                ],
                [
                    'automated' => $this->automated,
                    'ends_at' => $this->endsAt,
                    'channel' => $this->channel,
                    'was_dispatched' => ! $this->automated,
                ]
            );

            $assignmentIds[] = $assignment->id;
        }

        Log::info('Finished creating group_survey assignments for '.count($this->groupIds).' groups');

        // If automated, stop here - the scheduler will handle dispatch
        if ($this->automated) {
            Log::info('Survey is automated - scheduler will dispatch at scheduled time');

            return $this->summary(0, 0, [], 0, 0);
        }

        // If not automated, process the groups now
        $summary = $this->processGroupIds($dispatchService->normalizeGroupIds($this->groupIds), $dispatchService);
        $this->storeGroupSurveySummary($assignmentIds, $summary);

        return $summary;
    }

    /**
     * Process array of group IDs and send survey to members
     */
    protected function processGroupIds(array $groupIds, SurveyDispatchService $dispatchService): array
    {
        // Fetch the first question
        $firstQuestion = getNextQuestion($this->survey->id, null, null);

        // Check if getNextQuestion returned an error array
        if (is_array($firstQuestion)) {
            Log::error("Error getting first question for survey '{$this->survey->title}': ".($firstQuestion['message'] ?? 'Unknown error'));

            return $this->summary(0, 0, ['Survey has no valid first question' => 1], 0, 0);
        }

        if (! $firstQuestion || ! $firstQuestion instanceof \App\Models\SurveyQuestion) {
            Log::info("Survey '{$this->survey->title}' has no questions. No SMS sent.");

            return $this->summary(0, 0, ['Survey has no questions' => 1], 0, 0);
        }

        $totalQueued = 0;
        $skippedReasons = [];
        $cancelledProgress = 0;
        $restartBefore = $this->restartBefore ? Carbon::parse($this->restartBefore) : null;

        if ($this->limit !== null) {
            Log::info("SendSurveyToGroupJob: recipient limit set to {$this->limit}");
        }

        $memberIds = $dispatchService->eligibleMembersQuery($groupIds)->pluck('members.id')->all();
        $members = Member::whereIn('id', $memberIds)->orderBy('id')->get()->keyBy('id');

        foreach ($memberIds as $memberId) {
            if ($this->limit !== null && $totalQueued >= $this->limit) {
                Log::info("SendSurveyToGroupJob: reached limit of {$this->limit} recipients. Stopping.");
                $skippedReasons['Recipient limit reached'] = ($skippedReasons['Recipient limit reached'] ?? 0) + 1;
                break;
            }

            $member = $members->get($memberId);
            if (! $member) {
                $skippedReasons['Member record not found'] = ($skippedReasons['Member record not found'] ?? 0) + 1;

                continue;
            }

            $result = $dispatchService->dispatchToMember(
                $member,
                $this->survey,
                $firstQuestion,
                $this->channel,
                $this->automated ? 'automated' : 'manual',
                $this->dispatchBatchUuid,
                $this->restartOpenProgress,
                $restartBefore
            );

            if ($result['status'] === 'queued') {
                $totalQueued++;
                $cancelledProgress += (int) ($result['cancelled_progress_count'] ?? 0);

                continue;
            }

            $reason = $result['reason'] ?? 'Skipped';
            $skippedReasons[$reason] = ($skippedReasons[$reason] ?? 0) + 1;
        }

        $summary = $this->summary($totalQueued, array_sum($skippedReasons), $skippedReasons, $cancelledProgress, count($memberIds));

        Log::info("SendSurveyToGroupJob completed for survey '{$this->survey->title}'", $summary);

        return $summary;
    }

    private function summary(int $queued, int $skipped, array $skipReasons, int $cancelledProgress, int $eligibleMembers): array
    {
        return [
            'queued' => $queued,
            'skipped' => $skipped,
            'skip_reasons' => $skipReasons,
            'cancelled_stale_progress' => $cancelledProgress,
            'eligible_members' => $eligibleMembers,
            'dispatch_batch_uuid' => $this->dispatchBatchUuid,
        ];
    }

    private function storeGroupSurveySummary(array $assignmentIds, array $summary): void
    {
        if (empty($assignmentIds) || ! Schema::hasColumn('group_survey', 'queued_count')) {
            return;
        }

        GroupSurvey::whereIn('id', $assignmentIds)->update([
            'dispatch_batch_uuid' => $this->dispatchBatchUuid,
            'queued_count' => $summary['queued'],
            'skipped_count' => $summary['skipped'],
            'dispatch_summary' => json_encode($summary),
            'dispatched_at' => now(),
            'was_dispatched' => true,
        ]);
    }

    private function notifyCompletion(array $summary): void
    {
        if (! $this->userId || ! ($user = User::find($this->userId))) {
            return;
        }

        $body = "Queued {$summary['queued']} SMS message(s); skipped {$summary['skipped']} member(s).";
        if (($summary['cancelled_stale_progress'] ?? 0) > 0) {
            $body .= " Restarted {$summary['cancelled_stale_progress']} stale open progress record(s).";
        }

        Notification::make()
            ->title('Group survey dispatch complete')
            ->body($body)
            ->success()
            ->actions([
                Action::make('view_sms')
                    ->label('View SMS records')
                    ->url(SMSResource::getUrl('index'), shouldOpenInNewTab: true)
                    ->button(),
            ])
            ->sendToDatabase($user);
    }
}
