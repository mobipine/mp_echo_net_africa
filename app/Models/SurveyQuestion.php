<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SurveyQuestion extends Model
{
    protected $fillable = [
        'survey_id',
        'question',
        'answer_data_type',
        'data_type_violation_response'
    ];

    public function survey()
    {
        return $this->belongsTo(Survey::class);
    }

    public function surveys()
    {
        return $this->belongsToMany(Survey::class, 'survey_question_survey');
    }
}
