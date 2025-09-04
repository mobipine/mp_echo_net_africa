<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GroupSurvey extends Model
{
    protected $guarded = ['id'];
    protected $table = 'group_survey';

    public function group()
    {
        return $this->belongsTo(Group::class);
    }
    
    public function survey()
    {
        return $this->belongsTo(Survey::class);
    }
}
