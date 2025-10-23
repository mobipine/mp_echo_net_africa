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
            $normalizedPhone = normalizePhoneNumber($messageData['from']);
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
            return startSurvey($phoneNumber, $survey,"whatsapp");
        }

        // Check for active survey progress
        $progress = $this->findActiveSurveyProgress($phoneNumber);

        if ($progress) {
            return processSurveyResponse($phoneNumber, $progress, $message,"whatsapp");
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
                formatPhoneForWhatsApp($phoneNumber),
                $response
            );
        } catch (\Exception $e) {
            Log::error("Failed to send non-text message response: " . $e->getMessage());
        }

        return response()->json(['status' => 'processed', 'message' => 'Non-text message handled']);
    }    
}