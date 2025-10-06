<?php


namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class NotificationTemplate extends Model
{
    use HasFactory;

    protected $fillable = [
        'slug',
        'subject',
        'body_email',
        'body_sms',
        'body_whatsapp',
        'channels',
        'is_active',
    ];

    protected $casts = [
        'channels' => 'array', // Casts the JSON column to a PHP array/collection
        'is_active' => 'boolean',
    ];
}