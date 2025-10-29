<?php

//WE CREATED A FLOW BUILDER TABLE TO HANDLE THE NEXT QUESTION FLOW BASED ON THE ANSWERS
use Carbon\Carbon;
use App\Models\Member;
use App\Models\MemberEditRequest;
use App\Models\RedoSurvey;
use App\Models\SMSInbox;
use App\Models\Survey;
use App\Models\SurveyProgress;
use App\Models\SurveyResponse;
use Illuminate\Support\Facades\Log;

function getNextQuestion($survey_id, $response = null, $current_question_id = null)
{
    //get the flow data from the survey
    $survey = \App\Models\Survey::find($survey_id);
    $flowData = $survey->flow_data;
    // dd($flowData);

    if (!$flowData) {
        return [
            'status' => 'error',
            'message' => 'No flow data found for this survey.'
        ];
    }

    $elements = $flowData['elements'];
    $edges = $flowData['edges'];

    if(!$elements || !is_array($elements) || count($elements) == 0) {
        return [
            'status' => 'error',
            'message' => 'No elements found in the flow data.'
        ];
    }

    if ($current_question_id == null) {

        //get the start element
        //this will be the element with a label of "Start"
        $startElement = null;
        foreach ($elements as $element) {
            if ($element['label'] == 'Start') {
                $startElement = $element;
                break;
            }
        }

        //get the first question element
        //this will be the element that is connected to the start element (the start element can only have one connection)
        //go to the edges and find the edge that has the start element as the source
        $startElementId = $startElement['id'];
        $startingEdge = null;
        foreach ($edges as $edge) {
            if ($edge['source'] == $startElementId) {
                $startingEdge = $edge;
                break;
            }
        }

        //get the target of the starting edge
        $firstQuestionElementId = $startingEdge['target'];
        $firstQuestionElement = null;
        foreach ($elements as $element) {
            if ($element['id'] == $firstQuestionElementId) {
                $firstQuestionElement = $element;
                break;
            }
        }

        //get the question_id
        $next_question_id = $firstQuestionElement['data']['questionId'];
    } else {
        //get the current question element
        $currentQuestionElement = null;
        foreach ($elements as $element) {
            if ($element['data']['questionId'] == $current_question_id) {
                $currentQuestionElement = $element;
                break;
            }
        }

        // dd($currentQuestionElement);

        //get all edges that have the current question element as the source
        $outgoingEdges = [];
        foreach ($edges as $edge) {
            if ($edge['source'] == $currentQuestionElement['id']) {
                $outgoingEdges[] = $edge;
            }
        }

        //first check the answer strictness
        $answer_strictness = $currentQuestionElement['data']['answerStrictness'];
        if ($answer_strictness == "Open-Ended") {
            // take the first array element of the outgoing edges and get its target
            $nextQuestionElementId = $outgoingEdges[0]['target'];
            $nextQuestionElement = null;
            foreach ($elements as $element) {
                if ($element['id'] == $nextQuestionElementId) {
                    $nextQuestionElement = $element;
                    break;
                }
            }
            //get the question_id
            $next_question_id = $nextQuestionElement['data']['questionId'];
        } else {
            //get the possible answers and the flows they lead to
            $possibleAnswers = $currentQuestionElement['data']['possibleAnswers'];

            //check if the response matches any of the possible answers
            $matchedFlow = null;
            foreach ($possibleAnswers as $answer) {
                if (strcasecmp($answer['answer'], $response) == 0) {
                    $matchedFlow = $answer['linkedFlow'];
                    break;
                }
            }

            // dd($matchedFlow, $possibleAnswers, $response, $outgoingEdges);

            if ($matchedFlow) {
                //get the edge that matches the linked flow
                $matchedEdge = null;
                foreach ($outgoingEdges as $edge) {
                    if ($edge['id'] == $matchedFlow) {
                        $matchedEdge = $edge;
                        break;
                    }
                }

                // dd($matchedEdge, $outgoingEdges);

                //get the target of the matched edge
                $nextQuestionElementId = $matchedEdge['target'];
                $nextQuestionElement = null;
                foreach ($elements as $element) {
                    if ($element['id'] == $nextQuestionElementId) {
                        $nextQuestionElement = $element;
                        break;
                    }
                }

                //get the question_id
                $next_question_id = $nextQuestionElement['data']['questionId'];
            } else {
                //if no match, check if there is a violation response
                $violationResponse = $currentQuestionElement['data']['violationResponse'];
                if ($violationResponse) {
                    //send the violation response
                    return [
                        'status' => 'violation',
                        'message' => $violationResponse
                    ];
                } else {
                    return [
                        'status' => 'error',
                        'message' => 'No matching answer found and no violation response set.'
                    ];
                }
            }
        }
    }
    
    // dd($next_question_id, $next_question, $next_question->question );
    if ($next_question_id) {
        $next_question = \App\Models\SurveyQuestion::find($next_question_id);
        return $next_question;
    }

}

function formartQuestion($firstQuestion,$member,$survey){

    if ($firstQuestion->answer_strictness == "Multiple Choice") {
        $message = "{$firstQuestion->question}\n\n"; 
        
        $numbers = [];
        $index = 1;

        foreach ($firstQuestion->possible_answers as $answer) {
            $message .= "{$index}. {$answer['answer']}\n";
            $numbers[] = $index;
            $index++;
        }

        // Dynamically build the number options string
        if (count($numbers) === 1) {
            $numberText = $numbers[0];
        } elseif (count($numbers) === 2) {
            $numberText = $numbers[0] . " or " . $numbers[1];
        } else {
            $lastNumber = array_pop($numbers);
            $numberText = implode(', ', $numbers) . " or " . $lastNumber;
        }

        $message .= "\nPlease reply with the number {$numberText}.";
        Log::info("The message to be sent is {$message}");
    }
    else {
        $message = $firstQuestion->question;
        if ($firstQuestion->answer_data_type === 'Strictly Number') {
            $message .= "\n *Note: Your answer should be a number.*";
        } elseif ($firstQuestion->answer_data_type === 'Alphanumeric') {
            $message .= "\n *Note: Your answer should contain only letters and numbers.*";
        }
    }
    $loanReceivedMonthId=$loanAmountQuestionId = \App\Models\SurveyQuestion::where('purpose', 'loan_received_date')
    ->value('id');
    $loanMonth = null;

    if ($loanReceivedMonthId) {
        // Retrieve the latest valid response for this question from the member
        $latestDateResponse = \App\Models\SurveyResponse::where('msisdn', $member->phone)
            ->where('question_id', $loanReceivedMonthId)
            ->latest('id') 
            ->first();

        if ($latestDateResponse) {
            $loanDate = $latestDateResponse->survey_response;
            $loanMonth=\Carbon\Carbon::parse($loanDate)->format('F');
        }
    }

    $loanAmountQuestionId = \App\Models\SurveyQuestion::where('purpose', 'loan_amount_received')
    ->value('id');
    $loanAmount = null;

    if ($loanAmountQuestionId) {
        // Retrieve the latest valid response for this question from the member
        $latestAmountResponse = \App\Models\SurveyResponse::where('msisdn', $member->phone)
            ->where('question_id', $loanAmountQuestionId)
            ->latest('id') 
            ->first();

        if ($latestAmountResponse) {
            $loanAmount = $latestAmountResponse->survey_response;
        }
    }
    $latestEdit=MemberEditRequest::where('phone_number',$member->phone)->latest()->first();
    Log::info($latestEdit);

    if($latestEdit){
        $placeholders = [
        '{member}' => $member->name,
        '{group}' => $member->group->name,
        '{id}' => $member->national_id,
        '{gender}'=>$member->gender,
        '{dob}'=> \Carbon\Carbon::parse($member->dob)->format('Y'),
        '{LIP}' => $member?->group?->localImplementingPartner?->name,
        '{month}' => \Carbon\Carbon::now()->monthName,
        '{loan_received_month}' => $loanMonth ?? "N/A",
        '{edit_id}' => $latestEdit->national_id ?? $member->national_id,
        '{edit_year}' => $latestEdit->year_of_birth ?? \Carbon\Carbon::parse($member->dob)->format('Y'),
        '{edit_gender}' => $latestEdit->gender ?? $member->gender,
        '{edit_group}' => $latestEdit->group ?? $member->group->name,
        '{loan_amount_received}' => $loanAmount ?? 'N/A', // Use 'N/A' or 0 if no response found
        '{survey}' => $survey->title,

    ];
    }else{

        $placeholders = [
        '{member}' => $member->name,
        '{group}' => $member->group->name,
        '{id}' => $member->national_id,
        '{gender}'=>$member->gender,
        '{dob}'=> \Carbon\Carbon::parse($member->dob)->format('Y'),
        '{LIP}' => $member?->group?->localImplementingPartner?->name,
        '{month}' => \Carbon\Carbon::now()->monthName,
        '{loan_received_month}' => $loanMonth ?? "N/A",
        '{loan_amount_received}' => $loanAmount ?? 'N/A', // Use 'N/A' or 0 if no response found
        '{survey}' => $survey->title,

    ];
    }
    
    $message = str_replace(
        array_keys($placeholders),
        array_values($placeholders),
        $message
    );
    Log::info($message);
    return $message;
}

function startSurvey($msisdn, Survey $survey,$channel)
{
    Log::info("{$survey->title} has started for {$msisdn} on {$channel}");
    // Get first question
    $firstQuestion = getNextQuestion($survey->id);
    if (!$firstQuestion) {
        return response()->json(['status' => 'error', 'message' => 'Survey has no questions.']);
    }

    // Find member
    $member = Member::where('phone', $msisdn)->first();
    if (!$member) {
        Log::warning("No member found with phone number: {$msisdn}");
        return response()->json(['status' => 'error', 'message' => 'Phone number not recognized.']);
    }

    // --- Cancel all active uncompleted surveys ---
    Log::info("Cancelling all active uncompleted progress for member ID: {$member->id}");
    SurveyProgress::where('member_id', $member->id)
        ->whereNull('completed_at')
        ->update(['status' => 'CANCELLED']);

    // Check existing progress for this survey
    $existingProgress = SurveyProgress::where('member_id', $member->id)
        ->where('survey_id', $survey->id)
        ->first();

    // --- If participant uniqueness is ON and a survey already exists ---
    if ($existingProgress && $survey->participant_uniqueness) {
        // Log redo request
        Log::info("Redo request detected for member {$member->id} on survey {$survey->id}");

        // Find the “Redo Survey” by name
        $redoSurvey = Survey::where('title', 'Redo Survey')->first();

        if (!$redoSurvey) {
            Log::error("Redo Survey not found in database.");
            return response()->json(['status' => 'error', 'message' => 'Redo Survey not found.']);
        }

        // Get first question of the “Redo Survey”
        $redoFirstQuestion = getNextQuestion($redoSurvey->id);
        if ($redoFirstQuestion) {
            $message = formartQuestion($redoFirstQuestion, $member,$survey);
            sendSMS($msisdn, $message,$channel);

            // Log for clarity
            Log::info("Sent redo survey question to {$msisdn}");
        }
        $newProgress = SurveyProgress::create([
        'survey_id' => $redoSurvey->id,
        'member_id' => $member->id,
        'current_question_id' => $redoFirstQuestion->id,
        'last_dispatched_at' => now(),
        'has_responded' => false,
        'source' => 'shortcode'
    ]);

        return response()->json([
            'status' => 'info',
            'message' => 'Redo request logged. Await admin approval.',
        ]);
    }

    // --- Start new progress ---
    $newProgress = SurveyProgress::create([
        'survey_id' => $survey->id,
        'member_id' => $member->id,
        'current_question_id' => $firstQuestion->id,
        'last_dispatched_at' => now(),
        'has_responded' => false,
        'source' => 'shortcode'
    ]);

    // Send first question
    $message = formartQuestion($firstQuestion, $member, $survey);
    sendSMS($msisdn, $message,$channel);

    return response()->json([
        'status' => 'success',
        'message' => 'Survey started.',
        'question_sent' => $firstQuestion->question,
    ]);
}


function processSurveyResponse($msisdn, SurveyProgress $progress, $response, $channel)
{
    
    //THIS FUNCTION SHOULD VALIDATE THE RESPONSE BASED ON THE QUESTION'S DATA TYPE AND STORE IT IF VALID
    $currentQuestion = $progress->currentQuestion;
    $survey = $progress->survey;
    Log::info("{$survey->title} has continuing for {$msisdn} on {$channel}");
    if (!$currentQuestion) {
        return response()->json(['status' => 'error', 'message' => 'Invalid question or session state.']);
    }
    Log::info("The survey progress status is $progress->status");
    $userResponse = trim($response);  
    Log::info("The user responded with ".$userResponse);
    $actualAnswer = getActualAnswer($currentQuestion,$userResponse,$msisdn);

    if ($actualAnswer==null){
        sendSMS($msisdn, $currentQuestion->data_type_violation_response,$channel);
        return;
    }

    if ($currentQuestion->purpose=="loan_received_date"){

         // CRITICAL: Handle the Loan Date (Anchor for Future Scheduling)
        Log::info("Processing loan_date purpose. Answer: " . $actualAnswer);

        // 1. Parse and Validate the Date
        // Assume you have a helper function to safely convert the string answer into a Carbon instance.
        $actualAnswer = parse_member_date_response($actualAnswer);
        if ($actualAnswer instanceof Carbon) {

            Log::info("Successfully parsed and saved loan_date: " . $actualAnswer->toDateString());
            
        } else {
            // Log failure or send a correction SMS back to the user (depending on your service flow)
            Log::warning("Failed to parse loan date answer: " . $actualAnswer);
            // Optionally, resend the question or provide an error message here.
            return response()->json([
                "status" => "failed",
                "message" => "Failed to parse loan date answer"
            ]);
        }
    }
    else{

        $valid=validateResponse($currentQuestion,$msisdn,$response);

        if(!$valid){
            sendSMS($msisdn, $currentQuestion->data_type_violation_response,$channel);
            return;
        }
    }
 
    Log::info("The actual answer to be stored is  $actualAnswer");
    $member = $progress->member;

    if($currentQuestion->purpose !=="regular"){
        Log::info("This is not  regular question ");
        processQuestionPurpose($currentQuestion,$msisdn,$member,$actualAnswer,$survey,$channel);
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
        sendSMS($msisdn, $survey->final_response,$channel);
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

function parse_member_date_response(string $answer): ?Carbon
{
        // VALID FOMARTS FOR DATE RESPONSES

    // 25/09/2025
    // 09-25-2025
    // 2025-09-25
    // 25.9.25
    // 25 09 2025 (Space as a delimiter)
    // 25 September 2025
    // Sept 25th 2025
    // 25 Sep (Assumes the current year or the most recent past year, if the date is in the past)
    // September 25, 2025
    // 25th September
    // Today (Parses to October 6, 2025)
    // Yesterday (Parses to October 5, 2025)
    // A week ago (Parses to September 29, 2025)
    // Last Monday (Parses to September 29, 2025)
    // 3 days ago (Parses to October 3, 2025)

    // 1. Clean and Normalize Input
    $cleanedAnswer = trim(strtolower($answer));
    
    try {
        // 2. Check for Relative Keywords (e.g., Today, Yesterday)
        if ($cleanedAnswer === 'today') {
            return Carbon::today();
        }
        if ($cleanedAnswer === 'yesterday') {
            return Carbon::yesterday();
        }
        
        // The parse() method is smart and often handles many common formats.
        $date = Carbon::parse($answer);
        
        if ($date->isFuture()) {
            Log::warning("Date Parsing failed for answer '{$answer}': Date is in the future.");
            // Throw a specific exception or return null to signal failure/error
            return null; 
        }

        // 5. Success
        return $date->startOfDay(); // Return the date at the start of the day for scheduling

    } catch (\Exception $e) {
        // Log the error for debugging, but return null to signal failure
        Log::warning("Date Parsing failed for answer '{$answer}': " . $e->getMessage());
        return null;
    }
}

function processQuestionPurpose($currentQuestion,$msisdn,$member,$actualAnswer,$survey,$channel){
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
    } elseif ($currentQuestion->purpose == "redo_reason") {
        // Find existing pending redo request for this member
        $redo = \App\Models\RedoSurvey::where('member_id', $member->id)
            ->where('phone_number', $msisdn)
            ->where('action', 'pending')
            ->latest()
            ->first();

        if ($redo) {
            // Update the reason for this redo request
            $redo->update([
                'reason' => $actualAnswer,
            ]);

            Log::info("RedoSurvey reason updated for member {$member->id}");
            // sendSMS($msisdn, "Your redo request has been submitted for review. You will be notified once approved.");
        } else {
            Log::warning("No pending redo request found for member {$member->id} when updating reason.");
           
        }
    }
    elseif ($currentQuestion->purpose=="redo_request"){
         $redoSurvey = Survey::where('title', 'Redo Survey')->first();

        if (!$redoSurvey) {
            Log::error("Redo Survey not found in database.");
            return response()->json(['status' => 'error', 'message' => 'Redo Survey not found.']);
        }

        // Create redo request entry
        if($actualAnswer=="Yes"){
                $redoRecord = RedoSurvey::create([
                'member_id' => $member->id,
                'phone_number' => $msisdn,
                'survey_to_redo_id' => $survey->id,
                'reason' => 'User triggered redo for a unique survey.',
                'action' => 'pending',
                'channel' => $channel,
            ]);
        }  
    }

}
function getActualAnswer($currentQuestion, $userResponse, $msisdn)
{
    $actualAnswer = null;

    if ($currentQuestion->answer_strictness === "Multiple Choice") {
        $index = 1;

        foreach ($currentQuestion->possible_answers as $answer) {
            // Check if user typed the number
            if ((string)$index === trim($userResponse)) {
                $actualAnswer = $answer['answer']; // select answer based on number
                break;
            }

            // Also allow typing the full answer text
            if (strcasecmp($answer['answer'], $userResponse) === 0) {
                $actualAnswer = $answer['answer'];
                break;
            }

            $index++;
        }

        if ($actualAnswer === null) {
            // User gave something invalid (neither number nor valid answer)
            return $actualAnswer; // stop further processing
        }
    } else {
        // For non-multiple-choice, store response directly
        $actualAnswer = trim($userResponse);
    }

    return $actualAnswer;
}


function validateResponse($currentQuestion,$msisdn,$response){

    if ($currentQuestion->answer_data_type === 'Strictly Number') {
        // Remove commas and extra spaces
        $normalizedResponse = str_replace(',', '', trim($response));

        if (!is_numeric($normalizedResponse)) {
            return false;
        }
    }
    if ($currentQuestion->answer_data_type === 'Alphanumeric' && !ctype_alnum(str_replace(' ', '', $response))) {
        
        Log::info("the response violates the questions strictness");
        return false;
    }
    return true;
}

function formatPhoneForWhatsApp(string $phoneNumber): string
{
    $cleanNumber = preg_replace('/\D/', '', $phoneNumber);
    
    // Convert 0... to 254...
    if (substr($cleanNumber, 0, 1) === "0") {
        $cleanNumber = "254" . substr($cleanNumber, 1);
    }
    return $cleanNumber;
}


function normalizePhoneNumber(string $phoneNumber): string
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


function sendSMS($msisdn, $message,$channel)
{
    try {
        SMSInbox::create([
            'phone_number' => $msisdn, // Store the phone number in group_ids for tracking
            'message' => $message,
            'channel' => $channel,
        ]);
    } catch (\Exception $e) {
        Log::error("Failed to create SMSInbox record for $msisdn: " . $e->getMessage());
    }
}