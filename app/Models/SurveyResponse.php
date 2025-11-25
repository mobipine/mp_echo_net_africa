<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SurveyResponse extends Model
{
    protected $fillable = ['survey_id', 'msisdn', 'question_id', 'survey_response', 'session_id','inbox_id','credits_used'];

    public function survey()
    {
        return $this->belongsTo(Survey::class);
    }

    public function question()
    {
        return $this->belongsTo(SurveyQuestion::class);
    }

    public function session()
    {
        return $this->belongsTo(ShortcodeSession::class, 'session_id');
    }
    public function member()
    {
        return $this->belongsTo(\App\Models\Member::class, 'msisdn', 'phone');
    }
    public function inbox()
    {
        return $this->belongsTo(SMSInbox::class, 'inbox_id');
    }

}
