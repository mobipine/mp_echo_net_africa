<?php

namespace App\Jobs;

use App\Models\Group;
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
     * The number of seconds the job's unique lock will be maintained.
     */
    public int $uniqueFor = 300;

    public function __construct(
        public array $groupIds,
        public Survey $survey,
        public $channel
    ) {}

    /**
     * Get the unique ID for the job.
     */
    public function uniqueId(): string
    {
        $groupIdsStr = implode('-', $this->groupIds);
        return "send-survey-{$this->survey->id}-groups-{$groupIdsStr}-{$this->channel}";
    }

    public function handle(): void
    {
        Log::info("Starting SendSurveyToGroupJob for survey '{$this->survey->title}'");

        // Fetch the first question
        $firstQuestion = getNextQuestion($this->survey->id, null, null);
        if (!$firstQuestion) {
            Log::info("Survey '{$this->survey->title}' has no questions. No SMS sent.");
            return;
        }

        $surveyOrder = $this->survey->order;

        foreach ($this->groupIds as $groupId) {
            $group = Group::find($groupId);
            if (!$group) {
                Log::warning("Group with ID {$groupId} not found. Skipping.");
                continue;
            }

            $members = $group->members()->where('is_active', true)->get();
            $sentCount = 0;

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
                $length = mb_strlen($message);

                $credits = $length > 0 ? (int) ceil($length / 160) : 0;
                try {
                    SMSInbox::create([
                        'message' => $message,
                        'phone_number' => $member->phone,
                        'member_id' => $member->id,
                        'survey_progress_id' => $newProgress->id,
                        'channel' => $this->channel,
                        'credits_used' => $credits,
                    ]);
                    Log::info("SMS queued for {$member->name}: '{$message}'");
                    $sentCount++;
                } catch (\Exception $e) {
                    Log::error("Failed to create SMSInbox for {$member->name}: " . $e->getMessage());
                }
            }

            Log::info("Survey '{$this->survey->title}' dispatched to {$sentCount} members in group '{$group->name}'.");
        }

        Log::info("SendSurveyToGroupJob completed for survey '{$this->survey->title}'.");
    }
}
