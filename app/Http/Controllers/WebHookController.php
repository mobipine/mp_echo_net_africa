<?php

namespace App\Http\Controllers;

use App\Models\Survey;
use App\Models\SurveyQuestion;
use App\Models\SurveyResponse;
use App\Models\ShortcodeSession;
use App\Services\UjumbeSMS;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class WebHookController extends Controller
{
    public function handleWebhook(Request $request)
    {
        // Log the incoming webhook data
        Log::info('Webhook received:', $request->all());

        // Validate the incoming request
        $validatedData = $request->validate([
            'phone_number' => 'required|string',
            'message' => 'required|string',
        ]);

        $msisdn = $validatedData['phone_number'];
        $message = trim(strtolower($validatedData['message']));

        // Check if the message is a trigger word for any survey
        $survey = Survey::where('trigger_word', $message)->first();

        if ($survey) {
            // Start a new survey session
            return $this->startSurvey($msisdn, $survey);
        }

        // Check if the user is in an active session
        $session = ShortcodeSession::where('msisdn', $msisdn)
            ->whereNotNull('current_question_id')
            ->latest()
            ->first(); // a user can have multiple sessions, so we get the latest one

            // dd($session);

        if ($session) {
            // Process the user's response
            return $this->processSurveyResponse($msisdn, $session, $validatedData['message']);
        }

        // If no trigger word or active session, store the message as a generic response
        Log::info("No active survey or trigger word found for message: $message");

        return response()->json(['status' => 'ignored', 'message' => 'No active survey or trigger word found.']);
    }

    private function startSurvey($msisdn, Survey $survey)
    {
        // Get the first question in the survey
        $firstQuestion = $survey->questions()->orderBy('position')->first();

        if (!$firstQuestion) {
            return response()->json(['status' => 'error', 'message' => 'Survey has no questions.']);
        }

        // Create a new session
        ShortcodeSession::create([
            'msisdn' => $msisdn,
            'survey_id' => $survey->id,
            'current_question_id' => $firstQuestion->id,
        ]);

        // Send the first question
        $this->sendSMS($msisdn, $firstQuestion->question);

        return response()->json(['status' => 'success', 'message' => 'Survey started.']);
    }

    private function processSurveyResponse($msisdn, ShortcodeSession $session, $response)
    {
        $currentQuestion = SurveyQuestion::find($session->current_question_id);

        if (!$currentQuestion) {
            return response()->json(['status' => 'error', 'message' => 'Invalid question.']);
        }

        // Validate the response based on the question's answer data type
        if ($currentQuestion->answer_data_type === 'Strictly Number' && !is_numeric($response)) {
            // Send the data type violation response
            $this->sendSMS($msisdn, $currentQuestion->data_type_violation_response);

            return response()->json(['status' => 'error', 'message' => 'Invalid response.']);
        }

        if ($currentQuestion->answer_data_type === 'Alphanumeric' && !ctype_alnum(str_replace(' ', '', $response))) {
            // Send the data type violation response
            $this->sendSMS($msisdn, $currentQuestion->data_type_violation_response);

            return response()->json(['status' => 'error', 'message' => 'Invalid response.']);
        }

        // Store the response in the survey_responses table
        SurveyResponse::create([
            'survey_id' => $session->survey_id,
            'msisdn' => $msisdn,
            'question_id' => $session->current_question_id,
            'survey_response' => $response,
        ]);

        // Get the next question in the survey
        $survey = $session->survey;
        
        $survey_question_id = 
        $nextQuestion = $currentQuestion->getNextQuestion($survey->id);
        // dd($survey, "here", $currentQuestion->getPosition($survey->id), $nextQuestion);

        if ($nextQuestion) {
            // Update the session with the next question
            $session->update(['current_question_id' => $nextQuestion->id]);

            // Send the next question
            $this->sendSMS($msisdn, $nextQuestion->question);

            return response()->json(['status' => 'success', 'message' => 'Next question sent.']);
        }

        // If no more questions, end the survey and send the final response
        $session->update(['current_question_id' => null]); // Mark session as complete
        $this->sendSMS($msisdn, $survey->final_response);

        return response()->json(['status' => 'success', 'message' => 'Survey completed.']);
    }

    private function sendSMS($msisdn, $message)
    {
        try {
            $ujumbeSMS = new UjumbeSMS();
            $ujumbeSMS->send($msisdn, $message);
        } catch (\Exception $e) {
            Log::error("Failed to send SMS to $msisdn: " . $e->getMessage());
        }
    }
}