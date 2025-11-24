<?php

namespace App\Console\Commands;

use App\Models\Group;
use App\Models\SMSInbox;
use App\Services\BongaSMS;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class SendSMS extends Command
{
    /**
     * SMS DISPATCH COMMAND - OVERVIEW
     *
     * 1. Runs every 5 seconds, processes 100 pending SMS records at a time
     * 2. Fetches records from sms_inboxes WHERE status='pending' AND channel='sms'
     * 3. For group messages: Expands to individual members, replaces placeholders
     * 4. For individual messages: Sends directly via BongaSMS API
     * 5. Updates status: 'sent' (with unique_id) or 'failed'
     * 6. This is the ONLY place actual SMS sending happens in the app
     */

    public $bonga_sms;

    public function __construct(BongaSMS $bonga_sms)
    {
        parent::__construct();
        $this->bonga_sms = $bonga_sms;
    }

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'dispatch:sms';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Process pending SMS messages from sms_inboxes table and send via BongaSMS';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        // Acquire lock to prevent concurrent executions
        $lock = \Illuminate\Support\Facades\Cache::lock('dispatch-sms-command', 60);

        if (!$lock->get()) {
            Log::info('SendSMS command already running. Skipping...');
            return;
        }

        try {
            // Fetch pending SMSInbox records with row locking
            $pendingSms = SMSInbox::where('status', 'pending')
                ->where('channel', 'sms')
                ->take(100) // Process in batches of 100
                ->lockForUpdate() // Lock rows to prevent concurrent access
                ->get();

            if ($pendingSms->isEmpty()) {
                Log::info("No pending SMS records to send.");
                return;
            }

            $sentCount = 0;
            $failedCount = 0;

            foreach ($pendingSms as $smsInbox) {
                // Mark as processing to prevent duplicate processing
                $smsInbox->update(['status' => 'processing']);

                try {
                    // Handle group messages (expand to individual members)
                    if (!empty($smsInbox->group_ids) && is_array($smsInbox->group_ids)) {
                        foreach ($smsInbox->group_ids as $groupId) {
                            $group = Group::find($groupId);
                            if (!$group) {
                                Log::warning("Group ID {$groupId} not found for SMS inbox {$smsInbox->id}");
                                continue;
                            }

                            foreach ($group->members as $member) {
                                $personalizedMessage = $this->replacePlaceholders($smsInbox->message, $member);
                                $this->sendSingleSMS($member->phone, $personalizedMessage);
                            }
                        }
                        // Mark as sent after processing all group members
                        $smsInbox->update(['status' => 'sent']);
                        $sentCount++;
                    }
                    // Handle individual phone number
                    elseif ($smsInbox->phone_number) {
                        $response = $this->sendSingleSMS($smsInbox->phone_number, $smsInbox->message);

                        if (($response['status'] ?? null) == 222) {
                            $smsInbox->update([
                                'status' => 'sent',
                                'unique_id' => $response['unique_id'] ?? null,
                            ]);
                            $sentCount++;
                            Log::info("SMS sent to {$smsInbox->phone_number}, inbox ID: {$smsInbox->id}");
                        } else {
                            $smsInbox->update(['status' => 'failed']);
                            $failedCount++;
                            Log::warning("Failed to send SMS to {$smsInbox->phone_number}, inbox ID: {$smsInbox->id}");
                        }
                    } else {
                        // No phone number or group_ids
                        $smsInbox->update(['status' => 'failed']);
                        $failedCount++;
                        Log::warning("SMS inbox {$smsInbox->id} has no phone_number or group_ids");
                    }
                } catch (\Exception $e) {
                    // Reset to pending for retry on next run
                    $smsInbox->update(['status' => 'pending']);
                    $failedCount++;
                    Log::error("Exception sending SMS inbox {$smsInbox->id}: {$e->getMessage()}");
                }
            }

            Log::info("SMS batch complete: {$sentCount} sent, {$failedCount} failed");
        } finally {
            $lock->release();
        }
    }

    /**
     * Send a single SMS via BongaSMS service
     *
     * @param string $phoneNumber
     * @param string $message
     * @return array Response from SMS service
     */
    protected function sendSingleSMS(string $phoneNumber, string $message): array
    {
        try {
            $response = $this->bonga_sms->send($phoneNumber, $message);
            return $response;
        } catch (\Exception $e) {
            Log::error("Error sending SMS to {$phoneNumber}: {$e->getMessage()}");
            return ['status' => 'error', 'message' => $e->getMessage()];
        }
    }

    /**
     * Replace placeholders in message with member data
     *
     * @param string $message
     * @param mixed $member
     * @return string
     */
    protected function replacePlaceholders(string $message, $member): string
    {
        return str_replace(
            ['{member}', '{group}', '{id}'],
            [
                $member?->name ?? 'Not recorded',
                $member?->group?->name ?? 'Not recorded',
                $member?->national_id ?? 'Not recorded',
            ],
            $message
        );
    }
}



