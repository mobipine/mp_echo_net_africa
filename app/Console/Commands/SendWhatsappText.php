<?php

namespace App\Console\Commands;

use App\Models\Group;
use App\Models\SMSInbox;
use App\Services\WhatsAppService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class SendWhatsappText extends Command
{
    protected $whatsappService;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'dispatch:whatsapp-text';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Send WhatsApp messages to pending SMS inbox entries';

    /**
     * Construct the command.
     */
    public function __construct(WhatsAppService $whatsappService)
    {
        parent::__construct();
        $this->whatsappService = $whatsappService;
    }

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Starting WhatsApp message processing...');
        
        // Process group messages first
        $this->processGroupMessages();
        
        // Process individual messages
        $this->processIndividualMessages();
        
        $this->info('WhatsApp message processing completed.');
    }

    /**
     * Process messages with group IDs
     */
    protected function processGroupMessages()
    {
        $smsInboxes = SMSInbox::where('status', 'pending')
            ->where('channel','whatsapp')
            ->where(function ($query) {
                $query->whereNotNull('group_ids')
                      ->where('group_ids', '!=', '[]');
            })
            ->take(10)
            ->get();

        if ($smsInboxes->isEmpty()) {
            $this->info('No group messages found to process.');
            return;
        }

        $this->info("Processing {$smsInboxes->count()} group messages...");

        foreach ($smsInboxes as $smsInbox) {
            try {
                $this->processSingleGroupMessage($smsInbox);
                $smsInbox->update(['status' => 'sent']);
                $this->info("SMS inbox with ID {$smsInbox->id} marked as sent.");
            } catch (\Exception $e) {
                Log::error("Failed to process group message {$smsInbox->id}: " . $e->getMessage());
                $smsInbox->update(['status' => 'failed']);
                $this->error("Failed to process SMS inbox {$smsInbox->id}: " . $e->getMessage());
            }
        }
    }

    /**
     * Process individual messages (no groups)
     */
    protected function processIndividualMessages()
    {
        $smsInboxes = SMSInbox::where('status', 'pending')
            ->where('channel','whatsapp')
            ->where(function ($query) {
                $query->whereNull('group_ids')
                      ->orWhere('group_ids', '=', '[]');
            })
            ->whereNotNull('phone_number')
            ->take(10)
            ->get();

        if ($smsInboxes->isEmpty()) {
            $this->info('No individual messages found to process.');
            return;
        }

        $this->info("Processing {$smsInboxes->count()} individual messages...");

        foreach ($smsInboxes as $smsInbox) {
            try {
                $this->processSingleIndividualMessage($smsInbox);
                $smsInbox->update(['status' => 'sent']);
                $this->info("SMS inbox with ID {$smsInbox->id} marked as sent.");
            } catch (\Exception $e) {
                Log::error("Failed to process individual message {$smsInbox->id}: " . $e->getMessage());
                $smsInbox->update(['status' => 'failed']);
                $this->error("Failed to process SMS inbox {$smsInbox->id}: " . $e->getMessage());
            }
        }
    }

    /**
     * Process a single group message
     */
    protected function processSingleGroupMessage(SMSInbox $smsInbox)
    {
        $groupIds = $smsInbox->group_ids;
        $message = $smsInbox->message;
        $totalSent = 0;
        $totalFailed = 0;

        foreach ($groupIds as $groupId) {
            $group = Group::with('members')->find($groupId);
            
            if (!$group) {
                $this->warn("Group with ID {$groupId} not found.");
                continue;
            }

            if ($group->members->isEmpty()) {
                $this->info("No members found in group {$group->name}");
                continue;
            }

            $this->info("Processing group: {$group->name} with {$group->members->count()} members");

            foreach ($group->members as $member) {
                if ($this->sendWhatsAppMessage($member->phone, $message)) {
                    $totalSent++;
                    $this->info("✓ WhatsApp sent to {$member->phone}");
                } else {
                    $totalFailed++;
                    $this->error("✗ Failed to send to {$member->phone}");
                }
                
                // Add delay to avoid rate limiting (WhatsApp allows ~80 messages/second)
                usleep(100000); // 100ms delay between messages
            }
        }

        $this->info("Group message {$smsInbox->id}: {$totalSent} sent, {$totalFailed} failed");
    }

    /**
     * Process a single individual message
     */
    protected function processSingleIndividualMessage(SMSInbox $smsInbox)
    {
        $phoneNumber = $this->formatPhoneNumber($smsInbox->phone_number);
        $message = $smsInbox->message;

        $this->info("Sending WhatsApp to individual: {$phoneNumber}");

        if ($this->sendWhatsAppMessage($phoneNumber, $message)) {
            $this->info("✓ WhatsApp sent to {$phoneNumber}");
        } else {
            throw new \Exception("Failed to send WhatsApp to {$phoneNumber}");
        }
    }

    /**
     * Format phone number to WhatsApp format
     */
    protected function formatPhoneNumber(string $phoneNumber): string
    {
        // Remove any non-digit characters
        $phoneNumber = preg_replace('/\D/', '', $phoneNumber);
        
        // Handle Kenyan numbers (starting with 0)
        if (substr($phoneNumber, 0, 1) === "0") {
            $phoneNumber = "254" . substr($phoneNumber, 1);
        }
        
        // Ensure it has country code
        if (substr($phoneNumber, 0, 3) !== "254") {
            $phoneNumber = "254" . ltrim($phoneNumber, '0');
        }

        Log::info("Formatted WhatsApp Number: " . $phoneNumber);
        return $phoneNumber;
    }

    /**
     * Send WhatsApp message
     */
    protected function sendWhatsAppMessage(string $phoneNumber, string $message): bool
    {
        try {
            $formattedNumber = $this->formatPhoneNumber($phoneNumber);
            
            $response = $this->whatsappService->sendTextMessage($formattedNumber, $message);
            
            // Log successful send
            Log::info('WhatsApp message sent successfully', [
                'to' => $formattedNumber,
                'message' => $message
            ]);
            
            return true;
            
        } catch (\Exception $e) {
            Log::error('WhatsApp message sending failed', [
                'to' => $phoneNumber,
                'error' => $e->getMessage()
            ]);
            
            return false;
        }
    }
}