<?php

namespace App\Jobs;

use App\Models\Group;
use App\Models\Survey;
use App\Models\SurveyProgress;
use App\Models\SMSInbox;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SendIncompleteRemindersJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 3600;

    public function __construct(
        public int $groupId,
        public int $surveyId,
        public ?int $maxReminders = null,
        public ?int $limit = null
    ) {}

    public function handle(): void
    {
        Log::info("SendIncompleteRemindersJob started: group={$this->groupId}, survey={$this->surveyId}, maxReminders={$this->maxReminders}, limit={$this->limit}");

        $query = SurveyProgress::with(['survey', 'member', 'currentQuestion'])
            ->whereNull('completed_at')
            ->whereIn('status', ['ACTIVE', 'UPDATING_DETAILS'])
            ->whereNotNull('current_question_id')
            ->where('survey_id', $this->surveyId)
            ->whereHas('member', function ($q) {
                $q->where('group_id', $this->groupId)
                    ->orWhereHas('groups', fn ($gq) => $gq->where('groups.id', $this->groupId));
            })
            ->orderBy('created_at', 'asc');

        if ($this->maxReminders !== null) {
            $query->where(function ($q) {
                $q->whereNull('number_of_reminders')
                    ->orWhere('number_of_reminders', '<', $this->maxReminders);
            });
        }

        $progresses = $this->limit ? $query->limit($this->limit)->get() : $query->get();

        $sent = 0;
        $failed = 0;
        $skipped = 0;

        foreach ($progresses as $progress) {
            try {
                $progress->refresh();

                if ($progress->completed_at || !$progress->currentQuestion) {
                    $skipped++;
                    continue;
                }

                $member = $progress->member;
                $survey = $progress->survey;
                $currentQuestion = $progress->currentQuestion;

                if (!$member || !$survey || !$currentQuestion) {
                    $skipped++;
                    Log::warning("SendIncompleteRemindersJob: Skipping progress ID {$progress->id} - missing member/survey/question");
                    continue;
                }

                DB::beginTransaction();

                $message = formartQuestion($currentQuestion, $member, $survey, true);

                SMSInbox::create([
                    'phone_number' => $member->phone,
                    'message' => $message,
                    'channel' => $progress->channel ?? 'sms',
                    'is_reminder' => true,
                    'member_id' => $member->id,
                    'survey_progress_id' => $progress->id,
                ]);

                $progress->update(['last_dispatched_at' => now()]);
                $progress->increment('number_of_reminders');

                $sent++;
                DB::commit();
            } catch (\Exception $e) {
                DB::rollBack();
                $failed++;
                Log::error("SendIncompleteRemindersJob: Failed progress ID {$progress->id}: {$e->getMessage()}");
            }
        }

        Log::info("SendIncompleteRemindersJob completed: {$sent} sent, {$skipped} skipped, {$failed} failed");
    }
}
