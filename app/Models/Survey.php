<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Survey extends Model
{
    protected $fillable = [
        'title',
        'description',
        'trigger_word',
        'final_response',
        'status',
        'start_date',
        'end_date',
        'participant_uniqueness'
    ];

    public function questions()
    {
        return $this->belongsToMany(SurveyQuestion::class, 'survey_question_survey')
            ->withPivot('position')
            ->orderBy('pivot_position');
    }

    public function responses()
    {
        return $this->hasMany(SurveyResponse::class);
    }

    public function groups()
    {
        return $this->belongsToMany(Group::class, 'group_survey');
    }
}
