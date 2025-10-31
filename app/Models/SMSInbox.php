<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SMSInbox extends Model
{
    use HasFactory;

    protected $table = 'sms_inboxes';

    protected $fillable = [
        'message',
        'group_ids', // Add this to the fillable array
        'status',
        'phone_number',
        'member_id',
        'channel',
        'is_reminder'
    ];

    protected $casts = [
        'group_ids' => 'array', // Cast group_ids as an array
        'is_reminder' => 'boolean'
    ];


    // public function groups()
    // {
    //     return $this->belongsToMany(Group::class, 'sms_inbox_group', 'sms_inbox_id', 'group_id');
    // }
}
