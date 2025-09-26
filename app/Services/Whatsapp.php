<?php
namespace App\Services;

use Exception;
use GuzzleHttp\Client;
use Illuminate\Support\Facades\Log;

class Whatsapp{

    public function send($recipient_number, $message){


        $base_url= config('whatsapp.connection.url');
        $token = config("whatsapp.connection.token");
        
        
        $client = new Client();

        try{
            Log::info("Sending the message");
            Log::info($base_url."message/text");

            $payload = [
                'to' => $recipient_number,
                'body' => $message,  
            ];
            
            $response = $client->post($base_url."messages/text", [
            // Set the Authorization Header for Bearer Token
                'headers' => [
                    'Authorization' => 'Bearer ' . $token,
                    'Accept' => 'application/json',
                ],
                // Send the payload as JSON (automatically sets Content-Type: application/json)
                'json' => $payload, 
                'timeout' => 30, // Add timeout
                'http_errors' => false, // Don't throw exceptions for HTTP errors
            ]);

            $statusCode = $response->getStatusCode();
            $responseBody = $response->getBody()->getContents();

            Log::info("WhatsApp API Response Status: " . $statusCode);
            Log::info("WhatsApp API Response Body: " . $responseBody);

        }catch (Exception $e) {

            return response()->json([
                "status" => "failed",
                "error" => $e
            ]);
        }

        return response()->json([
            "status" => "success",
            "code" => $statusCode
        ]);
    }
}