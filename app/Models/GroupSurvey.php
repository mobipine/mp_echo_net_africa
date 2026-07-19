<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GroupSurvey extends Model
{
    protected $guarded = ['id'];

    protected $table = 'group_survey';

    protected $casts = [
        'automated' => 'boolean',
        'was_dispatched' => 'boolean',
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
        'dispatched_at' => 'datetime',
        'dispatch_summary' => 'array',
    ];

    public function group()
    {
        return $this->belongsTo(Group::class);
    }

    public function survey()
    {
        return $this->belongsTo(Survey::class);
    }
}
