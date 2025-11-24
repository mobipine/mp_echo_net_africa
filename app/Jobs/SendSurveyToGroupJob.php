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
     * 4. Fetches first question, checks member eligibility (stage + uniqueness)
     * 5. Creates survey_progress records and queues SMS messages
     * 6. Does NOT send SMS directly - creates records for dispatch:sms command
     */

    /**
     * The number of seconds the job's unique lock will be maintained.
     */
    public int $uniqueFor = 300;

    public function __construct(
        public array|string $groupIds, // Can be array of IDs or 'all'
        public Survey $survey,
        public $channel,
        public $automated = false,
        public $startsAt = null,
        public $endsAt = null
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
        $this->processGroupIds($this->groupIds);
    }

    /**
     * Process array of group IDs and send survey to members
     */
    protected function processGroupIds(array $groupIds): void
    {
        // Fetch the first question
        $firstQuestion = getNextQuestion($this->survey->id, null, null);
        if (!$firstQuestion) {
            Log::info("Survey '{$this->survey->title}' has no questions. No SMS sent.");
            return;
        }

        $surveyOrder = $this->survey->order;
        $totalSent = 0;

        foreach ($groupIds as $groupId) {
            $group = Group::find($groupId);
            if (!$group) {
                Log::warning("Group with ID {$groupId} not found. Skipping.");
                continue;
            }

            $members = $group->members()->where('is_active', true)->get();
            $sentCount = 0;
            Log::info("Processing {$members->count()} members in group '{$group->name}'");

            foreach ($members as $member) {
                // --- Stage check and sequencing ---
                if ($surveyOrder === 1) {
                    if ($member->stage !== 'New') {
                        Log::info("Skipping {$member->name}: not in 'New' stage for first survey.");
                        continue;
                    }
                } else {
                    // Fetch previous survey by order
                    $previousSurvey = Survey::where('order', $surveyOrder - 1)->first();
                    if (!$previousSurvey) {
                        Log::warning("Previous survey (order " . ($surveyOrder - 1) . ") not found. Skipping {$member->name}.");
                        continue;
                    }

                    $expectedStage = str_replace(' ', '', ucfirst($previousSurvey->title)) . 'Completed';
                    if ($member->stage !== $expectedStage) {
                        Log::info("Skipping {$member->name}: stage '{$member->stage}' does not match expected '{$expectedStage}'.");
                        continue;
                    }
                }

                // --- Participant uniqueness check with row locking ---
                $progress = SurveyProgress::where('member_id', $member->id)
                    ->where('survey_id', $this->survey->id)
                    ->whereNull('completed_at')
                    ->lockForUpdate() // Prevent race conditions
                    ->first();

                if ($progress && $this->survey->participant_uniqueness) {
                    Log::info("Skipping {$member->name}: participant uniqueness is ON and survey already started.");
                    continue;
                }

                // Cancel any previous incomplete progress if uniqueness is off
                if ($progress) {
                    SurveyProgress::where('member_id', $member->id)
                        ->where('survey_id', $this->survey->id)
                        ->whereNull('completed_at')
                        ->update(['status' => 'CANCELLED']);
                    Log::info("Cancelled previous incomplete progress for {$member->name}.");
                }

                // --- Double-check one more time before creating (defensive) ---
                $doubleCheck = SurveyProgress::where('member_id', $member->id)
                    ->where('survey_id', $this->survey->id)
                    ->whereNull('completed_at')
                    ->whereIn('status', ['ACTIVE', 'UPDATING_DETAILS'])
                    ->exists();

                if ($doubleCheck) {
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
                    Log::info("Updated {$member->name}'s stage to {$memberStage}");
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
                    Log::info("SMS queued for {$member->name}: '{$message}'");
                    $sentCount++;
                } catch (\Exception $e) {
                    Log::error("Failed to create SMSInbox for {$member->name}: " . $e->getMessage());
                }
            }

            Log::info("Survey '{$this->survey->title}' dispatched to {$sentCount} members in group '{$group->name}'.");
            $totalSent += $sentCount;
        }

        Log::info("SendSurveyToGroupJob completed for survey '{$this->survey->title}': {$totalSent} total messages queued.");
    }
}
