<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CreditTransaction extends Model
{
    protected $fillable = [
        'type',
        'amount',
        'balance_before',
        'balance_after',
        'transaction_type',
        'description',
        'sms_inbox_id',
        'survey_response_id',
        'user_id',
    ];

    /**
     * Get the SMS inbox associated with this transaction
     */
    public function smsInbox()
    {
        return $this->belongsTo(SMSInbox::class);
    }

    /**
     * Get the survey response associated with this transaction
     */
    public function surveyResponse()
    {
        return $this->belongsTo(SurveyResponse::class);
    }

    /**
     * Get the user who performed this transaction
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Scope for additions
     */
    public function scopeAdditions($query)
    {
        return $query->where('type', 'add');
    }

    /**
     * Scope for subtractions
     */
    public function scopeSubtractions($query)
    {
        return $query->where('type', 'subtract');
    }

    /**
     * Scope for sent SMS
     */
    public function scopeSmsSent($query)
    {
        return $query->where('transaction_type', 'sms_sent');
    }

    /**
     * Scope for received SMS
     */
    public function scopeSmsReceived($query)
    {
        return $query->where('transaction_type', 'sms_received');
    }
}

