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
            return startSurvey($msisdn, $survey);
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
           
            return processSurveyResponse($msisdn, $progress, $message);
              
        }

        
        Log::info("No active survey or trigger word found for message: $message");

        return response()->json(['status' => 'ignored', 'message' => 'No active survey or trigger word found.']);
    }
    
}
