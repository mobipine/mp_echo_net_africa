<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SurveyQuestion extends Model
{
    protected $fillable = [
        'survey_id',
        'question',
        'answer_data_type',
        'data_type_violation_response',
        'answer_strictness',
        'possible_answers',
    ];

    protected $casts = [
        'possible_answers' => 'array',
    ];

    public function survey()
    {
        return $this->belongsTo(Survey::class);
    }

    public function surveys()
    {
        return $this->belongsToMany(Survey::class, 'survey_question_survey');
    }

    //add a function that takes in the survey_id and returns the position of the question in that survey
    public function getPosition($surveyId)
    {
        $position = SurveyQuestionPivot::where('survey_id', $surveyId)
            ->where('survey_question_id', $this->id)
            ->first()
            ->position ?? null;

        return $position;

    }

    public function getNextQuestion($surveyId)
    {
        $position = $this->getPosition($surveyId);
        if ($position === null) {
            return null;
        }

        $nextQuestion = SurveyQuestionPivot::where('survey_id', $surveyId)
            ->where('position', '>', $position)
            ->orderBy('position')
            ->first();

        return $nextQuestion ? SurveyQuestion::find($nextQuestion->survey_question_id) : null;
    }
}
