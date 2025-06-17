<?php

return [
    'api_key' => env('UJUMBE_SMS_API_KEY', 'ZTE5MDk1ZjAwNDMzOWZmZjVlM2RkMjkyZDIyYzRm'),
    'base_url' => env('UJUMBE_SMS_BASE_URL', 'https://ujumbesms.co.ke/api/messaging'),
    'sender_id' => env('UJUMBESMS_SENDER_ID', '20642'),
    'timeout' => env('UJUMBE_SMS_TIMEOUT', 30), // in seconds
    'email' => env('UNJUMBE_SMS_ACCOUNT_EMAIL', 'kariukia225@gmail.com'),
];
