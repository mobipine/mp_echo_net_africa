<?php

namespace App\Services;

use App\Models\SMSInbox;
use App\Models\SmsCredit;
use Illuminate\Support\Facades\Log;

class SmsDispatcher
{
    public function __construct(protected BongaSMS $bonga)
    {
    }

    /**
     * Send a single pending SMSInbox row immediately via BongaSMS.
     *
     * Safe to call inline (from the inbound webhook, for instant delivery) or from the
     * dispatch:sms batch. It atomically claims the row (pending -> processing) before
     * sending, so the same message can never be sent twice even if the webhook and the
     * scheduled batch hit it at the same moment.
     *
     * Returns 'sent', 'failed', or 'skipped' (already claimed / not pending / no credits).
     * On failure the row is marked 'failed' (terminal, matching dispatch:sms) so an
     * ambiguous gateway error never causes a duplicate send.
     */
    public function sendOne(SMSInbox $sms): string
    {
        if ($sms->channel !== 'sms') {
            return 'skipped';
        }

        if (SmsCredit::getBalance() <= 0) {
            Log::warning("Insufficient SMS credits; not sending inbox {$sms->id}.");
            return 'skipped';
        }

        // Atomic claim: only the first caller flips pending -> processing.
        $claimed = SMSInbox::where('id', $sms->id)
            ->where('status', 'pending')
            ->update(['status' => 'processing']);

        if ($claimed === 0) {
            return 'skipped'; // another process already took this row
        }

        $sms->refresh();

        try {
            $response = $this->bonga->send($sms->phone_number, $sms->message);

            if (($response['status'] ?? null) == 222) {
                $sms->update([
                    'status' => 'sent',
                    'unique_id' => $response['unique_id'] ?? null,
                    'failure_reason' => null,
                ]);

                SmsCredit::subtractCredits(
                    $sms->credits_count,
                    'sms_sent',
                    "SMS sent to {$sms->phone_number}",
                    $sms->id
                );

                Log::info("SMS sent to {$sms->phone_number}, inbox ID: {$sms->id}, credits deducted: {$sms->credits_count}");
                return 'sent';
            }

            // Non-success response: terminal failure (matches dispatch:sms, which never
            // retries 'failed' rows) so we don't risk a duplicate send on an ambiguous reply.
            $reason = $response['status_message'] ?? 'Unknown response from BongaSMS';
            $sms->update([
                'status' => 'failed',
                'retries' => $sms->retries + 1,
                'failure_reason' => $reason,
            ]);
            Log::warning("SMS send failed for inbox {$sms->id} ({$sms->phone_number}): {$reason}");
            return 'failed';
        } catch (\Throwable $e) {
            $sms->update([
                'status' => 'failed',
                'retries' => $sms->retries + 1,
                'failure_reason' => 'Exception: ' . $e->getMessage(),
            ]);
            Log::error("SMS send exception for inbox {$sms->id}: " . $e->getMessage());
            return 'failed';
        }
    }
}
