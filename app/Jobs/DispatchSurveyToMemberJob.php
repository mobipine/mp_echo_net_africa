<?php

namespace App\Jobs;

use App\Models\GroupSurvey;
use App\Models\Survey;
use App\Models\Member;
use App\Models\SurveyProgress;
use App\Models\SMSInbox;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class DispatchSurveyToMemberJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $memberId;
    public $groupSurveyId;

    public $tries = 3;
    public $timeout = 60;

    /**
     * The number of seconds the job's unique lock will be maintained.
     */
    public int $uniqueFor = 300;

    public function __construct($memberId, $groupSurveyId)
    {
        $this->memberId = $memberId;
        $this->groupSurveyId = $groupSurveyId;
    }

    /**
     * Get the unique ID for the job.
     */
    public function uniqueId(): string
    {
        return "dispatch-survey-member-{$this->memberId}-assignment-{$this->groupSurveyId}";
    }

    public function handle()
    {
        $assignment = GroupSurvey::find($this->groupSurveyId);
        $member = Member::find($this->memberId);
        if (!$assignment || !$member) return;

        $survey = Survey::find($assignment->survey_id);
        if (!$survey) return;

        $firstQuestion = getNextQuestion($survey->id, null, null);
        if (!$firstQuestion) {
            Log::info("Survey '{$survey->title}' has no questions.");
            return;
        }

        // ===== Stage & order check =====
        $surveyOrder = $survey->order;
        if ($surveyOrder === 1 && $member->stage !== 'New') return;

        if ($surveyOrder > 1) {
            $previousSurvey = Survey::where('order', $surveyOrder - 1)->first();
            if (!$previousSurvey) return;
            $expectedStage = str_replace(' ', '', ucfirst($previousSurvey->title)) . 'Completed';
            if ($member->stage !== $expectedStage) return;
        }

        // ===== Check existing progress =====
        $progress = SurveyProgress::where('member_id', $member->id)
            ->where('survey_id', $survey->id)
            ->whereNull('completed_at')
            ->first();

        if ($progress && $survey->participant_uniqueness) return;

        if ($progress) {
            $progress->update(['status' => 'CANCELLED']);
        }

        // ===== Create new survey progress =====
        SurveyProgress::create([
            'survey_id' => $survey->id,
            'member_id' => $member->id,
            'current_question_id' => $firstQuestion->id,
            'last_dispatched_at' => now(),
            'has_responded' => false,
            'source' => 'manual',
            'channel' => $assignment->channel,
        ]);

        // ===== Send SMS =====
        $message = formartQuestion($firstQuestion, $member, $survey);
        $length = mb_strlen($message);

        $credits = $length > 0 ? (int) ceil($length / 160) : 0;
        try {
            SMSInbox::create([
                'message' => $message,
                'phone_number' => $member->phone,
                'member_id' => $member->id,
                'channel' => $assignment->channel,
                'credits_used' => $credits
            ]);
        } catch (\Exception $e) {
            Log::error("Failed to send SMS to {$member->name}: {$e->getMessage()}");
        }

        // ===== Update Member Stage =====
        $member->update([
            'stage' => str_replace(' ', '', ucfirst($survey->title)) . 'InProgress'
        ]);
    }
}
