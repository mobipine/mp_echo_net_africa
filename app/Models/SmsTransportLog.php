<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SmsTransportLog extends Model
{
    protected $fillable = [
        'transport',
        'direction',
        'sms_inbox_id',
        'survey_progress_id',
        'phone_number',
        'message',
        'provider_message_id',
        'payload',
        'response',
    ];

    protected $casts = [
        'payload' => 'array',
        'response' => 'array',
    ];
}
