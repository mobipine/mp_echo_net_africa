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
use App\Models\SmsCredit;
use App\Services\UjumbeSMS;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * WEBHOOK CONTROLLER - OVERVIEW
 *
 * 1. Receives incoming SMS/WhatsApp messages from multiple providers
 * 2. Supports 3 formats: Custom, WhatsApp, BongaSMS (MSISDN/message)
 * 3. Normalizes phone numbers (254xxx → 0xxx) and messages (lowercase)
 * 4. Deducts credits for every received message (1 credit = 160 chars)
 * 5. Checks if message is a survey trigger word → starts new survey
 * 6. Otherwise, processes as response to active survey progress
 */
class WebHookController extends Controller
{
    /**
     * HANDLE WEBHOOK - INCOMING MESSAGE PROCESSOR
     *
     * 1. Parses payload: detects format (custom/WhatsApp/BongaSMS)
     * 2. Extracts phone_number and message, normalizes both
     * 3. Deducts credits for received message (sends to CreditTransaction)
     * 4. Checks Survey trigger_word: if match → calls startSurvey()
     * 5. Checks for active SurveyProgress: if found → calls processSurveyResponse()
     * 6. Returns JSON response: 'success', 'ignored', or error status
     */
    public function handleWebhook(Request $request)
    {
        Log::info('Webhook received:', $request->all());

        $data = $request->all();

        $msisdn = null;
        $message = null;
        $receivedMessageLength = 0;

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

        // 3. Handle bonga plus format
        elseif (isset($data['MSISDN']) && isset($data['message'])) {
            $msisdn = $data['MSISDN'];
            $message = urldecode(trim(strtolower($data['message'])));
        }

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
            // Deduct credits for trigger word message
            if ($message) {
                $creditsRequired = SMSInbox::calculateCredits($message);
                SmsCredit::subtractCredits(
                    $creditsRequired,
                    'sms_received',
                    "SMS received from {$msisdn}: " . mb_substr($message, 0, 50) . (mb_strlen($message) > 50 ? '...' : '')
                );
                Log::info("Credits deducted for received SMS (trigger): {$creditsRequired} (from {$msisdn})");
            }
            return startSurvey($msisdn, $survey, "sms");
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

        // Process the user's response (credit deduction happens inside)
        if ($progress) {
            return processSurveyResponse($msisdn, $progress, $message, "sms");
        }

        // If not a survey-related message, deduct credits for generic SMS
        if ($message) {
            $creditsRequired = SMSInbox::calculateCredits($message);
            SmsCredit::subtractCredits(
                $creditsRequired,
                'sms_received',
                "SMS received from {$msisdn}: " . mb_substr($message, 0, 50) . (mb_strlen($message) > 50 ? '...' : '')
            );
            Log::info("Credits deducted for received SMS (non-survey): {$creditsRequired} (from {$msisdn})");
        }


        Log::info("No active survey or trigger word found for message: $message");

        return response()->json(['status' => 'ignored', 'message' => 'No active survey or trigger word found.']);
    }
}
