<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SurveyResponse extends Model
{
    protected $fillable = ['survey_id', 'msisdn', 'question_id', 'survey_response'];

    public function survey()
    {
        return $this->belongsTo(Survey::class);
    }

    public function question()
    {
        return $this->belongsTo(SurveyQuestion::class);
    }
}
