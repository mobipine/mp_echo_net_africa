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
            Log::info("in the command");

            $progressRecords = SurveyProgress::with(['survey', 'currentQuestion', 'member'])
                ->whereNull('completed_at')
                ->whereIn('status', ['ACTIVE', 'UPDATING_DETAILS'])
                ->get();

            // dd($progressRecords, "progressRecords");

            if ($progressRecords->isEmpty()) {
                Log::info("No acive progress record");
                return;
            }

            Log::info("looping through active progress records");

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
                    Log::info("the progress was initiated from a group survey");

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

// dd($progress->last_dispatched_at, "last dispatched");
                // Check if the time since the last dispatch has exceeded the defined interval
                $lastDispatched = Carbon::parse($progress->last_dispatched_at);
                Log::info("Last Dispatched $lastDispatched");

                $nextDue = $lastDispatched->add($interval, $unit);
                Log::info("The interval should be $interval $unit");
                Log::info("Next Due Date $nextDue");

                $isDue = $nextDue->lessThanOrEqualTo(now());
                Log::info("is Due is: " . $isDue);

                // dd($isDue, "is due");

                if (!$isDue) {
                    Log::info("Survey progress ID: {$progress->id} is not yet due for processing.");
                    continue;
                }

                $reminder_interval = $survey->continue_confirmation_interval;
                $reminder_interval_unit = $survey->continue_confirmation_interval_unit;

                $confirmationDue = $lastDispatched->add($reminder_interval, $reminder_interval_unit);
                $isconfirmationDue = $confirmationDue->lessThanOrEqualTo(now());

                Log::info("If the user has not yet responded to the previous question sent in {$survey->title} and the time for to dispatch the confirmation question {$confirmationDue} has reached a confirmation message will be sent, if he has responded, the next question will be sent.");

                // Check if the user has responded since the last dispatch
                $hasResponded = SurveyProgress::where('member_id', $member->id)
                    ->where('survey_id', $survey->id)
                    ->where('has_responded', true)
                    ->whereIn('status', ['ACTIVE', 'UPDATING_DETAILS'])
                    ->exists();
// dd($hasResponded, "has responded", $isconfirmationDue, "is confirmation due");

                if ($hasResponded) {
                    /**
                     * The member has responded to the survey and next dispatch is due. Sending the next question...
                     */
                    Log::info("The member {$member->name} has responded to the survey and next dispatch is due. Sending the next question...");
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
                            $this->sendSMS($member->phone, $violationMessage, $channel, false, $member);
                            Log::info("Sent violation message to member {$member->id}: {$violationMessage}");

                            // Update progress to indicate we sent violation message (don't mark as responded)
                            $progress->update([
                                'last_dispatched_at' => now(),
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
                        Log::info("The member responded to previous question. Sending the next question");

                        //Formatting the question
                        $message = formartQuestion($nextQuestion, $member, $survey);
                        Log::info("This is the message " . $message);

                        $this->sendSMS($member->phone, $message, $channel, false, $member);
                        $progress->update([
                            'current_question_id' => $nextQuestion->id,
                            'last_dispatched_at' => now(),
                            'has_responded' => false,
                            // Reset reminders count
                            'number_of_reminders' => 0,
                        ]);
                        Log::info("Next question sent to {$member->phone} for survey {$survey->title}.");
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
                        Log::info("Survey {$survey->title} completed by {$member->phone}. Updated his stage to $stage");
                    }
                } elseif ($isconfirmationDue) {
                    // dd($isconfirmationDue, "is confirmation due", $progress, "progress");
                    /**
                     * The member has not responded to the survey and the confirmation due time has reached. Sending the confirmation question...
                     */
                    //reminder
                    // Check if user has already received 3 reminders
                    if ($progress->number_of_reminders >= 3) {
                        Log::info("Max reminders reached for {$member->phone} on survey {$survey->title}. No further reminders will be sent.");
                        continue; // Skip sending
                    }
                    $message = formartQuestion($currentQuestion, $member, $survey, true);
                    Log::info("This is the formated message $message");
                    Log::info("No response from member. Sending the reminder message {$message}...");

                    try {
                        // $smsService->send($member->phone, $message);
                        $this->sendSMS($member->phone, $message, $progress?->channel ?? 'sms', true, $member);
                        $progress->update([
                            'last_dispatched_at' => now(),

                        ]); // Update timestamp and status
                        $progress->increment('number_of_reminders');
                        Log::info("Confirmation sent to {$member->phone} for survey {$survey->title}.");
                    } catch (\Exception $e) {
                        Log::error("Failed to send confirmation to {$member->phone}: " . $e->getMessage());
                    }
                }
            }
        } finally {
            $lock->release();
        }
    }

    public function sendSMS($msisdn, $message, $channel, $is_reminder, $member)
    {
        try {
            SMSInbox::create([
                'phone_number' => $msisdn,
                'message' => $message,
                'channel' => $channel,
                'is_reminder' => $is_reminder,
                "member_id" => $member->id,
            ]);
        } catch (\Exception $e) {
            // Log and rethrow the exception so the caller can handle it
            Log::error("Failed to create SMSInbox record for $msisdn: " . $e->getMessage());
            throw $e; // <-- this allows the outer try-catch to detect the failure
        }
    }
}
