<?php

namespace App\Http\Controllers;

use App\Models\Survey;
use App\Models\SurveyProgress;
use App\Models\Member;
use App\Models\MemberEditRequest;
use App\Models\SMSInbox;
use App\Models\SurveyResponse;
use App\Services\WhatsAppService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class WhatsAppController extends Controller
{
    protected $whatsappService;

    public function __construct(WhatsAppService $whatsappService)
    {
        $this->whatsappService = $whatsappService;
    }

    /**
     * Handle incoming WhatsApp webhook
     */
    public function handleWebhook(Request $request)
    {
        Log::info('WhatsApp Webhook Received:', $request->all());

        // Verify if this is a webhook verification request
        if ($request->query('hub_mode') === 'subscribe') {
            return $this->handleWebhookVerification($request);
        }

        try {
            $data = $request->all();
            $messageData = $this->extractMessageData($data);

            if (!$messageData) {
                Log::info('No valid message data found in webhook');
                return response()->json(['status' => 'ignored', 'message' => 'No valid message data']);
            }

            // Ignore messages sent by the bot itself
            if ($messageData['is_from_me']) {
                Log::info("Ignoring message sent by bot");
                // return response()->json(['status' => 'ignored', 'message' => 'Own message ignored']);
            }

            // Normalize phone number
            $normalizedPhone = $this->normalizePhoneNumber($messageData['from']);
            Log::info("Processing message from: {$normalizedPhone}");

            // Process the message
            return $this->processIncomingMessage($normalizedPhone, $messageData['message']);

        } catch (\Exception $e) {
            Log::error('Webhook processing error: ' . $e->getMessage(), [
                'exception' => $e,
                'request_data' => $request->all()
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Internal server error'
            ], 500);
        }
    }

    /**
     * Handle webhook verification
     */
    private function handleWebhookVerification(Request $request)
    {
        $mode = $request->query('hub_mode');
        $token = $request->query('hub_verify_token');
        $challenge = $request->query('hub_challenge');

        if ($mode === 'subscribe' && $token === config('whatsapp.verify_token')) {
            Log::info('Webhook verified successfully');
            return response($challenge, 200);
        }

        Log::warning('Webhook verification failed', [
            'mode' => $mode,
            'token' => $token
        ]);

        return response('Verification failed', 403);
    }

    /**
     * Extract message data from different webhook formats
     */
    private function extractMessageData(array $data): ?array
    {
        // Case 1: Custom API payload (for testing)
        if (isset($data['phone_number']) && isset($data['message'])) {
            return [
                'from' => $data['phone_number'],
                'message' => trim(strtolower($data['message'])),
                'message_id' => $data['message_id'] ?? null,
                'is_from_me' => false,
                'type' => 'text'
            ];
        }

        // Case 2: Standard WhatsApp webhook format
        if (isset($data['entry'][0]['changes'][0]['value']['messages'][0])) {
            $message = $data['entry'][0]['changes'][0]['value']['messages'][0];

            return [
                'from' => $message['from'] ?? null,
                'message' => isset($message['text']['body']) ? trim(strtolower($message['text']['body'])) : null,
                'message_id' => $message['id'] ?? null,
                'is_from_me' => $message['from_me'] ?? false,
                'type' => $message['type'] ?? 'unknown'
            ];
        }

        // Case 3: Alternative WhatsApp format
        if (isset($data['messages'][0])) {
            $message = $data['messages'][0];

            return [
                'from' => $message['from'] ?? null,
                'message' => isset($message['text']['body']) ? trim(strtolower($message['text']['body'])) : null,
                'message_id' => $message['id'] ?? null,
                'is_from_me' => $message['from_me'] ?? false,
                'type' => $message['type'] ?? 'unknown'
            ];
        }

        return null;
    }

    /**
     * Normalize phone number to consistent format
     */
    private function normalizePhoneNumber(string $phoneNumber): string
    {
        // Remove any non-digit characters
        $cleanNumber = preg_replace('/\D/', '', $phoneNumber);

        // Convert 254... to 0... for internal storage
        if (substr($cleanNumber, 0, 3) === "254") {
            $cleanNumber = "0" . substr($cleanNumber, 3);
        }

        // Ensure it starts with 0
        if (substr($cleanNumber, 0, 1) !== "0") {
            $cleanNumber = "0" . ltrim($cleanNumber, '0');
        }

        Log::info("Normalized phone number: {$cleanNumber}");
        return $cleanNumber;
    }

    /**
     * Process incoming message and route to appropriate handler
     */
    private function processIncomingMessage(string $phoneNumber, ?string $message)
    {
        if (!$message) {
            return $this->handleNonTextMessage($phoneNumber);
        }

        Log::info("Processing message from {$phoneNumber}: {$message}");
        Log::info($phoneNumber." is the number that should be saved on the db."."The survey should have $message trigger word");

        // Check if message matches any survey trigger word
        $survey = $this->findSurveyByTriggerWord($message);

        if ($survey) {
            return $this->startSurvey($phoneNumber, $survey);
        }

        // Check for active survey progress
        $progress = $this->findActiveSurveyProgress($phoneNumber);

        if ($progress) {
            return $this->processSurveyResponse($phoneNumber, $progress, $message);
        }

    }

    /**
     * Find survey by trigger word
     */
    private function findSurveyByTriggerWord(string $message): ?Survey
    {
        return Survey::where('trigger_word', $message)
            ->where('status', "Active")
            ->first();
    }

    /**
     * Find active survey progress for member
     */
    private function findActiveSurveyProgress(string $phoneNumber): ?SurveyProgress
    {
        Log::info($phoneNumber." is the number that should be saved on the db");
        $member = Member::where('phone', $phoneNumber)->first();
        
        if (!$member) {
            return null;
        }

        return SurveyProgress::with(['survey', 'currentQuestion'])
            ->where('member_id', $member->id)
            ->whereIn('status', ['ACTIVE', 'PENDING'])
            ->whereNull('completed_at')
            ->latest('last_dispatched_at')
            ->first();
    }

    /**
     * Handle non-text messages (images, documents, etc.)
     */
    private function handleNonTextMessage(string $phoneNumber)
    {
        $response = "Thank you for your message! Currently, I can only process text messages. Please send your response as text.";

        try {
            $this->whatsappService->sendTextMessage(
                $this->formatPhoneForWhatsApp($phoneNumber),
                $response
            );
        } catch (\Exception $e) {
            Log::error("Failed to send non-text message response: " . $e->getMessage());
        }

        return response()->json(['status' => 'processed', 'message' => 'Non-text message handled']);
    }

    private function formatPhoneForWhatsApp(string $phoneNumber): string
    {
        $cleanNumber = preg_replace('/\D/', '', $phoneNumber);
        
        // Convert 0... to 254...
        if (substr($cleanNumber, 0, 1) === "0") {
            $cleanNumber = "254" . substr($cleanNumber, 1);
        }

        return $cleanNumber;
    }

    /**
     * Start a new survey
     */
    private function startSurvey(string $msisdn, Survey $survey)
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
                $message = "New Survey: {$survey->title}\n\nQuestion 1: {->question}\nPlease reply with your answer.";

                if ($firstQuestion->answer_strictness == "Multiple Choice") {
                    $message = "{$firstQuestion->question}\n\n"; 
                    
                    $letters = [];
                    foreach ($firstQuestion->possible_answers as $answer) {
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
                    $message = $firstQuestion->question;
                    if ($firstQuestion->answer_data_type === 'Strictly Number') {
                        $message .= "\n💡 *Note: Your answer should be a number.*";
                    } elseif ($firstQuestion->answer_data_type === 'Alphanumeric') {
                        $message .= "\n💡 *Note: Your answer should contain only letters and numbers.*";
                    }
                }
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
        

        if (!$currentQuestion) {
            return response()->json(['status' => 'error', 'message' => 'Invalid question or session state.']);
        }


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
            if ($actualAnswer === null) {
                // User picked something not in the list
                $this->sendSMS($msisdn, $currentQuestion->data_type_violation_response);
                return; // stop further processing
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