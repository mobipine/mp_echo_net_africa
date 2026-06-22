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
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
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

        if (!$msisdn) {
            Log::warning('Webhook had no resolvable phone number. Ignoring.');
            return response()->json(['status' => 'ignored', 'message' => 'No phone number.']);
        }

        $uniqueId = $this->extractMessageId($data);
        $lockKey = 'survey-inbound:' . normalizePhoneNumber($msisdn);

        // Serialize all inbound processing per phone number. Duplicate or concurrent
        // deliveries (gateway retries, members double-texting) must never double-charge
        // credits, create duplicate responses, or send the next question twice. The survey
        // poller takes this same lock before it advances a member, so the two can never act
        // on one member at the same time.
        try {
            return Cache::lock($lockKey, 20)->block(8, function () use ($msisdn, $message, $uniqueId) {
                // Idempotency: drop a delivery we have already fully processed.
                if ($uniqueId && Cache::has('inbound-msg:' . $uniqueId)) {
                    Log::info("Duplicate inbound message {$uniqueId} from {$msisdn} ignored.");
                    return response()->json(['status' => 'ignored', 'message' => 'Duplicate message.']);
                }

                $result = $this->routeInbound($msisdn, $message);

                // Mark processed as soon as the response has committed, so a duplicate
                // delivery is dropped even if the best-effort send below fails. (If
                // routeInbound itself threw, the transaction rolled back and we never reach
                // here, so a retry reprocesses cleanly.)
                if ($uniqueId) {
                    Cache::put('inbound-msg:' . $uniqueId, true, now()->addHours(6));
                }

                // Push anything we just queued (e.g. the next survey question) straight to
                // the gateway so delivery doesn't wait for the next dispatch:sms tick. The
                // scheduled dispatch:sms remains the fallback for anything not flushed here.
                $this->flushPendingSms($msisdn);

                return $result;
            });
        } catch (LockTimeoutException $e) {
            Log::warning("Could not acquire inbound lock for {$msisdn} within 8s; not processed: {$message}");
            return response()->json(['status' => 'busy', 'message' => 'Another message is being processed.']);
        }
    }

    /**
     * Route an inbound message: survey trigger word, active-survey response, or generic SMS.
     * Always runs inside the per-phone lock acquired by handleWebhook().
     */
    private function routeInbound(string $msisdn, ?string $message)
    {
        // Check if the message is a trigger word for any survey
        $survey = Survey::where('trigger_word', $message)->first();

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

    /**
     * Send any SMS rows we just queued for this member straight to the gateway, so the
     * next question is delivered immediately instead of waiting for the next dispatch:sms
     * tick. Best-effort: SmsDispatcher atomically claims each row, so this never collides
     * with the scheduled batch, and any row it doesn't send stays for the fallback.
     */
    private function flushPendingSms(string $msisdn): void
    {
        // Purely a delivery optimization — never let it break the (already committed)
        // webhook, or a retry would reprocess the reply against the advanced survey state.
        try {
            $member = Member::where('phone', $msisdn)->first();
            if (!$member) {
                return;
            }

            $pending = SMSInbox::where('member_id', $member->id)
                ->where('channel', 'sms')
                ->where('status', 'pending')
                ->orderBy('id')
                ->get();

            if ($pending->isEmpty()) {
                return;
            }

            $dispatcher = app(\App\Services\SmsDispatcher::class);
            foreach ($pending as $sms) {
                $dispatcher->sendOne($sms);
            }
        } catch (\Throwable $e) {
            Log::error("Inline SMS flush failed for {$msisdn}: " . $e->getMessage() . ". Falling back to dispatch:sms.");
        }
    }

    /**
     * Extract a stable provider message id, used for idempotency to drop duplicate
     * deliveries. Returns null when the payload carries no usable id.
     */
    private function extractMessageId(array $data): ?string
    {
        $id = $data['traceUniqueID']
            ?? $data['tid']
            ?? $data['linkID']
            ?? ($data['messages'][0]['id'] ?? null)
            ?? $data['id']
            ?? $data['message_id']
            ?? null;

        return $id !== null ? (string) $id : null;
    }
}
