<?php

namespace App\Console\Commands;

use App\Models\GroupSurvey;
use App\Models\SMSInbox;
use App\Models\SurveyProgress;
use App\Models\SurveyQuestion;
use App\Models\SurveyResponse;
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

                // Check if the time since the last dispatch has exceeded the defined interval
                $lastDispatched = Carbon::parse($progress->last_dispatched_at);
                $nextDue = $lastDispatched->copy()->add($interval, $unit);
                $isDue = $nextDue->lessThanOrEqualTo(now());

                if (!$isDue) {
                    continue;
                }

                // Message is due - start logging
                // Log::info("Processing survey progress for member {$member->name} (ID: {$member->id})");
                // Log::info("Survey: {$survey->title}, Last Dispatched: $lastDispatched, Next Due: $nextDue");

                $reminder_interval = $survey->continue_confirmation_interval;
                $reminder_interval_unit = $survey->continue_confirmation_interval_unit;

                $confirmationDue = $lastDispatched->copy()->add($reminder_interval, $reminder_interval_unit);
                $isconfirmationDue = $confirmationDue->lessThanOrEqualTo(now());

                // Check if the user has responded since the last dispatch
                $hasResponded = SurveyProgress::where('member_id', $member->id)
                    ->where('survey_id', $survey->id)
                    ->where('has_responded', true)
                    ->whereIn('status', ['ACTIVE', 'UPDATING_DETAILS'])
                    ->exists();

                if ($hasResponded) {
                    Log::info("Member has responded - sending next question");
                    $progress = SurveyProgress::where('member_id', $member->id)
                        ->where('survey_id', $survey->id)
                        ->where('has_responded', true)
                        ->whereIn('status', ['ACTIVE', 'UPDATING_DETAILS'])
                        ->latest()
                        ->first();

                    $channel = $progress?->channel ?? 'sms'; // default to sms if null

                    //get the response
                    $latestResponse = SurveyResponse::where('session_id', $progress->id)
                        ->where('survey_id', $survey->id)
                        ->where('question_id', $currentQuestion->id)
                        ->latest()
                        ->first();
                    $response = $latestResponse ? $latestResponse->survey_response : null;

                    // User has responded, send the next question
                    $nextQuestion = getNextQuestion($survey->id, $response, $currentQuestion->id);

                    // Check if nextQuestion is an error/violation array or a valid question object
                    if (is_array($nextQuestion)) {
                        // Handle error or violation response
                        Log::error("Error getting next question for member {$member->id}: " . ($nextQuestion['message'] ?? 'Unknown error'));

                        if (($nextQuestion['status'] ?? null) === 'violation') {
                            // Send violation message to member
                            $violationMessage = $nextQuestion['message'];
                            $this->sendSMS($member->phone, $violationMessage, $channel, false, $member, $progress->id);
                            Log::info("Sent violation message to member {$member->id}: {$violationMessage}");

                            // CRITICAL: Reset has_responded to prevent loop
                            // Member must respond again before we process further
                            $progress->update([
                                'last_dispatched_at' => now(),
                                'has_responded' => false, // Require new response
                            ]);
                        } else {
                            // Error - mark survey as completed with error status
                            Log::error("Survey flow error for member {$member->id}, survey {$survey->id}. Marking as completed.");
                            $progress->update([
                                'completed_at' => now(),
                                'status' => 'error',
                            ]);
                        }
                        continue; // Skip to next progress record
                    }

                    if ($nextQuestion && $nextQuestion instanceof \App\Models\SurveyQuestion) {
                        //Formatting the question
                        $message = formartQuestion($nextQuestion, $member, $survey);

                        $this->sendSMS($member->phone, $message, $channel, false, $member, $progress->id);
                        $progress->update([
                            'current_question_id' => $nextQuestion->id,
                            'last_dispatched_at' => now(),
                            'has_responded' => false,
                            // Reset reminders count
                            'number_of_reminders' => 0,
                        ]);
                        Log::info("Next question sent to {$member->phone}");
                    } else {
                        // All questions answered, mark as complete
                        $progress->update([
                            'completed_at' => now(),
                            'status' => 'COMPLETED',
                            'number_of_reminders' => 0,
                        ]);
                        $stage = str_replace(' ', '', ucfirst($survey->title)) . 'Completed';
                        $member->update([
                            'stage' => $stage
                        ]);
                        Log::info("Survey completed by {$member->phone}, stage updated to {$stage}");
                    }
                } elseif ($isconfirmationDue) {
                    // Check if user has already received 3 reminders
                    if ($progress->number_of_reminders >= 3) {
                        Log::info("Max reminders (3) reached for {$member->phone}");
                        continue; // Skip sending
                    }

                    Log::info("No response from member - sending reminder #{$progress->number_of_reminders}");
                    $message = formartQuestion($currentQuestion, $member, $survey, true);

                    try {
                        $this->sendSMS($member->phone, $message, $progress?->channel ?? 'sms', true, $member, $progress->id);
                        $progress->update([
                            'last_dispatched_at' => now(),
                        ]);
                        $progress->increment('number_of_reminders');
                        Log::info("Reminder sent to {$member->phone}");
                    } catch (\Exception $e) {
                        Log::error("Failed to send reminder to {$member->phone}: " . $e->getMessage());
                    }
                }
            }
        } finally {
            $lock->release();
        }
    }

    public function sendSMS($msisdn, $message, $channel, $is_reminder, $member, $survey_progress_id = null)
    {
        try {
            SMSInbox::create([
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
