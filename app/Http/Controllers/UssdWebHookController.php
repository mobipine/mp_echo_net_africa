<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class UssdWebHookController extends Controller
{
    public function handleUssdWebhook(Request $request)
    {
        Log::info('USSD Webhook Received:', $request->all());
        return response()->json([
            'data' => $request->all(),
            'status' => 'success',
            'message' => 'USSD Webhook Received'
        ]);
    }
}
