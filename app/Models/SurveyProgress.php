<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SurveyProgress extends Model
{
    use HasFactory;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'survey_progress';

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'survey_id',
        'member_id',
        'current_question_id',
        'last_dispatched_at',
        'has_responded',
        'completed_at',
        'status',
        'source',
    ];

    /**
     * Get the survey associated with the progress.
     */
    public function survey(): BelongsTo
    {
        return $this->belongsTo(Survey::class);
    }

    /**
     * Get the member associated with the progress.
     */
    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    /**
     * Get the current question associated with the progress.
     */
    public function currentQuestion(): BelongsTo
    {
        return $this->belongsTo(SurveyQuestion::class, 'current_question_id');
    }
}