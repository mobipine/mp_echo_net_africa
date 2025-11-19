<?php

namespace App\Console\Commands;

use App\Jobs\SendGroupSMSJob;
use App\Models\Group;
use App\Models\SMSInbox;
use App\Services\BongaSMS;
use App\Services\UjumbeSMS;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class SendSMS extends Command
{

    public $bonga_sms;
    //construct the Ujumbe
    public function __construct(BongaSMS $bonga_sms)
    {
        parent::__construct();
        // You can initialize any services or dependencies here if needed
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
    protected $description = 'Command description';

    /**
     * Execute the console command.
     */

    public function handle()
    {
        // Fetch pending SMSInbox records, limit to avoid memory issues
        $pendingSms = SMSInbox::where('status', 'pending')
            ->where('channel', 'sms')
            ->take(100) // dispatch in batches of 100, adjust as needed
            ->get();

        if ($pendingSms->isEmpty()) {
            $this->info("No pending SMSInbox records to dispatch.");
            return;
        }

        foreach ($pendingSms as $smsInbox) {
            SendGroupSMSJob::dispatch($smsInbox->id)->onQueue('sms');
            Log::info("Dispatched SMSInbox ID {$smsInbox->id} to queue.");
        }

        $this->info("Dispatched {$pendingSms->count()} SMSInbox records to the SMS queue.");
    
    }
}



