<?php

namespace App\Services;

use App\Contracts\SmsTransport;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class BongaSMS implements SmsTransport
{
    protected $baseUrl;
    protected $clientId;
    protected $key;
    protected $secret;
    protected $serviceId;

    public function __construct()
    {
        $config = config('bongasms');
        $this->baseUrl = $config['base_url'];
        $this->clientId = $config['client_id'];
        $this->key = $config['key'];
        $this->secret = $config['secret'];
        $this->serviceId = $config['service_id'];
    }

    public function send(string $phoneNumber, string $message, ?string $serviceId = null): array
    {
        $this->guardRealDelivery();

        $payload = [
            'apiClientID' => $this->clientId,
            'key' => $this->key,
            'secret' => $this->secret,
            'txtMessage' => $message,
            'MSISDN' => $phoneNumber,
            'serviceID' => $serviceId ?? $this->serviceId,
        ];

        Log::info("Sending SMS Payload:", $payload);

        $response = Http::asForm()->post($this->baseUrl, $payload);

        Log::info("SMS API Response:", [
            'body' => $response->json(),
            'status' => $response->status()
        ]);

        if (!$response->successful()) {
            Log::info("Sending SMS not successful");
            throw new \Exception("SMS Sending Failed: " . $response->body());
        }

        return $response->json();
    }

    public function fetchDeliveryStatus(string $uniqueId): ?array
    {
        $this->guardRealDelivery();

        $response = Http::timeout(10)->get('https://app.bongasms.co.ke/api/fetch-delivery', [
            'apiClientID' => config('bongasms.client_id'),
            'key' => config('bongasms.key'),
            'unique_id' => $uniqueId,
        ]);

        if (!$response->successful()) {
            throw new \Exception("SMS delivery fetch failed: " . $response->body());
        }

        return $response->json();
    }

    private function guardRealDelivery(): void
    {
        if (!config('sms.allow_real_delivery')) {
            throw new \RuntimeException('Real SMS delivery is disabled in this environment.');
        }
    }
}
