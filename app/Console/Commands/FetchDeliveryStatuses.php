<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\SMSInbox;
use App\Jobs\FetchDeliveryStatusJob;

class FetchDeliveryStatuses extends Command
{
    protected $signature = 'sms:fetch-delivery';
    protected $description = 'Queue jobs to fetch SMS delivery statuses for pending messages';

    public function handle()
    {
        $this->info("Fetching pending SMS for delivery status...");

        SMSInbox::whereNotNull('unique_id')
            ->where(function ($q) {
                $q->whereNull('delivery_status')
                  ->orWhere('delivery_status', 'pending');
            })
            ->chunk(500, function ($smsBatch) {
                foreach ($smsBatch as $sms) {
                    FetchDeliveryStatusJob::dispatch($sms->id)->onQueue('sms-delivery');
                }
            });

        $this->info("All pending SMS have been dispatched to the queue.");
    }
}
