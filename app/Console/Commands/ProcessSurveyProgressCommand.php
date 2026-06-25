<?php

namespace App\Console\Commands;

use App\Models\GroupSurvey;
use App\Models\SMSInbox;
use App\Models\SurveyProgress;
use App\Models\SurveyQuestion;
use App\Models\SurveyResponse;
use App\Services\SurveyReminderService;
// use App\Services\UjumbeSMS;
use Illuminate\Console\Command;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ProcessSurveyProgressCommand extends Command
{
    /**
     * SURVEY PROGRESS PROCESSOR - THE CORE ENGINE
     *
     * 1. Runs every 5 seconds, finds active survey_progress records (not completed)
     * 2. Checks if question_interval time has passed since last_dispatched_at
     * 3. IF member has_responded=true: Gets next question via getNextQuestion(), queues it, updates progress
     * 4. IF member has_responded=false: Checks reminder interval, sends reminder (max 3)
     * 5. When survey complete: Marks progress completed, updates member stage to '{Survey}Completed'
     * 6. This is THE CORE - handles question flow, branching, reminders, completion
     */

    protected $signature = 'process:surveys-progress';
    protected $description = 'Sends next survey questions or reminders based on participant progress.';

    public function handle()
    {
        // Check if survey messages are enabled
        if (!config('survey_settings.messages_enabled', true)) {
            Log::info('Survey messages are disabled via config. Skipping survey progress processing.');
            return;
        }

        // Acquire lock to prevent concurrent executions
        $lock = \Illuminate\Support\Facades\Cache::lock('process-surveys-progress-command', 60);

        if (!$lock->get()) {
            Log::info('ProcessSurveyProgressCommand already running. Skipping...');
            return;
        }

        try {
            $progressRecords = SurveyProgress::with(['survey', 'currentQuestion', 'member'])
                ->whereNull('completed_at')
                ->whereIn('status', ['ACTIVE', 'UPDATING_DETAILS'])
                ->get();

            if ($progressRecords->isEmpty()) {
                return;
            }

            foreach ($progressRecords as $progress) {
                $member = $progress->member;
                $survey = $progress->survey;
                $currentQuestion = $progress->currentQuestion;

                if (!$currentQuestion) {
                    Log::warning("No current question found for survey progress ID: {$progress->id}.");
                    continue;
                }

                // Get the pivot data for the group-survey relationship
                // $groupSurvey = DB::table('group_survey')
                //     ->where('survey_id', $survey->id)
                //     ->first();
                $source = $progress->source ?? 'manual'; // Default to 'MANUAL' if source is null

                if ($source != "shortcode") {
                    $groupSurvey = GroupSurvey::where('survey_id', $survey->id)->first();

                    // If no pivot data exists, skip this record to prevent errors
                    if (!$groupSurvey) {
                        Log::warning("Group-survey relationship not found for survey ID: {$survey->id}.");
                        continue;
                    }

                    $interval = $currentQuestion->question_interval ?? 1; // Use the pivot value, or default to 3 days
                    $unit = $currentQuestion->question_interval_unit ?? 'seconds'; // Use the pivot value, or default to 'days'

                    // $endDate = GroupSurvey::where('group_id', $member->group_id)
                    //         ->where('survey_id',$survey->id)
                    //         ->value('ends_at');

                    // dd($interval, $unit, "interval and unit", $currentQuestion, "current question");


                } else {
                    //for shortcode surveys, use 1 minute interval
                    //TODO: Josphat: Create a global config for on the survey resource
                    $interval = $currentQuestion->question_interval ?? 1; // Use the pivot value, or default to 3 days
                    $unit = $currentQuestion->question_interval_unit ?? 'seconds'; // Use the pivot value, or default to 'days'

                    // $endDate = null;
                }

                // Log::info("The survey ends on $endDate");
                //check if endDate has passed. If it has, continue to the next record

                // Decide from current state whether this record might need action. Most
                // active records are idle (waiting on a reply, no reminder yet due), so we
                // avoid taking a lock for them.
                $hasResponded = SurveyProgress::where('member_id', $member->id)
                    ->where('survey_id', $survey->id)
                    ->where('has_responded', true)
                    ->whereIn('status', ['ACTIVE', 'UPDATING_DETAILS'])
                    ->exists();

                $lastDispatched = Carbon::parse($progress->last_dispatched_at);
                $confirmationDue = $lastDispatched->copy()
                    ->add($survey->continue_confirmation_interval, $survey->continue_confirmation_interval_unit);
                $isconfirmationDue = $confirmationDue->lessThanOrEqualTo(now());

                if (!$hasResponded && !$isconfirmationDue) {
                    continue; // nothing to do — don't bother locking
                }

                // Serialize against the inbound webhook, which advances surveys
                // synchronously and takes this same per-phone lock. Non-blocking: if a
                // webhook is currently handling this member, skip and retry next cycle. This
                // guarantees the webhook and the poller can never act on one member at the
                // same time — no duplicate sends, no duplicate DB writes.
                $memberLock = \Illuminate\Support\Facades\Cache::lock('survey-inbound:' . normalizePhoneNumber($member->phone), 20);
                if (!$memberLock->get()) {
                    continue;
                }

                try {
                    // Re-read fresh state under the lock — the bulk load above may be stale
                    // (a webhook may have advanced this member in the meantime).
                    $progress = SurveyProgress::with('currentQuestion')
                        ->where('member_id', $member->id)
                        ->where('survey_id', $survey->id)
                        ->whereIn('status', ['ACTIVE', 'UPDATING_DETAILS'])
                        ->whereNull('completed_at')
                        ->latest()
                        ->first();

                    if (!$progress) {
                        continue;
                    }

                    $currentQuestion = $progress->currentQuestion;
                    if (!$currentQuestion) {
                        continue;
                    }

                    $channel = $progress->channel ?? 'sms';

                    if ($progress->has_responded) {
                        // Get the latest response for the current question.
                        $latestResponse = SurveyResponse::where('session_id', $progress->id)
                            ->where('survey_id', $survey->id)
                            ->where('question_id', $currentQuestion->id)
                            ->latest()
                            ->first();
                        $response = $latestResponse ? $latestResponse->survey_response : null;

                        // Safety-net guard: only step in if the reply is old enough that the
                        // webhook clearly failed to handle it (>30s). Fresh replies belong to
                        // the webhook, which advances them synchronously.
                        if ($latestResponse && $latestResponse->created_at->greaterThan(now()->subSeconds(30))) {
                            continue;
                        }

                        Log::info("Safety-net: advancing survey for {$member->phone} (webhook missed this reply)");

                        $nextQuestion = getNextQuestion($survey->id, $response, $currentQuestion->id);

                        // Check if nextQuestion is an error/violation array or a valid question object
                        if (is_array($nextQuestion)) {
                            Log::error("Error getting next question for member {$member->id}: " . ($nextQuestion['message'] ?? 'Unknown error'));

                            if (($nextQuestion['status'] ?? null) === 'violation') {
                                // Send violation message and require a new response.
                                $this->sendSMS($member->phone, $nextQuestion['message'], $channel, false, $member, $progress->id);
                                $progress->update([
                                    'last_dispatched_at' => now(),
                                    'has_responded' => false,
                                ]);
                                Log::info("Sent violation message to member {$member->id}: {$nextQuestion['message']}");
                            } else {
                                Log::error("Survey flow error for member {$member->id}, survey {$survey->id}. Marking as completed.");
                                $progress->update([
                                    'completed_at' => now(),
                                    'status' => 'error',
                                ]);
                            }
                            continue;
                        }

                        if ($nextQuestion instanceof \App\Models\SurveyQuestion) {
                            $message = formartQuestion($nextQuestion, $member, $survey);
                            $this->sendSMS($member->phone, $message, $channel, false, $member, $progress->id);
                            $progress->update([
                                'current_question_id' => $nextQuestion->id,
                                'last_dispatched_at' => now(),
                                'has_responded' => false,
                                'number_of_reminders' => 0,
                            ]);
                            Log::info("Next question sent to {$member->phone}");
                        } else {
                            // All questions answered, mark as complete.
                            $progress->update([
                                'completed_at' => now(),
                                'status' => 'COMPLETED',
                                'number_of_reminders' => 0,
                            ]);
                            $stage = str_replace(' ', '', ucfirst($survey->title)) . 'Completed';
                            $member->update(['stage' => $stage]);
                            Log::info("Survey completed by {$member->phone}, stage updated to {$stage}");
                        }
                    } else {
                        // No unprocessed reply — consider a reminder, recomputing the due
                        // check from the FRESH last_dispatched_at so we never remind right
                        // after a webhook just advanced this member.
                        $confirmationDue = Carbon::parse($progress->last_dispatched_at)->copy()
                            ->add($survey->continue_confirmation_interval, $survey->continue_confirmation_interval_unit);

                        if ($confirmationDue->lessThanOrEqualTo(now()) && $progress->number_of_reminders < 3) {
                            Log::info("No response from member - sending reminder #{$progress->number_of_reminders}");

                            try {
                                $result = app(SurveyReminderService::class)->queueReminderForProgress($progress->id, $survey);
                                if ($result['status'] === 'queued') {
                                    Log::info("Reminder sent to {$member->phone}");
                                }
                            } catch (\Exception $e) {
                                Log::error("Failed to send reminder to {$member->phone}: " . $e->getMessage());
                            }
                        }
                    }
                } finally {
                    $memberLock->release();
                }
            }
        } finally {
            $lock->release();
        }
    }

    public function sendSMS($msisdn, $message, $channel, $is_reminder, $member, $survey_progress_id = null)
    {
        try {
            app(\App\Services\SurveyMessageQueueService::class)->queue([
                'phone_number' => $msisdn,
                'message' => $message,
                'channel' => $channel,
                'is_reminder' => $is_reminder,
                "member_id" => $member->id,
                'survey_progress_id' => $survey_progress_id,
            ]);
        } catch (\Exception $e) {
            // Log and rethrow the exception so the caller can handle it
            Log::error("Failed to create SMSInbox record for $msisdn: " . $e->getMessage());
            throw $e; // <-- this allows the outer try-catch to detect the failure
        }
    }
}
