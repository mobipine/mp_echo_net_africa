<?php
namespace App\Services;
use App\Models\SMSInbox;
use Illuminate\Support\Facades\Http;

class UjumbeSMS {
    public function send($phoneNumber, $message)
    {
        $ujumbe_config = config('ujumbesms');
        // dd($ujumbe_config);

        $baseUrl = $ujumbe_config['base_url'];
        $apiKey = $ujumbe_config['api_key'];
        $email = $ujumbe_config['email'];
        $senderId = $ujumbe_config['sender_id'];


        $url = $baseUrl . '?email=' . $email . '&to=' . $phoneNumber . '&from=' . $senderId . '&auth=' . $apiKey . '&message=' . $message;
        // dd($url);

        //hit the url using Http Client get req
        $response = Http::get($url);
        // $response = true;
        if ($response->successful()) {
            return $response->json();
        } else {
            throw new \Exception('Failed to send SMS: ' . $response->body());
        }
    }
}