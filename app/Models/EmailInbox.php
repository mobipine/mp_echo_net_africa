<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class EmailInbox extends Model
{
    use HasFactory;

    protected $fillable = [
        'member_id',
        'subject',
        'body',
        'status',
    ];

    public function member()
    {
        return $this->belongsTo(Member::class);
    }
}
