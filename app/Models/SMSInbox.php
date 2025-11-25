<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SMSInbox extends Model
{
    use HasFactory;

    protected $table = 'sms_inboxes';

    protected $fillable = [
        'message',
        'group_ids',
        'status',
        'phone_number',
        'member_id',
        'channel',
        'is_reminder',
        'delivery_status_desc',
        'delivery_status',
        'unique_id',
        'failure_reason',
        'retries',
        'credits_count',
        'amended',
        'survey_progress_id',
    ];

    protected $casts = [
        'group_ids' => 'array',
        'is_reminder' => 'boolean',
        'retries' => 'integer',
        'credits_count' => 'integer',
    ];

    /**
     * Boot method to automatically calculate credits on creation
     */
    protected static function boot()
    {
        parent::boot();

        static::creating(function ($smsInbox) {
            if (!isset($smsInbox->credits_count)) {
                $smsInbox->credits_count = static::calculateCredits($smsInbox->message);
            }
        });
    }

    /**
     * Calculate credits required for a message
     * 1 credit = 160 characters
     */
    public static function calculateCredits(string $message): int
    {
        $length = mb_strlen($message);
        return (int) ceil($length / 160);
    }

    /**
     * Get the member associated with this SMS
     */
    public function member()
    {
        return $this->belongsTo(Member::class);
    }

    /**
     * Get the survey progress associated with this SMS
     */
    public function surveyProgress()
    {
        return $this->belongsTo(SurveyProgress::class, 'survey_progress_id');
    }

    /**
     * Get credit transaction for this SMS (if exists)
     */
    public function creditTransaction()
    {
        return $this->hasOne(CreditTransaction::class);
    }
}
