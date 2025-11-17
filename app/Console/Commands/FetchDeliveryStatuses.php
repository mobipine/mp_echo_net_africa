<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use App\Models\SMSInbox;
use Illuminate\Support\Facades\Log;

class FetchDeliveryStatuses extends Command
{
    protected $signature = 'sms:fetch-delivery';
    protected $description = 'Fetch SMS delivery status for all messages with unique_id and pending delivery';

    public function handle()
    {
        $pending = SMSInbox::whereNotNull('unique_id')
            ->where(function ($q) {
                $q->whereNull('delivery_status')
                  ->orWhere('delivery_status', 'pending');
            })
            ->get();

        if ($pending->isEmpty()) {
            $this->info("No pending delivery statuses.");
            return;
        }

        foreach ($pending as $sms) {
            $this->info("Checking delivery for unique_id {$sms->unique_id}");

            $response = Http::get('https://app.bongasms.co.ke/api/fetch-delivery', [
                'apiClientID' => config('bongasms.client_id'),
                'key' => config('bongasms.key'),
                'unique_id' => $sms->unique_id,
            ]);

            if (!$response->successful()) {
                $this->error("API request failed for unique_id {$sms->unique_id}");
                continue;
            }

            $data = $response->json();
            Log::info($data);

            if (($data['status'] ?? null) == 222) {

            $desc = $data['delivery_status_desc'] ?? null;

            // normalize
            if ($desc === 'DeliveredToTerminal') {
                $sms->delivery_status = 'Delivered';
            } else {
                $sms->delivery_status = "failed";
            }

            // also save exact provider description
            $sms->delivery_status_desc = $desc;

            $sms->save();

            $this->info("Updated {$sms->unique_id} → {$sms->delivery_status} ({$desc})");
        }
        else {
                $this->error("Error fetching status for {$sms->unique_id}: " . ($data['status_message'] ?? 'Unknown error'));
            }
        }
    }
}
