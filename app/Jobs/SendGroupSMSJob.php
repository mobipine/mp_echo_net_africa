<?php

namespace App\Jobs;

use App\Models\Group;
use App\Models\SMSInbox;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SendGroupSMSJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 300;

    protected int $smsInboxId;

    public function __construct(int $smsInboxId)
    {
        $this->smsInboxId = $smsInboxId;
    }

    public function handle()
    {
        $smsInbox = SMSInbox::find($this->smsInboxId);
        if (!$smsInbox) {
            Log::warning("SMSInbox ID {$this->smsInboxId} not found");
            return;
        }

        $message = $smsInbox->message;

        // If group_ids exists, send to each member
        if (!empty($smsInbox->group_ids) && is_array($smsInbox->group_ids)) {
            foreach ($smsInbox->group_ids as $groupId) {
                $group = Group::find($groupId);
                if (!$group) continue;

                foreach ($group->members as $member) {
                    $personalizedMessage = $this->replacePlaceholders($message, $member);
                    SendMemberSMSJob::dispatch($smsInbox->id, $member->phone, $personalizedMessage, $member->id)
                        ->onQueue('sms');
                }
            }
        } else {
            Log::info("Sending sms");
            // Send directly to phone number if no group
            if ($smsInbox->phone_number) {
                SendMemberSMSJob::dispatch($smsInbox->id, $smsInbox->phone_number, $message, $smsInbox->member_id)
                    ->onQueue('sms');
            }
        }
    }

    protected function replacePlaceholders(string $message, $member)
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
