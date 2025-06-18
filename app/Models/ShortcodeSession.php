<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ShortcodeSession extends Model
{
    protected $table = 'shortcode_sessions';

    protected $fillable = ['msisdn', 'survey_id', 'current_question_id'];

    public function survey()
    {
        return $this->belongsTo(Survey::class);
    }

    public function currentQuestion()
    {
        return $this->belongsTo(SurveyQuestion::class, 'current_question_id');
    }
}
