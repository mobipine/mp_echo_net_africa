<?php

namespace App\Jobs;

use App\Models\SMSInbox;
use App\Services\BongaSMS;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SendMemberSMSJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 60;

    protected int $inboxId;
    protected ?int $memberId;
    protected string $phoneNumber;
    protected string $message;

    /**
     * @param int $inboxId SMSInbox ID
     * @param string $phoneNumber Recipient phone number
     * @param string $message SMS message
     * @param int|null $memberId Optional member ID
     */
    public function __construct(int $inboxId, string $phoneNumber, string $message, ?int $memberId = null)
    {
        $this->inboxId = $inboxId;
        $this->phoneNumber = $phoneNumber;
        $this->message = $message;
        $this->memberId = $memberId;
    }

    public function handle(BongaSMS $bonga)
    {
        $smsInbox = SMSInbox::find($this->inboxId);

        // Defensive check: already sent or not found
        if (!$smsInbox || $smsInbox->status === 'sent') {
            Log::info("SMSInbox ID {$this->inboxId} already sent or not found. Skipping.");
            return;
        }

        if (!$this->phoneNumber) {
            Log::warning("No phone number for inbox {$this->inboxId}");
            $smsInbox->update(['status' => 'failed']);
            return;
        }

        try {
            $response = $bonga->send($this->phoneNumber, $this->message);

            if (($response['status'] ?? null) == 222) {
                Log::info("SMS sent to {$this->phoneNumber}");

                // Update SMSInbox with status and unique_id
                $smsInbox->update([
                    'status'    => 'sent',
                    'unique_id' => $response['unique_id'] ?? null,
                ]);
                Log::info("SMSInbox ID {$smsInbox->id} marked as sent with unique_id {$response['unique_id']}");

            }elseif(($response['status_message'] ?? null) == "insufficient credit"){
                 Log::warning("Insufficient credit cannot send this sms");
                $smsInbox->update(['status' => 'failed(insufficient credit)']);

            }else {
                Log::warning("Failed to send SMS to {$this->phoneNumber}");
                $smsInbox->update(['status' => "failed({$response['status_message']})"]);
            }
        } catch (\Exception $e) {
            Log::error("Exception sending SMS to {$this->phoneNumber}: {$e->getMessage()}");
            // Reset to pending for retry
            $smsInbox->update(['status' => 'pending']);
            throw $e; // allows retry
        }
    }
}
