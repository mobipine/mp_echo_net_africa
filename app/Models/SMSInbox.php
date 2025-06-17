<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SMSInbox extends Model
{
    use HasFactory;

    protected $fillable = [
        'message',
        'group_ids', // Add this to the fillable array
    ];

    protected $casts = [
        'group_ids' => 'array', // Cast group_ids as an array
    ];
}
