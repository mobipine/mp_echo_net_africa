<?php

namespace App\Services;

use App\Contracts\SmsTransport;
use App\Models\SmsTransportLog;
use Illuminate\Support\Str;

class FakeSmsTransport implements SmsTransport
{
    public function send(string $phoneNumber, string $message, ?string $serviceId = null): array
    {
        $response = [
            'status' => 222,
            'status_message' => 'Simulated success',
            'unique_id' => 'fake-' . Str::uuid()->toString(),
            'service_id' => $serviceId,
        ];

        SmsTransportLog::create([
            'transport' => 'fake',
            'direction' => 'outbound',
            'phone_number' => $phoneNumber,
            'message' => $message,
            'provider_message_id' => $response['unique_id'],
            'payload' => [
                'service_id' => $serviceId,
            ],
            'response' => $response,
        ]);

        return $response;
    }

    public function fetchDeliveryStatus(string $uniqueId): ?array
    {
        $response = [
            'status' => 222,
            'delivery_status_desc' => 'DeliveredToTerminal',
            'unique_id' => $uniqueId,
        ];

        SmsTransportLog::create([
            'transport' => 'fake',
            'direction' => 'delivery_status',
            'provider_message_id' => $uniqueId,
            'payload' => ['unique_id' => $uniqueId],
            'response' => $response,
        ]);

        return $response;
    }
}
