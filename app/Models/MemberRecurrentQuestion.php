<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MemberRecurrentQuestion extends Model
{
    use HasFactory;

    protected $fillable = [
        'member_id',
        'question_id',
        'sent_count',
        'next_dispatch_at',
    ];

    protected $casts = [
        'next_dispatch_at' => 'datetime',
    ];

    // Relationships

    public function member()
    {
        return $this->belongsTo(Member::class);
    }

    public function question()
    {
        return $this->belongsTo(SurveyQuestion::class, 'question_id');
    }
}
