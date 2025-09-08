<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SurveyQuestionPivot extends Model
{
    protected $table = 'survey_question_survey';
    protected $fillable = ['survey_id', 'survey_question_id', 'position'];
    public $timestamps = false;
}
