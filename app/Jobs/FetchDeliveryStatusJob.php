<?php

namespace App\Jobs;

use App\Models\SMSInbox;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class FetchDeliveryStatusJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $smsId;

    // Optional: Retry 3 times if failed
    public $tries = 3;
    public $timeout = 60; // seconds

    public function __construct($smsId)
    {
        $this->smsId = $smsId;
    }

    public function handle()
    {
        $sms = SMSInbox::find($this->smsId);
        if (!$sms) return;

        // Skip if unique_id is missing
        if (!$sms->unique_id) return;

        $response = Http::timeout(10)->get('https://app.bongasms.co.ke/api/fetch-delivery', [
            'apiClientID' => config('bongasms.client_id'),
            'key' => config('bongasms.key'),
            'unique_id' => $sms->unique_id,
        ]);

        if (!$response->successful()) {
            Log::error("Failed to fetch delivery for SMS ID {$sms->id}");
            return;
        }

        $data = $response->json();
        Log::info("Delivery fetch for SMS ID {$sms->id}: " . json_encode($data));

        if (($data['status'] ?? null) == 222) {

                $desc = $data['delivery_status_desc'] ?? null;

                // If NULL → leave as pending
                if ($desc === null) {
                    // $this->info("Status still pending for {$sms->unique_id} (NULL response) → not updating");
                    return; // skip updating
                }

                // Normalize based on provider response
                if ($desc === 'DeliveredToTerminal') {
                    $sms->delivery_status = 'Delivered';
                } else {
                    $sms->delivery_status = 'failed';
                }

                // always store provider description
                $sms->delivery_status_desc = $desc;

                $sms->save();
                Log::info("Delivery updated for sms id $this->smsId");
                // $this->info("Updated {$sms->unique_id} → {$sms->delivery_status} ({$desc})");

            } else {
                // $this->error("Error fetching status for {$sms->unique_id}: " . ($data['status_message'] ?? 'Unknown error'));
            }
    }
}
