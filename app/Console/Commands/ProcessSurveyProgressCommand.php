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
    protected $signature = 'process:surveys-progress';
    protected $description = 'Sends next survey questions or reminders based on participant progress.';

    public function handle()
    {
        Log::info("in the command");

        $progressRecords = SurveyProgress::with(['survey', 'currentQuestion', 'member'])
            ->whereNull('completed_at')
            ->whereIn('status', ['ACTIVE', 'UPDATING_DETAILS'])
            ->get();

        if($progressRecords->isEmpty()){
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

            if($source != "shortcode") {
                $groupSurvey = GroupSurvey::where('survey_id', $survey->id)->first();
    
                // If no pivot data exists, skip this record to prevent errors
                if (!$groupSurvey ) {
                    Log::warning("Group-survey relationship not found for survey ID: {$survey->id}.");
                    continue;
                }
                Log::info("the progress was initiated from a group survey");
    
                $interval = $currentQuestion->question_interval ?? 1; // Use the pivot value, or default to 3 days
                $unit = $currentQuestion->question_interval_unit ?? 'seconds'; // Use the pivot value, or default to 'days'

                $endDate = GroupSurvey::where('group_id', $member->group_id)
                        ->where('survey_id',$survey->id)
                        ->value('ends_at');
                

            } else {
                //for shortcode surveys, use 1 minute interval
                //TODO: Josphat: Create a global config for on the survey resource
                $interval = $currentQuestion->question_interval ?? 1; // Use the pivot value, or default to 3 days
                $unit = $currentQuestion->question_interval_unit ?? 'seconds'; // Use the pivot value, or default to 'days'

                $endDate = null;
            }

            Log::info("The survey ends on $endDate");
            //check if endDate has passed. If it has, continue to the next record
            if ($endDate && now()->greaterThan(Carbon::parse($endDate))) {
                Log::info("Survey {$survey->title} for member {$member->phone} has ended on $endDate. Skipping.");
                continue;
            }

            // Check if the time since the last dispatch has exceeded the defined interval
            $lastDispatched = Carbon::parse($progress->last_dispatched_at);
            Log::info("Last Dispatched $lastDispatched");

            $nextDue = $lastDispatched->add($interval, $unit);
            Log::info("The interval should be $interval $unit");
            Log::info("Next Due Date $nextDue");

            $isDue = $nextDue->lessThanOrEqualTo(now()); 
            Log::info("is Due is: ".$isDue);

            if (!$isDue) {
                Log::info("Survey progress ID: {$progress->id} is not yet due for processing.");
                continue;
            }

            // $confirmation_interval=$survey->continue_confirmation_interval;
            // $confirmation_interval_unit=$survey->continue_confirmation_interval_unit;

            $confirmationDue=$lastDispatched->add(3, "days");
            $isconfirmationDue=$confirmationDue->lessThanOrEqualTo(now());

            Log::info("If the user has not yet responded to the previous question sent in {$survey->title} and the time for to dispatch the confirmation question {$confirmationDue} has reached a confirmation message will be sent, if he has responded, the next question will be sent.");

            // Check if the user has responded since the last dispatch
            $hasResponded = SurveyProgress::where('member_id', $member->id)
                ->where('survey_id', $survey->id)
                ->where('has_responded', true)
                ->whereIn('status', ['ACTIVE', 'UPDATING_DETAILS'])
                ->exists();
                
        
            if ($hasResponded) {
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
                //TODO: MODIFY FUNCTION TO GET THE NEXT QUESTION FROM THE FLOW BUILDER
                $nextQuestion = getNextQuestion($survey->id, $response, $currentQuestion->id);

                if ($nextQuestion) {
                    Log::info("The member responded to previous question. Sending the next question");

                    //Formatting the question 
                    $message=formartQuestion($nextQuestion,$member,$survey);
                    Log::info("This is the message ".$message);

                    $this->sendSMS($member->phone, $message,$channel,false);
                    $progress->update([
                        'current_question_id' => $nextQuestion->id,
                        'last_dispatched_at' => now(),
                        'has_responded' => false,
                    ]);
                    Log::info("Next question sent to {$member->phone} for survey {$survey->title}.");
                   
                } else {
                    // All questions answered, mark as complete
                    $progress->update([
                        'completed_at' => now(),
                        'status' => 'COMPLETED'
                    ]);
                    Log::info("Survey {$survey->title} completed by {$member->phone}.");
                }

            } elseif($isconfirmationDue) {
                    //reminder
                $message=formartQuestion($member,$survey,$currentQuestion,true);
                Log::info("This is the formated message $message");
                Log::info("No response from member. Sending the reminder message {$message}...");
                 
                try {
                    // $smsService->send($member->phone, $message);
                    $this->sendSMS($member->phone, $message, $progress?->channel ?? 'sms',true);
                    $progress->update([
                        'last_dispatched_at' => now(),
                        
                    ]); // Update timestamp and status
                    Log::info("Confirmation sent to {$member->phone} for survey {$survey->title}.");
                } catch (\Exception $e) {
                    Log::error("Failed to send confirmation to {$member->phone}: " . $e->getMessage());
                }
            }
        }
    }

    public function sendSMS($msisdn, $message,$channel,$is_reminder) {

        try{
            SMSInbox::create([
                'phone_number' => $msisdn, // Store the phone number in group_ids for tracking
                'message' => $message,
                'channel' => $channel,
                'is_reminder' => $is_reminder
            ]);
        } catch (\Exception $e) {
            Log::error("Failed to create SMSInbox record for $msisdn: " . $e->getMessage());
        }
    }
}