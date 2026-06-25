<?php

namespace App\Jobs;

use App\Models\Group;
use App\Models\GroupSurvey;
use App\Models\Member;
use App\Models\Survey;
use App\Models\SurveyQuestion;
use App\Services\SurveyDispatchService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class SendSurveyToGroupJob implements ShouldQueue, ShouldBeUnique
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
        public ?string $dispatchBatchUuid = null
    ) {
        if (!$this->automated && $this->startsAt === null) {
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
        Log::info("Starting SendSurveyToGroupJob for survey '{$this->survey->title}'");

        // Handle "ALL GROUPS" case
        if ($this->groupIds === 'all') {
            $this->processAllGroups($dispatchService);
            return;
        }

        // Handle specific groups case
        $this->processSpecificGroups($dispatchService);
    }

    /**
     * Process ALL groups in the system
     */
    protected function processAllGroups(SurveyDispatchService $dispatchService): void
    {
        Log::info("Processing ALL groups for survey '{$this->survey->title}'");

        // First, create group_survey assignments for all groups
        Group::chunk(300, function ($groups) {
            foreach ($groups as $group) {
                GroupSurvey::firstOrCreate(
                    [
                        'group_id'   => $group->id,
                        'survey_id'  => $this->survey->id,
                        'starts_at'  => $this->startsAt ?? now(),
                    ],
                    [
                        'automated'       => $this->automated,
                        'ends_at'         => $this->endsAt,
                        'channel'         => $this->channel,
                        'was_dispatched'  => !$this->automated,
                    ]
                );
            }
        });

        Log::info("Finished creating group_survey assignments for ALL groups");

        // If automated, stop here - the scheduler will handle dispatch
        if ($this->automated) {
            Log::info("Survey is automated - scheduler will dispatch at scheduled time");
            return;
        }

        // If not automated, process all groups now
        $groupIds = Group::pluck('id')->toArray();
        $this->processGroupIds($dispatchService->normalizeGroupIds($groupIds), $dispatchService);
    }

    /**
     * Process specific groups
     */
    protected function processSpecificGroups(SurveyDispatchService $dispatchService): void
    {
        Log::info("Processing specific groups for survey '{$this->survey->title}'");

        // First, create group_survey assignments for the selected groups
        foreach ($this->groupIds as $groupId) {
            $group = Group::find($groupId);
            if (!$group) {
                Log::warning("Group with ID {$groupId} not found. Skipping group_survey creation.");
                continue;
            }

            GroupSurvey::firstOrCreate(
                [
                    'group_id'   => $groupId,
                    'survey_id'  => $this->survey->id,
                    'starts_at'  => $this->startsAt ?? now(),
                ],
                [
                    'automated'       => $this->automated,
                    'ends_at'         => $this->endsAt,
                    'channel'         => $this->channel,
                    'was_dispatched'  => !$this->automated,
                ]
            );
        }

        Log::info("Finished creating group_survey assignments for " . count($this->groupIds) . " groups");

        // If automated, stop here - the scheduler will handle dispatch
        if ($this->automated) {
            Log::info("Survey is automated - scheduler will dispatch at scheduled time");
            return;
        }

        // If not automated, process the groups now
        $this->processGroupIds($dispatchService->normalizeGroupIds($this->groupIds), $dispatchService);
    }

    /**
     * Process array of group IDs and send survey to members
     */
    protected function processGroupIds(array $groupIds, SurveyDispatchService $dispatchService): void
    {
        // Fetch the first question
        $firstQuestion = getNextQuestion($this->survey->id, null, null);

        // Check if getNextQuestion returned an error array
        if (is_array($firstQuestion)) {
            Log::error("Error getting first question for survey '{$this->survey->title}': " . ($firstQuestion['message'] ?? 'Unknown error'));
            return;
        }

        if (!$firstQuestion || !$firstQuestion instanceof \App\Models\SurveyQuestion) {
            Log::info("Survey '{$this->survey->title}' has no questions. No SMS sent.");
            return;
        }

        $totalQueued = 0;
        $skipped = [];

        if ($this->limit !== null) {
            Log::info("SendSurveyToGroupJob: recipient limit set to {$this->limit}");
        }

        $memberIds = $dispatchService->eligibleMembersQuery($groupIds)->pluck('members.id')->all();
        $members = Member::whereIn('id', $memberIds)->orderBy('id')->get()->keyBy('id');

        foreach ($memberIds as $memberId) {
            if ($this->limit !== null && $totalQueued >= $this->limit) {
                Log::info("SendSurveyToGroupJob: reached limit of {$this->limit} recipients. Stopping.");
                break;
            }

            $member = $members->get($memberId);
            if (!$member) {
                $skipped[] = ['member' => "ID {$memberId}", 'reason' => 'Member record not found'];
                continue;
            }

            $result = $dispatchService->dispatchToMember(
                $member,
                $this->survey,
                $firstQuestion,
                $this->channel,
                $this->automated ? 'automated' : 'manual',
                $this->dispatchBatchUuid
            );

            if ($result['status'] === 'queued') {
                $totalQueued++;
                continue;
            }

            $memberLabel = "{$member->name} (ID: {$member->id}, phone: " . ($member->phone ?: 'none') . ")";
            $skipped[] = [
                'member' => $memberLabel,
                'reason' => $result['reason'] ?? 'Skipped',
            ];
        }

        Log::info("SendSurveyToGroupJob completed for survey '{$this->survey->title}': {$totalQueued} total messages queued." . ($this->limit !== null ? " (limit was {$this->limit})" : ''));

        if (!empty($skipped)) {
            Log::info("Survey '{$this->survey->title}' – members not sent to (" . count($skipped) . "):", [
                'skipped' => $skipped,
            ]);
            foreach ($skipped as $s) {
                Log::info("  - {$s['member']} | Reason: {$s['reason']}");
            }
        }
    }
}
