<?php

namespace App\Console\Commands;

use App\Models\GroupSurvey;
use App\Models\SMSInbox;
use App\Models\SurveyProgress;
use App\Models\SurveyQuestion;
use App\Models\SurveyResponse;
use App\Services\UjumbeSMS;
use Illuminate\Console\Command;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ProcessSurveyProgressCommand extends Command
{
    protected $signature = 'survey:process-progress';
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
    
                $interval = $currentQuestion->question_interval ?? 3; // Use the pivot value, or default to 3 days
                $unit = $currentQuestion->question_interval_unit ?? 'days'; // Use the pivot value, or default to 'days'

                $endDate = GroupSurvey::where('group_id', $member->group_id)
                        ->where('survey_id',$survey->id)
                        ->value('ends_at');
                

            } else {
                //for shortcode surveys, use 1 minute interval
                //TODO: Josphat: Create a global config for on the survey resource
                $interval = 30;
                $unit = 'minutes';

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
            Log::info("Next Due Date $nextDue");
            Log::info("the current question is $currentQuestion");

            $isDue = $nextDue->lessThanOrEqualTo(now()); 

            if (!$isDue) {
                Log::info("Survey progress ID: {$progress->id} is not yet due for processing.");
                continue;
            }

            $confirmation_interval=$survey->continue_confirmation_interval;
            $confirmation_interval_unit=$survey->continue_confirmation_interval_unit;

            $confirmationDue=$lastDispatched->add($confirmation_interval, $confirmation_interval_unit);
            $isconfirmationDue=$confirmationDue->lessThanOrEqualTo(now());

            Log::info("If the user has not yet responded to the previous question sent in {$survey->title} and the time for to dispatch the confirmation question {$confirmationDue} has reached a confirmation message will be sent, if he has responded, the next question will be sent.");

            // Check if the user has responded since the last dispatch
            $hasResponded = SurveyProgress::where('member_id', $member->id)
                ->where('survey_id', $survey->id)
                ->where('has_responded', true)
                ->whereIn('status', ['ACTIVE', 'UPDATING_DETAILS'])
                ->exists();
                
        
            if ($hasResponded) {
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
                    // $message = $this->formatQuestionMessage($nextQuestion);

                    Log::info($nextQuestion);

                    Log::info("The question is {$nextQuestion->answer_strictness}");

                    //Formatting the question if multiple choice
                    if ($nextQuestion->answer_strictness == "Multiple Choice") {
                        $message = "{$nextQuestion->question}\n\n"; 
                        
                        $letters = [];
                        foreach ($nextQuestion->possible_answers as $answer) {
                            $message .= "{$answer['letter']}. {$answer['answer']}\n";
                            $letters[] = $answer['letter'];
                        }
                        
                        // Dynamically build the letter options string
                        if (count($letters) === 1) {
                            $letterText = $letters[0];
                        } elseif (count($letters) === 2) {
                            $letterText = $letters[0] . " or " . $letters[1];
                        } else {
                            $lastLetter = array_pop($letters);
                            $letterText = implode(', ', $letters) . " or " . $lastLetter;
                        }
                        
                        $message .= "\nPlease reply with the letter {$letterText}.";
                        Log::info("The message to be sent is {$message}");

                    } else {
                        $message = $nextQuestion->question;
                        if ($nextQuestion->answer_data_type === 'Strictly Number') {
                            $message .= "\n💡 *Note: Your answer should be a number.*";
                        } elseif ($nextQuestion->answer_data_type === 'Alphanumeric') {
                            $message .= "\n💡 *Note: Your answer should contain only letters and numbers.*";
                        }
                    }
                    
                    try {
                        // $smsService->send($member->phone, $message);
                        $placeholders = [
                            '{member}' => $member->name,
                            '{group}' => $member->group->name,
                            '{id}' => $member->national_id,
                            '{gender}'=>$member->gender,
                            '{dob}'=> \Carbon\Carbon::parse($member->dob)->format('Y'),
                            '{LIP}' => $member?->group?->localImplementingPartner?->name,
                            '{month}' => \Carbon\Carbon::now()->monthName,
                        ];
                        $message = str_replace(
                            array_keys($placeholders),
                            array_values($placeholders),
                            $message
                        );
                        $this->sendSMS($member->phone, $message);
                        $progress->update([
                            'current_question_id' => $nextQuestion->id,
                            'last_dispatched_at' => now(),
                            'has_responded' => false,
                        ]);
                        Log::info("Next question sent to {$member->phone} for survey {$survey->title}.");
                    } catch (\Exception $e) {
                        Log::error("Failed to send next question to {$member->phone}: " . $e->getMessage());
                    }
                } else {
                    // All questions answered, mark as complete
                    $progress->update([
                        'completed_at' => now(),
                        'status' => 'COMPLETED'
                    ]);
                    Log::info("Survey {$survey->title} completed by {$member->phone}.");
                }

            } elseif($isconfirmationDue) {
                
                $continue_confirmation_question= $survey->continue_confirmation_question;
                $placeholders = [
                    '{member}' => $member->name,
                    '{group}' => $member->group->name,
                ];
                $message = str_replace(
                    array_keys($placeholders),
                    array_values($placeholders),
                    $continue_confirmation_question
                );

                Log::info("This is the formated message $message");

                
                Log::info("No response from member. Sending the confirmation message {$message}...");
                // No response and it's been more than 3 days, resend the last question
                // $message = $this->formatQuestionMessage($currentQuestion, true); // Add a reminder prefix
                 
                try {
                    // $smsService->send($member->phone, $message);
                    $this->sendSMS($member->phone, $message);
                    $progress->update([
                        'last_dispatched_at' => now(),
                        'status'=>'PENDING',
                    ]); // Update timestamp and status
                    Log::info("Confirmation sent to {$member->phone} for survey {$survey->title}.");
                } catch (\Exception $e) {
                    Log::error("Failed to send confirmation to {$member->phone}: " . $e->getMessage());
                }
            }
        }
    }

    public function sendSMS($msisdn, $message) {

        try{
            SMSInbox::create([
                'phone_number' => $msisdn, // Store the phone number in group_ids for tracking
                'message' => $message,
            ]);
        } catch (\Exception $e) {
            Log::error("Failed to create SMSInbox record for $msisdn: " . $e->getMessage());
        }
    }
}