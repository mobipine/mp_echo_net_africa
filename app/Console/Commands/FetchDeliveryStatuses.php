<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\SMSInbox;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class FetchDeliveryStatuses extends Command
{
    /**
     * SMS DELIVERY STATUS FETCHER - OVERVIEW
     *
     * 1. Runs every 5 seconds, checks 100 SMS records with unique_id but no delivery status
     * 2. Queries BongaSMS API for each message: fetch-delivery endpoint
     * 3. Updates delivery_status: 'Delivered' (if DeliveredToTerminal) or 'failed'
     * 4. Rate limiting: 50ms delay between API calls to prevent throttling
     * 5. Graceful error handling: continues on failure, retries next run
     */

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
            ->take(100) // Process in batches to avoid overwhelming the API
            ->chunk(100, function ($smsBatch) use (&$processedCount, &$updatedCount) {
                foreach ($smsBatch as $sms) {
                    $processedCount++;

                    try {
                        $response = Http::timeout(10)->get('https://app.bongasms.co.ke/api/fetch-delivery', [
                            'apiClientID' => config('bongasms.client_id'),
                            'key' => config('bongasms.key'),
                            'unique_id' => $sms->unique_id,
                        ]);

                        if (!$response->successful()) {
                            Log::error("Failed to fetch delivery for SMS ID {$sms->id}, unique_id: {$sms->unique_id}");
                            continue;
                        }

                        $data = $response->json();

                        if (($data['status'] ?? null) == 222) {
                            $desc = $data['delivery_status_desc'] ?? null;

                            // If NULL, status is still pending - skip update
                            if ($desc === null) {
                                continue;
                            }

                            // Normalize delivery status
                            if ($desc === 'DeliveredToTerminal') {
                                $sms->delivery_status = 'Delivered';
                            } else {
                                $sms->delivery_status = 'failed';
                            }

                            // Store provider description
                            $sms->delivery_status_desc = $desc;
                            $sms->save();

                            $updatedCount++;
                            Log::info("Delivery status updated for SMS ID {$sms->id}: {$sms->delivery_status} ({$desc})");
                        } else {
                            Log::warning("API error fetching status for SMS ID {$sms->id}: " . ($data['status_message'] ?? 'Unknown error'));
                        }
                    } catch (\Exception $e) {
                        Log::error("Exception fetching delivery for SMS ID {$sms->id}: {$e->getMessage()}");
                    }

                    // Small delay to avoid rate limiting
                    usleep(50000); // 50ms delay
                }
            });

        Log::info("Delivery status fetch complete: {$processedCount} processed, {$updatedCount} updated");
    }
}
