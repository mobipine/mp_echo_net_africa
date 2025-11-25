<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\GroupSurvey;
use App\Models\Group;
use App\Models\Member;
use App\Models\Survey;
use App\Models\SurveyProgress;
use App\Models\SMSInbox;
use Illuminate\Support\Facades\Log;

class DispatchDueSurveysCommand extends Command
{
    /**
     * AUTOMATED SURVEY DISPATCH COMMAND - OVERVIEW
     *
     * 1. Runs every 5 seconds, finds scheduled surveys due to start now
     * 2. Queries group_survey WHERE automated=true, was_dispatched=false, starts_at<=now
     * 3. Marks was_dispatched=true immediately to prevent duplicates
     * 4. Validates member eligibility: stage (New or PreviousSurveyCompleted) + uniqueness
     * 5. Creates survey_progress, updates member stage, queues first question SMS
     * 6. Works with SendSurveyToGroupJob (this=automated, job=manual UI)
     */

    protected $signature = 'surveys:due-dispatch';
    protected $description = 'Dispatch automated surveys to eligible members based on order and stage';

    public function handle()
    {
        // Check if survey messages are enabled
        if (!config('survey_settings.messages_enabled', true)) {
            Log::info('Survey messages are disabled via config. Skipping automated survey dispatch.');
            return;
        }

        // Acquire lock to prevent concurrent executions
        $lock = \Illuminate\Support\Facades\Cache::lock('surveys-due-dispatch-command', 60);

        if (!$lock->get()) {
            Log::info('DispatchDueSurveysCommand already running. Skipping...');
            return;
        }

        try {
            $dueAssignments = GroupSurvey::with(['survey', 'group'])
                ->where('automated', true)
                ->where('was_dispatched', false)
                ->where('starts_at', '<=', now())
                ->lockForUpdate() // Lock rows to prevent concurrent access
                ->get();

            if ($dueAssignments->isEmpty()) {
                Log::info('No automated survey assignments due.');
                return;
            }

            $totalSent = 0;

            foreach ($dueAssignments as $assignment) {
                $group = $assignment->group;
                $survey = $assignment->survey;

                if (!$group || !$survey) {
                    Log::warning("Group or survey not found for assignment ID {$assignment->id}");
                    continue;
                }

                Log::info("Processing survey '{$survey->title}' for group '{$group->name}'");

                // CRITICAL: Mark as dispatched BEFORE processing to prevent duplicates
                $assignment->update(['was_dispatched' => true]);

                $surveyOrder = $survey->order;
                $sentCount = 0;

                // Get first question
                $firstQuestion = getNextQuestion($survey->id, null, null);

                // Check if getNextQuestion returned an error array
                if (is_array($firstQuestion)) {
                    Log::error("Error getting first question for survey '{$survey->title}': " . ($firstQuestion['message'] ?? 'Unknown error'));
                    continue;
                }

                if (!$firstQuestion || !$firstQuestion instanceof \App\Models\SurveyQuestion) {
                    Log::warning("Survey '{$survey->title}' has no questions. Skipping.");
                    continue;
                }

                // Process members in chunks to avoid memory issues
                $group->members()->where('is_active', true)->chunk(500, function ($members) use ($survey, $surveyOrder, $firstQuestion, $assignment, &$sentCount) {
                    foreach ($members as $member) {
                        // Check survey order and member stage
                        if ($surveyOrder === 1) {
                            if ($member->stage !== 'New') {
                                Log::info("Skipping {$member->name}: not in 'New' stage for first survey");
                                continue;
                            }
                        } else {
                            // Check previous survey completion
                            $previousSurvey = Survey::where('order', $surveyOrder - 1)->first();
                            if (!$previousSurvey) {
                                Log::warning("Previous survey (order " . ($surveyOrder - 1) . ") not found");
                                continue;
                            }

                            $expectedStage = str_replace(' ', '', ucfirst($previousSurvey->title)) . 'Completed';
                            if ($member->stage !== $expectedStage) {
                                Log::info("Skipping {$member->name}: stage '{$member->stage}' != '{$expectedStage}'");
                                continue;
                            }
                        }

                        // Check existing progress
                        $existingProgress = SurveyProgress::where('member_id', $member->id)
                            ->where('survey_id', $survey->id)
                            ->whereNull('completed_at')
                            ->first();

                        if ($existingProgress && $survey->participant_uniqueness) {
                            Log::info("Skipping {$member->name}: participant uniqueness is ON and survey already started");
                            continue;
                        }

                        // Cancel previous incomplete progress if uniqueness is off
                        if ($existingProgress) {
                            SurveyProgress::where('member_id', $member->id)
                                ->where('survey_id', $survey->id)
                                ->whereNull('completed_at')
                                ->update(['status' => 'CANCELLED']);
                            Log::info("Cancelled previous incomplete progress for {$member->name}");
                        }

                        // Create new survey progress
                        $newProgress = SurveyProgress::create([
                            'survey_id' => $survey->id,
                            'member_id' => $member->id,
                            'current_question_id' => $firstQuestion->id,
                            'last_dispatched_at' => now(),
                            'has_responded' => false,
                            'source' => 'automated',
                            'channel' => $assignment->channel ?? 'sms',
                        ]);

                        // Update member stage
                        $memberStage = str_replace(' ', '', ucfirst($survey->title)) . 'InProgress';
                        if ($member->stage !== $memberStage) {
                            $member->update(['stage' => $memberStage]);
                            Log::info("Updated {$member->name}'s stage to {$memberStage}");
                        }

                        // Create SMS inbox record (actual sending handled by dispatch:sms command)
                        $message = formartQuestion($firstQuestion, $member, $survey);
                        try {
                            SMSInbox::create([
                                'message' => $message,
                                'phone_number' => $member->phone,
                                'member_id' => $member->id,
                                'survey_progress_id' => $newProgress->id,
                                'channel' => $assignment->channel ?? 'sms',
                            ]);
                            $sentCount++;
                        } catch (\Exception $e) {
                            Log::error("Failed to create SMSInbox for {$member->name}: {$e->getMessage()}");
                        }
                    }
                });

                Log::info("Survey '{$survey->title}' dispatched to {$sentCount} members in group '{$group->name}'");
                $totalSent += $sentCount;
            }

            Log::info("DispatchDueSurveysCommand complete: {$totalSent} messages queued");
        } finally {
            $lock->release();
        }
    }
}
