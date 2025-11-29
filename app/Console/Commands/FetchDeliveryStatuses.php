<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\SMSInbox;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class FetchDeliveryStatuses extends Command
{
    protected $signature = 'sms:fetch-delivery';
    protected $description = 'Fetch SMS delivery statuses for pending messages from BongaSMS';

    public function handle()
    {
        Log::info("Fetching delivery statuses for pending SMS...");

        $processedCount = 0;
        $updatedCount = 0;

        SMSInbox::whereNotNull('unique_id')
            ->where(function ($q) {
                $q->whereNull('delivery_status')
                  ->orWhere('delivery_status', 'pending');
            })
            ->take(500)
            ->chunk(500, function ($smsBatch) use (&$processedCount, &$updatedCount) {

                SMSInbox::whereIn('id', $smsBatch->pluck('id'))
                    ->update(['delivery_status' => 'processing']);

                Log::info("Marked " . count($smsBatch) . " SMS as processing.");

                foreach ($smsBatch as $sms) {
                    $processedCount++;

                    try {
                        $response = Http::timeout(10)->get('https://app.bongasms.co.ke/api/fetch-delivery', [
                            'apiClientID' => config('bongasms.client_id'),
                            'key' => config('bongasms.key'),
                            'unique_id' => $sms->unique_id,
                        ]);

                        if (!$response->successful()) {
                            Log::error("Failed API call for SMS {$sms->id}");
                            continue;
                        }

                        $data = $response->json();

                        if (($data['status'] ?? null) == 222) {
                            $desc = $data['delivery_status_desc'] ?? null;

                            if ($desc === null) {
                                continue; // still pending on provider
                            }

                            if ($desc === 'DeliveredToTerminal') {
                                $sms->delivery_status = 'Delivered';
                            } else {
                                $sms->delivery_status = 'failed';
                            }

                            $sms->delivery_status_desc = $desc;
                            $sms->save();

                            $updatedCount++;
                            Log::info("Updated SMS {$sms->id} to {$sms->delivery_status}");
                        } else {
                            Log::warning("API error for SMS {$sms->id} : " . ($data['status_message'] ?? 'Unknown error'));
                        }
                    } catch (\Exception $e) {
                        Log::error("Exception for SMS {$sms->id}: " . $e->getMessage());
                    }

                    usleep(50000); // 50ms
                }
            });

        Log::info("Delivery status fetch complete: {$processedCount} processed, {$updatedCount} updated");
    }
}
