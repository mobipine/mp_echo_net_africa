<?php

namespace App\Console\Commands;

use App\Models\Group;
use App\Models\SMSInbox;
use App\Services\UjumbeSMS;
use Illuminate\Console\Command;

class SendSMS extends Command
{

    public $ujumbe_sms;
    //construct the Ujumbe
    public function __construct(UjumbeSMS $ujumbe_sms)
    {
        parent::__construct();
        // You can initialize any services or dependencies here if needed
        $this->ujumbe_sms = $ujumbe_sms;
    }
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'send:sms';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Command description';

    /**
     * Execute the console command.
     */

    public function handle()
    {
        //for this cron job, we will send SMS to all groups that have been selected in the SendSMS page
        $sms_inboxes = SMSInbox::where('status', 'pending')
            ->where('group_ids', '!=', null) // Ensure group_ids is not null
            ->where('group_ids', '!=', []) // Ensure group_ids is not an empty array
            ->take(10) // Limit to 10 SMS inboxes to process at a time
            ->get();
        // dd($sms_inboxes);

        info('Processing pending SMS...');

        if ($sms_inboxes->isEmpty()) {
            // info('No pending SMS to send.');
            // return;


            //check if there are SMSInbox with group_ids that are null or empty but phone_number and member_id is filled
            $sms_inboxes = SMSInbox::where('status', 'pending')

                ->whereNotNull('phone_number')
                ->whereNotNull('member_id')
                ->take(10)
                ->get();

            // dd($sms_inboxes);

            foreach ($sms_inboxes as $sms_inbox) {
                $phone_number = $sms_inbox->phone_number;
                $message = $sms_inbox->message;

                // Send SMS to the phone number
                info("Sending SMS to {$phone_number}");
                $response = $this->sendSMS($phone_number, $message);

                if ($response['status']['code'] == "1008" && $response['status']['type'] == "success") {
                    info("SMS sent to {$phone_number}");
                    // After processing, update the SMS inbox status to 'sent'
                    $sms_inbox->status = 'sent';
                    $sms_inbox->save();
                    info("SMS inbox with ID {$sms_inbox->id} marked as sent.");
                } else {
                    $this->error("Failed to send SMS to {$phone_number}");
                }
            }

            return; // Exit if no groups are found
        }

        foreach ($sms_inboxes as $sms_inbox) {
            $group_ids = $sms_inbox->group_ids; // This should be an array of group IDs
            // dd($group_ids);
            $message = $sms_inbox->message;

            // Loop through each group and send the SMS
            foreach ($group_ids as $group_id) {
                $group = Group::find($group_id);
                // dd($group, $group->members);
                if ($group) {
                    $members = $group->members;
                    if ($members->isEmpty()) {
                        info("No members found in group {$group->name}");
                        continue; // Skip to the next group if no members
                    }
                    foreach ($group->members as $member) {

                        info(">>>>>>>>>>Sending SMS to {$member->phone} in group {$group->name}");

                        $response = $this->sendSMS($member->phone, $message);
                        // dd($response);
                        if ($response['status']['code'] == "1008" && $response['status']['type'] == "success") {
                            info("SMS sent to {$member->phone}");
                        } else {
                            $this->error("Failed to send SMS to {$member->phone}");
                        }
                    }
                }
            }
            // After processing, update the SMS inbox status to 'sent'
            $sms_inbox->status = 'sent';
            $sms_inbox->save();
            info("SMS inbox with ID {$sms_inbox->id} marked as sent.");
        }
    }

    protected function sendSMS(string $phoneNumber, string $message)
    {
        // Use the UjumbeSMS service to send the SMS
        try {
            $res = $this->ujumbe_sms->send($phoneNumber, $message);
            info("SMS sent to {$phoneNumber}");

            // dd($res); // Debugging line to check the response
            return $res; // Return the response from the SMS service
        } catch (\Exception $e) {
            $this->error("Failed to send SMS to {$phoneNumber}: " . $e->getMessage());
            return false; // Return false on failure
        }
    }
}
