<?php

namespace App\Console\Commands;

use App\Models\Group;
use App\Models\SMSInbox;
use App\Models\SmsCredit;
use App\Services\BongaSMS;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class SendSMS extends Command
{
/**
 * SMS DISPATCH COMMAND - OVERVIEW
 *
 * 1. Runs every 5 seconds, processes 100 SMS records at a time
 * 2. Fetches: pending (not yet tried) OR failed (retries<3) from sms_inboxes
 * 3. For group messages: Expands to individual members, replaces placeholders
 * 4. Sends via BongaSMS API, updates: 'sent' (success) or 'failed' (error)
 * 5. Failed messages (status 666): retry up to 3 times, then permanently failed
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
        // Check if survey messages are enabled
        if (!config('survey_settings.messages_enabled', true)) {
            Log::info('Survey messages are disabled via config. Skipping SMS sending.');
            $this->info('Survey messages are disabled via config. Skipping SMS sending.');
            return;
        }

        // Acquire lock to prevent concurrent executions
        $lock = \Illuminate\Support\Facades\Cache::lock('dispatch-sms-command', 60);

        if (!$lock->get()) {
            Log::info('SendSMS command already running. Skipping...');
            return;
        }

        try {
            // Check credit balance
            $creditBalance = SmsCredit::getBalance();
            if ($creditBalance <= 0) {
                Log::warning("Insufficient SMS credits. Current balance: {$creditBalance}. Sending stopped.");
                return;
            }

            // Fetch pending and failed (with retries < 3) SMSInbox records
            $pendingSms = SMSInbox::where('channel', 'sms')
                ->where(function($query) {
                    $query->where('status', 'pending'); // New messages not yet tried
                        //   ->orWhere(function($q) {
                        //       $q->where('status', 'failed')
                        //         ->where('retries', '<', 3); // Failed messages eligible for retry
                        //   })

                })
                ->take(100) // Process in batches of 100
                ->lockForUpdate() // Lock rows to prevent concurrent access
                ->get();

            if ($pendingSms->isEmpty()) {
                Log::info("No pending SMS records to send.");
                return;
            }

            $sentCount = 0;
            $failedCount = 0;
            $skippedNoCredits = 0;

            foreach ($pendingSms as $smsInbox) {
                // Mark as processing to prevent duplicate processing
                $smsInbox->update(['status' => 'processing']);

                try {
                    // Handle individual phone number
                    if ($smsInbox->phone_number) {
                        // Check if we have enough credits for this message
                        if (SmsCredit::getBalance() <= 0) {
                            Log::warning("Insufficient credits to send SMS inbox {$smsInbox->id}. Stopping batch.");
                            $skippedNoCredits++;
                            break; // Stop processing this batch
                        }

                        $response = $this->sendSingleSMS($smsInbox->phone_number, $smsInbox->message);

                        // SUCCESS (status 222)
                        if (($response['status'] ?? null) == 222) {
                            $smsInbox->update([
                                'status' => 'sent',
                                'unique_id' => $response['unique_id'] ?? null,
                                'failure_reason' => null,
                            ]);

                            // Deduct credits
                            SmsCredit::subtractCredits(
                                $smsInbox->credits_count,
                                'sms_sent',
                                "SMS sent to {$smsInbox->phone_number}",
                                $smsInbox->id
                            );

                            $sentCount++;
                            Log::info("SMS sent to {$smsInbox->phone_number}, inbox ID: {$smsInbox->id}, credits deducted: {$smsInbox->credits_count}");
                        }
                        // ERROR (status 666) - Mark as failed and increment retries
                        elseif (($response['status'] ?? null) == 666) {
                            $failureReason = $response['status_message'] ?? 'Unknown error from BongaSMS';
                            $newRetryCount = $smsInbox->retries + 1;

                            // Always mark as failed, use retries to determine if eligible for retry
                            $smsInbox->update([
                                'status' => 'failed',
                                'retries' => $newRetryCount,
                                'failure_reason' => $failureReason,
                            ]);

                            if ($newRetryCount < 3) {
                                Log::warning("SMS failed (666) to {$smsInbox->phone_number}, inbox ID: {$smsInbox->id}, attempt {$newRetryCount}/3. Will retry. Reason: {$failureReason}");
                            } else {
                                $failedCount++;
                                Log::error("SMS permanently failed to {$smsInbox->phone_number}, inbox ID: {$smsInbox->id}. Max retries (3) reached. Reason: {$failureReason}");
                            }
                        }
                        // Unknown response
                        else {
                            $failureReason = $response['status_message'] ?? 'Unknown response from BongaSMS API';
                            $smsInbox->update([
                                'status' => 'failed',
                                'retries' => $smsInbox->retries + 1,
                                'failure_reason' => $failureReason,
                            ]);
                            $failedCount++;
                            Log::warning("SMS failed with unknown status to {$smsInbox->phone_number}, inbox ID: {$smsInbox->id}. Reason: {$failureReason}");
                        }
                    } else {
                        // No phone number or group_ids - mark as failed permanently
                        $smsInbox->update([
                            'status' => 'failed',
                            'retries' => 3, // Set to max retries so it won't be retried
                            'failure_reason' => 'No phone_number or group_ids provided',
                        ]);
                        $failedCount++;
                        Log::warning("SMS inbox {$smsInbox->id} has no phone_number or group_ids. Marked as permanently failed.");
                    }
                } catch (\Exception $e) {
                    // Mark as failed on exception and increment retries
                    $newRetryCount = $smsInbox->retries + 1;
                    $smsInbox->update([
                        'status' => 'failed',
                        'retries' => $newRetryCount,
                        'failure_reason' => 'Exception: ' . $e->getMessage(),
                    ]);

                    if ($newRetryCount < 3) {
                        Log::error("Exception sending SMS inbox {$smsInbox->id}, attempt {$newRetryCount}/3. Will retry. Exception: {$e->getMessage()}");
                    } else {
                        $failedCount++;
                        Log::error("SMS inbox {$smsInbox->id} permanently failed after 3 attempts. Exception: {$e->getMessage()}");
                    }
                }
            }

            $currentBalance = SmsCredit::getBalance();
            Log::info("SMS batch complete: {$sentCount} sent, {$failedCount} permanently failed, {$skippedNoCredits} skipped (no credits). Current credit balance: {$currentBalance}");
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



