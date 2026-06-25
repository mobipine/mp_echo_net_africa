<?php

return [
    'driver' => env('SMS_DRIVER', in_array(env('APP_ENV'), ['local', 'testing'], true) ? 'fake' : 'bonga'),
    'allow_real_delivery' => env('SMS_ALLOW_REAL_DELIVERY', !in_array(env('APP_ENV'), ['local', 'testing'], true)),
];
