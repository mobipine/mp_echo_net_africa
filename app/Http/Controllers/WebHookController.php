<?php

namespace App\Http\Controllers;

use App\Models\Survey;
use App\Models\SurveyQuestion;
use App\Models\SurveyResponse;
use App\Models\SurveyProgress;
use App\Models\Member;
use App\Models\SMSInbox;
use App\Services\UjumbeSMS;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class WebHookController extends Controller
{
    public function handleWebhook(Request $request)
    {
        Log::info('Webhook received:', $request->all());

        $validatedData = $request->validate([
            'phone_number' => 'required|string',
            'message' => 'required|string',
        ]);

        $msisdn = $validatedData['phone_number'];
        $message = trim(strtolower($validatedData['message']));

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
            ->whereNull('completed_at')
            ->latest('last_dispatched_at')
            ->first();

        if ($progress) {
            // Process the user's response
            return $this->processSurveyResponse($msisdn, $progress, $validatedData['message']);
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
            return response()->json(['status' => 'error', 'message' => 'Invalid response.']);
        }


        // Store the response
        SurveyResponse::create([
            'survey_id' => $survey->id,
            'msisdn' => $msisdn,
            'question_id' => $currentQuestion->id,
            'survey_response' => $response,
            'session_id' => $progress->id,//this is a foreign key to the survey_progress table
        ]);

        // Mark the question as responded to in the progress table
        $progress->update(['has_responded' => true]);
        Log::info("Response recorded for question ID: {$currentQuestion->id}. Waiting for next scheduled dispatch.");

        // Check if this was the last question in the survey.
        $nextQuestion = getNextQuestion($survey->id, $response, $currentQuestion->id);
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
