<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class WebHookController extends Controller
{
    public function handleWebhook(Request $request)
    {
        //I expect to receive the following data from webhook 
        // - phone_number: The phone number of the sender
        // - message: The content of the message sent
        // - status: The status of the message (e.g., 'delivered', 'failed')
        // - timestamp: The time the message was sent
        // Validate the incoming request data
        Log::info('Webhook received:', $request->all());
        // $validatedData = $request->validate([
        //     'phone_number' => 'required|string',
        //     'message' => 'required|string',
        //     'status' => 'required|string',
        //     'timestamp' => 'required|date',
        // ]);

        // If validation passes, you can proceed with processing the webhook data
        //i want to use this function to run my surveys through the shortcode
        //so here we will validate if the message is a trigger word and start the survey
        // For example, you might want to check if the message contains a specific keyword e.h HELLO or START
        //if not we will store the message in the database in the survey_response table and proceed to send the next message

       
        return response()->json(['status' => 'success', 'message' => 'Webhook processed successfully']);
    }
}
