<?php

return [
    'api_key' => env('UJUMBE_SMS_API_KEY', 'Njg2Mjc2MWU4MDAwNDg5Zjc1NWMyYjc3YzIxMGJi'),
    'base_url' => env('UJUMBE_SMS_BASE_URL', 'https://ujumbesms.co.ke/api/messaging'),
    'sender_id' => env('UJUMBE_SMS_SENDER_ID', '23722'),
    'timeout' => env('UJUMBE_SMS_TIMEOUT', 30), // in seconds
    'email' => env('UJUMBE_SMS_ACCOUNT_EMAIL', 'thumijosphat47@gmail.com'),
];
