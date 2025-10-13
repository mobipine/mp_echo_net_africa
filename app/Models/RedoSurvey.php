<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class RedoSurvey extends Model
{
    use HasFactory;

    protected $fillable = [
        'member_id',
        'phone_number',
        'survey_to_redo_id',
        'reason',
        'action',
    ];

    public function member()
    {
        return $this->belongsTo(Member::class);
    }

    public function surveyToRedo()
    {
        return $this->belongsTo(Survey::class, 'survey_to_redo_id');
    }
}
