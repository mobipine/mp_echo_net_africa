<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SmsInbox extends Model
{
    use HasFactory;

    protected $fillable = [
        'member_id',
        'phone_number',
        'message',
        'status',
    ];

    public function member()
    {
        return $this->belongsTo(Member::class);
    }
}
