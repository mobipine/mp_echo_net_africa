<?php

namespace App\Http\Controllers;

use App\Models\Group;
use App\Models\Survey;
use App\Models\SurveyQuestion;
use App\Models\SurveyResponse;
use App\Models\SurveyProgress;
use App\Models\Member;
use App\Models\MemberEditRequest;
use App\Models\SMSInbox;
use App\Services\UjumbeSMS;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class WebHookController extends Controller
{
    public function handleWebhook(Request $request)
    {
        Log::info('Webhook received:', $request->all());

        $data = $request->all();

        $msisdn = null;
        $message = null;

        // Case 1: Custom payload
        if (isset($data['phone_number']) && isset($data['message'])) {
            $msisdn = $data['phone_number'];
            $message = trim(strtolower($data['message']));
        }

        // Case 2: WhatsApp webhook format
        elseif (isset($data['messages'][0])) {
            $msg = $data['messages'][0];

            // Ignore messages you sent yourself
            if (!empty($msg['from_me'])) {
                Log::info("Ignored own message: " . ($msg['text']['body'] ?? 'N/A'));
                return response()->json(['status' => 'ignored']);
            }

            $msisdn = $msg['from'] ?? null;
            $message = isset($msg['text']['body'])
                ? trim(strtolower($msg['text']['body']))
                : null;
        }

        // Normalize MSISDN (ensure all start with 254...)
       if ($msisdn) {
        // If number starts with 254, change to 0...
        if (substr($msisdn, 0, 3) === "254") {
            $msisdn = "0" . substr($msisdn, 3);
        }
    }

        // Check if the message is a trigger word for any survey
        $survey = Survey::where('trigger_word', $message)->first();

        //TODO: CHECK IF THE member has an active survey
        if ($survey) {
            return $this->startSurvey($msisdn, $survey);
        }

        // Check if the user is in an active survey progress state
        $progress = SurveyProgress::with(['survey', 'currentQuestion'])
            ->whereHas('member', function ($query) use ($msisdn) {
                $query->where('phone', $msisdn);
            })
            ->whereIn('status', ['ACTIVE', 'PENDING'])
            ->whereNull('completed_at')
            ->latest('last_dispatched_at')
            ->first();

        // Process the user's response
        if ($progress) {
           
            return $this->processSurveyResponse($msisdn, $progress, $message);
              
        }

        
        Log::info("No active survey or trigger word found for message: $message");

        return response()->json(['status' => 'ignored', 'message' => 'No active survey or trigger word found.']);
    }

    public function startSurvey($msisdn, Survey $survey)
    {
        //TODO: CREATE A FUNCTION TO GET THE FIRST QUESTION FROM THE FLOW BUILDER
        // $firstQuestion = $survey->questions()->orderBy('pivot_position')->first();
        $firstQuestion = getNextQuestion($survey->id);
        if (!$firstQuestion) {
            return response()->json(['status' => 'error', 'message' => 'Survey has no questions.']);
        }
        // Get the member ID based on the phone number
        $member = Member::where('phone', $msisdn)->first();

        if (!$member) {
            Log::warning("No member found with phone number: {$msisdn}");
            return response()->json(['status' => 'error', 'message' => 'Phone number not recognized.']);
        }


        //find a record with the survey id and member id that is not completed
        $progress = SurveyProgress::where('survey_id', $survey->id)
            ->where('member_id', $member->id)
            ->whereNull('completed_at')
            ->first();

        if ($progress) {
            //check if survey has member uniquesness
            $p_unique = $survey->participant_uniqueness;
            if ($p_unique) {
                return response()->json(['status' => 'info', 'message' => 'Survey already started.']);
            } else {
                //update all previous progress records with thesame survey_id and member_id status to CANCELLED
                SurveyProgress::where('survey_id', $survey->id)
                    ->where('member_id', $member->id)
                    ->whereNull('completed_at')
                    ->update(['status' => 'CANCELLED']);


                //create a new progress record
                $newProgress = SurveyProgress::create([
                    'survey_id' => $survey->id,
                    'member_id' => $member->id,
                    'current_question_id' => $firstQuestion->id,
                    'last_dispatched_at' => now(),
                    'has_responded' => false,
                    'source' => 'shortcode'
                ]);

                //send the first question
                $message = "New Survey: {$survey->title}\n\nQuestion 1: {$firstQuestion->question}\nPlease reply with your answer.";
                $this->sendSMS($msisdn, $message);
                return response()->json([
                    'status' => 'success',
                    'message' => 'Survey started.',
                    'question_sent' => $firstQuestion->question
                ]);
            }
        } else {
            //create a new progress record
            $newProgress = SurveyProgress::create([
                'survey_id' => $survey->id,
                'member_id' => $member->id,
                'current_question_id' => $firstQuestion->id,
                'last_dispatched_at' => now(),
                'has_responded' => false,
                'source' => 'shortcode'
            ]);

            //send the first question
            $message = "New Survey: {$survey->title}\n\nQuestion 1: {$firstQuestion->question}\nPlease reply with your answer.";
            $this->sendSMS($msisdn, $message);
            return response()->json([
                'status' => 'success',
                'message' => 'Survey started.',
                'question_sent' => $firstQuestion->question
            ]);
        }
    }

    public function processSurveyResponse($msisdn, SurveyProgress $progress, $response)
    {

        //THIS FUNCTION SHOULD VALIDATE THE RESPONSE BASED ON THE QUESTION'S DATA TYPE AND STORE IT IF VALID
        $currentQuestion = $progress->currentQuestion;
        $survey = $progress->survey;


        Log::info("The survey progress status is $progress->status");

        $userResponse = trim($response);  
        Log::info($userResponse);

        $actualAnswer = null;

        if ($currentQuestion->answer_strictness === "Multiple Choice") {
            foreach ($currentQuestion->possible_answers as $answer) {
                if (strcasecmp($answer['letter'], $userResponse) === 0) {
                    $actualAnswer = $answer['answer']; // e.g. "No"
                    break;
                }
            }
        } else {
            $actualAnswer = $userResponse; // For non-multiple-choice, just store as is
        }

        Log::info("The actual answer is $actualAnswer");

         $member = $progress->member;
         if ($currentQuestion->purpose=="edit_id") {
           
            $memberEditRequest=MemberEditRequest::updateOrCreate(
                [
                    'phone_number'=>$msisdn,
                    'name' =>$member->name,
                    'status' => "pending",
                ],
                [
                    'national_id' =>$actualAnswer,
                ]);
            $progress->update(['has_responded' => true]);

        } elseif ($currentQuestion->purpose=="edit_year_of_birth") {
            
            $dob = \Carbon\Carbon::parse($member->dob);

            $dob->year = (int)$actualAnswer;
            
            $memberEditRequest=MemberEditRequest::updateOrCreate(
                [
                    'phone_number'=>$msisdn,
                    'name' =>$member->name,
                    'status' => "pending",
                ],
                [
                    'year_of_birth' =>$actualAnswer,
                ]);

            $progress->update(['has_responded' => true]);

        } elseif ($currentQuestion->purpose=="edit_gender") {
            
            Log::info("Updating member gender...");

            $memberEditRequest=MemberEditRequest::updateOrCreate(
                [
                    'phone_number'=>$msisdn,
                    'name' =>$member->name,
                    'status' => "pending",
                ],
                [
                    'gender' =>$actualAnswer,
                ]);
            $progress->update(['has_responded' => true]);

        } elseif ($currentQuestion->purpose=="edit_group") {

            $memberEditRequest=MemberEditRequest::updateOrCreate(
                [
                    'phone_number'=>$msisdn,
                    'name' =>$member->name,
                    'status' => "pending",
                ],
                [
                    'group' =>$actualAnswer,
                ]);
            $progress->update(['has_responded' => true]);

        }
        
        //If the survey progress status is pending it means we had sent the confirmation message

        //Handle the confirmation later

        // if ($progress->status=="PENDING"){
        //     Log::info("This is a confirmation message response");
        //     $response=trim(strtolower($response));
        //     if ($response=="yes"){
        //         Log::info("The member wishes to continue with the survey. Updating survey progress to ACTIVE. Resending the previous question...");
        //         $progress->update([
        //             'status'=>'ACTIVE',
        //         ]);
        //         $message = "{$currentQuestion->question}\n"; 
        //         if ($currentQuestion->answer_strictness=="Multiple Choice"){
        //             foreach ($currentQuestion->possible_answers as $answer) {
        //                 $message .= "{$answer['letter']}. {$answer['answer']}\n";
        //             }
        //             Log::info("The message to be sent is {$message}");
        //         }
        //         $message .= "Please reply with your answer.";
        //         $this->sendSMS($msisdn, $message);
        //         return response()->json([
        //             'status'=>'success',
        //             'message'=>'The member wishes to continue with the survey. Updating survey progress to ACTIVE. Resending the previous question...'
        //         ]);
        //     }
        //     elseif ($response=="no"){
        //         Log::info("The member does not wish to continue with the survey. Updating survey progress to CANCELLED");
        //         $progress->update([
        //             'status'=>'CANCELLED'
        //         ]);
        //         return response()->json([
        //             'status'=>'success',
        //             'message'=>"The member does not wish to continue with the survey. Updating survey progress to CANCELLED"
        //         ]);
        //     }else{
        //         Log::info("invalid response");
        //         return response()->json([
        //             'status'=>'error',
        //             'message'=>"Invalid response, it's a yes or no question",
        //         ]);
        //     }
             
        // }

        Log::info("This is the current question $currentQuestion");

        if (!$currentQuestion) {
            return response()->json(['status' => 'error', 'message' => 'Invalid question or session state.']);
        }

        // Validate the response based on the question's answer data type
        if ($currentQuestion->answer_data_type === 'Strictly Number' && !is_numeric($response)) {
            $this->sendSMS($msisdn, $currentQuestion->data_type_violation_response);
            return response()->json(['status' => 'error', 'message' => 'Invalid response.']);
        }

        if ($currentQuestion->answer_data_type === 'Alphanumeric' && !ctype_alnum(str_replace(' ', '', $response))) {
            $this->sendSMS($msisdn, $currentQuestion->data_type_violation_response);
            Log::info("the response violates the questions strictness");
            return response()->json(['status' => 'error', 'message' => 'Invalid response.']);
        }
        $inbox_id = SMSInbox::where('phone_number', $msisdn)
                    ->latest()
                    ->first()
                    ->id;


        // Store the response
        SurveyResponse::create([
            'survey_id' => $survey->id,
            'msisdn' => $msisdn,
            'question_id' => $currentQuestion->id,
            'survey_response' => $actualAnswer,
            'inbox_id' => $inbox_id,
            'session_id' => $progress->id,//this is a foreign key to the survey_progress table
        ]);

        // Mark the question as responded to in the progress table
        $progress->update(['has_responded' => true]);
        Log::info("Response recorded for question ID: {$currentQuestion->id}. Waiting for next scheduled dispatch.");

        // Check if this was the last question in the survey.
        $nextQuestion = getNextQuestion($survey->id,  $actualAnswer, $currentQuestion->id);
        // dd($nextQuestion);
        if (!$nextQuestion || (is_array($nextQuestion) && isset($nextQuestion['status']) && $nextQuestion['status'] == 'completed')) {
            // If no more questions, end the survey and send the final response
            $progress->update(
                [
                    'completed_at' => now(),
                    'status' => 'COMPLETED'
                ]
            );
            $this->sendSMS($msisdn, $survey->final_response);

            return response()->json([
                'status' => 'success',
                'message' => 'Survey completed.',
                'final_response' => $survey->final_response
            ]);
        }

        // The next question will be sent by the scheduled command.
        return response()->json([
            'status' => 'success',
            'message' => 'Response received. Thank you!',
            'nxt' => $nextQuestion
        ]);
    }

    public function sendSMS($msisdn, $message)
    {

        try {

            SMSInbox::create([
                'phone_number' => $msisdn, // Store the phone number in group_ids for tracking
                'message' => $message,
            ]);
        } catch (\Exception $e) {
            Log::error("Failed to create SMSInbox record for $msisdn: " . $e->getMessage());
        }
    }
}
