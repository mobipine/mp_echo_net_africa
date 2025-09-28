<?php

namespace App\Services;

use Netflie\WhatsAppCloudApi\WhatsAppCloudApi;
use Netflie\WhatsAppCloudApi\Message\Template\Component;
use Illuminate\Support\Facades\Log;

class WhatsAppService
{
    protected $whatsapp;

    public function __construct()
    {
        $this->whatsapp = new WhatsAppCloudApi([
            'from_phone_number_id' => config('whatsapp.phone_number_id'),
            'access_token' => config('whatsapp.access_token'),
        ]);
    }

    /**
     * Send text message with better error handling
     */
    public function sendTextMessage(string $to, string $message)
    {
        // Validate phone number format
        if (!$this->isValidPhoneNumber($to)) {
            throw new \Exception("Invalid phone number format: {$to}");
        }

        try {
            $response = $this->whatsapp->sendTextMessage($to, $message);
            
            // Log::info('WhatsApp message sent successfully', [
            //     'to' => $to,
            //     'message_id' => $response->id()
            // ]);
            
            return $response;
            
        } catch (\Exception $e) {
            Log::error('WhatsApp message sending failed', [
                'to' => $to,
                'error' => $e->getMessage()
            ]);
            
            throw $e;
        }
    }

    /**
     * Validate phone number format for WhatsApp
     */
    private function isValidPhoneNumber(string $phoneNumber): bool
    {
        // Remove any non-digit characters
        $cleanNumber = preg_replace('/\D/', '', $phoneNumber);
        
        // WhatsApp requires country code without + or 00
        return preg_match('/^[1-9][0-9]{8,14}$/', $cleanNumber) === 1;
    }

}