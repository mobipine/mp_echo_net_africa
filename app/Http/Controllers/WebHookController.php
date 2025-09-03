<?php

namespace App\Http\Controllers;

use App\Models\Survey;
use App\Models\SurveyQuestion;
use App\Models\SurveyResponse;
use App\Models\SurveyProgress;
use App\Models\Member;
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

    private function startSurvey($msisdn, Survey $survey)
    {
        $firstQuestion = $survey->questions()->orderBy('pivot_position')->first();

        if (!$firstQuestion) {
            return response()->json(['status' => 'error', 'message' => 'Survey has no questions.']);
        }

        // Get the member ID based on the phone number
        $member = Member::where('phone', $msisdn)->first();

        if (!$member) {
            Log::warning("No member found with phone number: {$msisdn}");
            return response()->json(['status' => 'error', 'message' => 'Phone number not recognized.']);
        }
        
        // Create a new progress record
        $progress = SurveyProgress::firstOrCreate(
            ['survey_id' => $survey->id, 'member_id' => $member->id],
            [
                'current_question_id' => $firstQuestion->id,
                'last_dispatched_at' => now(),
                'has_responded' => false
            ]
        );

        // Only send the first question if this is a new survey
        if ($progress->wasRecentlyCreated) {
            $this->sendSMS($msisdn, "New Survey: {$survey->title}\n\nQuestion 1: {$firstQuestion->question}\nPlease reply with your answer.");
            return response()->json([
                'status' => 'success',
                'message' => 'Survey started.',
                'question_sent' => $firstQuestion->question
            ]);
        }
        
        // If a record already exists, just acknowledge it.
        return response()->json(['status' => 'info', 'message' => 'Survey already in progress.']);
    }

    private function processSurveyResponse($msisdn, SurveyProgress $progress, $response)
    {
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

        // Check if the user has already responded to this specific question
        $existingResponse = SurveyResponse::where('msisdn', $msisdn)
            ->where('survey_id', $survey->id)
            ->where('question_id', $currentQuestion->id)
            ->exists();

        if ($existingResponse) {
            Log::info("Duplicate response for question_id {$currentQuestion->id} from {$msisdn}. Ignoring.");
            return response()->json(['status' => 'info', 'message' => 'Response already received for this question.']);
        }

        // Store the response
        SurveyResponse::create([
            'survey_id' => $survey->id,
            'msisdn' => $msisdn,
            'question_id' => $currentQuestion->id,
            'survey_response' => $response,
        ]);

        // Mark the question as responded to in the progress table
        $progress->update(['has_responded' => true]);
        Log::info("Response recorded for question ID: {$currentQuestion->id}. Waiting for next scheduled dispatch.");

        // Check if this was the last question in the survey.
        $nextQuestion = $currentQuestion->getNextQuestion($survey->id);
        if (!$nextQuestion) {
            // If no more questions, end the survey and send the final response
            $progress->update(['completed_at' => now()]);
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
        ]);
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
