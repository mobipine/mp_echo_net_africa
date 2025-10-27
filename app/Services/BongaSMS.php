<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class BongaSMS
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

    public function send($phoneNumber, $message, $serviceId = null)
    {
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
}
