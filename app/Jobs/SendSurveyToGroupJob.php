<?php

namespace App\Jobs;

use App\Models\Group;
use App\Models\GroupSurvey;
use App\Models\SMSInbox;
use App\Models\Survey;
use App\Models\SurveyProgress;
use App\Models\SurveyQuestion;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

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
        public ?int $limit = null // Optional max recipients across all groups (e.g. 2000 for monitoring)
    ) {}

    /**
     * Get the unique ID for the job.
     */
    public function uniqueId(): string
    {
        if ($this->groupIds === 'all') {
            $startsAtStr = $this->startsAt ? date('Y-m-d-H-i', strtotime($this->startsAt)) : 'now';
            return "send-survey-all-groups-{$this->survey->id}-{$startsAtStr}-{$this->channel}";
        }

        $groupIdsStr = implode('-', $this->groupIds);
        return "send-survey-{$this->survey->id}-groups-{$groupIdsStr}-{$this->channel}";
    }

    public function handle(): void
    {
        Log::info("Starting SendSurveyToGroupJob for survey '{$this->survey->title}'");

        // Handle "ALL GROUPS" case
        if ($this->groupIds === 'all') {
            $this->processAllGroups();
            return;
        }

        // Handle specific groups case
        $this->processSpecificGroups();
    }

    /**
     * Process ALL groups in the system
     */
    protected function processAllGroups(): void
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
        $this->processGroupIds($groupIds);
    }

    /**
     * Process specific groups
     */
    protected function processSpecificGroups(): void
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
        $this->processGroupIds($this->groupIds);
    }

    /**
     * Process array of group IDs and send survey to members
     */
    protected function processGroupIds(array $groupIds): void
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

        $totalSent = 0;
        $skipped = [];

        if ($this->limit !== null) {
            Log::info("SendSurveyToGroupJob: recipient limit set to {$this->limit}");
        }

        foreach ($groupIds as $groupId) {
            if ($this->limit !== null && $totalSent >= $this->limit) {
                Log::info("SendSurveyToGroupJob: reached limit of {$this->limit} recipients. Stopping.");
                break;
            }
            $group = Group::find($groupId);
            if (!$group) {
                Log::warning("Group with ID {$groupId} not found. Skipping.");
                continue;
            }

            $members = $group->members()->where('is_active', true)->get();
            $sentCount = 0;
            Log::info("Group '{$group->name}' (ID: {$group->id}): processing {$members->count()} active members");

            foreach ($members as $member) {
                $memberLabel = "{$member->name} (ID: {$member->id}, phone: " . ($member->phone ?: 'none') . ")";

                // Check if member has a phone number
                if (empty($member->phone)) {
                    $skipped[] = ['member' => $memberLabel, 'group' => $group->name, 'reason' => 'No phone number'];
                    Log::warning("Skipping {$member->name} (ID: {$member->id}): no phone number");
                    continue;
                }

                // --- Participant uniqueness check with row locking ---
                $progress = SurveyProgress::where('member_id', $member->id)
                    ->where('survey_id', $this->survey->id)
                    ->whereNull('completed_at')
                    ->lockForUpdate() // Prevent race conditions
                    ->first();

                if ($progress && $this->survey->participant_uniqueness) {
                    $skipped[] = ['member' => $memberLabel, 'group' => $group->name, 'reason' => 'Participant uniqueness is ON and survey already started'];
                    Log::info("Skipping {$member->name}: participant uniqueness is ON and survey already started.");
                    continue;
                }

                // Cancel any previous incomplete progress if uniqueness is off
                if ($progress) {
                    SurveyProgress::where('member_id', $member->id)
                        ->where('survey_id', $this->survey->id)
                        ->whereNull('completed_at')
                        ->update(['status' => 'CANCELLED']);
                }

                // --- Double-check one more time before creating (defensive) ---
                $doubleCheck = SurveyProgress::where('member_id', $member->id)
                    ->where('survey_id', $this->survey->id)
                    ->whereNull('completed_at')
                    ->whereIn('status', ['ACTIVE', 'UPDATING_DETAILS'])
                    ->exists();

                if ($doubleCheck) {
                    $skipped[] = ['member' => $memberLabel, 'group' => $group->name, 'reason' => 'Survey progress already exists (ACTIVE/UPDATING_DETAILS)'];
                    Log::info("Double-check: SurveyProgress already exists for member {$member->id}. Skipping.");
                    continue;
                }

                // --- Create new progress record ---
                $newProgress = SurveyProgress::create([
                    'survey_id' => $this->survey->id,
                    'member_id' => $member->id,
                    'current_question_id' => $firstQuestion->id,
                    'last_dispatched_at' => now(),
                    'has_responded' => false,
                    'source' => 'manual',
                ]);

                // --- Update member stage to SurveyInProgress ---
                $memberStage = str_replace(' ', '', ucfirst($this->survey->title)) . 'InProgress';
                if ($member->stage !== $memberStage) {
                    $member->update(['stage' => $memberStage]);
                }

                // --- Format and create SMSInbox record ---
                $message = formartQuestion($firstQuestion, $member, $this->survey);
                try {
                    SMSInbox::create([
                        'message' => $message,
                        'phone_number' => $member->phone,
                        'member_id' => $member->id,
                        'survey_progress_id' => $newProgress->id,
                        'channel' => $this->channel,
                    ]);
                    $sentCount++;

                    // Enforce optional recipient limit across all groups
                    if ($this->limit !== null && ($totalSent + $sentCount) >= $this->limit) {
                        $totalSent += $sentCount;
                        Log::info("SendSurveyToGroupJob: reached limit of {$this->limit} recipients. Stopping dispatch. Sent to {$totalSent} members.");
                        break 2;
                    }
                } catch (\Exception $e) {
                    $skipped[] = ['member' => $memberLabel, 'group' => $group->name, 'reason' => 'Failed to create SMS: ' . $e->getMessage()];
                    Log::error("Failed to create SMSInbox for {$member->name}: " . $e->getMessage());
                }
            }

            Log::info("Survey '{$this->survey->title}' dispatched to {$sentCount} members in group '{$group->name}'.");
            $totalSent += $sentCount;
        }

        Log::info("SendSurveyToGroupJob completed for survey '{$this->survey->title}': {$totalSent} total messages queued." . ($this->limit !== null ? " (limit was {$this->limit})" : ''));

        if (!empty($skipped)) {
            Log::info("Survey '{$this->survey->title}' – members not sent to (" . count($skipped) . "):", [
                'skipped' => $skipped,
            ]);
            foreach ($skipped as $s) {
                Log::info("  - {$s['member']} | Group: {$s['group']} | Reason: {$s['reason']}");
            }
        }
    }
}
