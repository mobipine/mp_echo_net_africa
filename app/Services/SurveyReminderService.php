<?php

namespace App\Services;

use Carbon\Carbon;
use App\Models\SMSInbox;
use App\Models\Survey;
use App\Models\SurveyProgress;
use App\Support\SurveyProgressState;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class SurveyReminderService
{
    public function __construct(protected SurveyMessageQueueService $messageQueue)
    {
    }

    public function eligibleProgressQuery(
        ?int $groupId = null,
        ?int $surveyId = null,
        ?int $maxReminders = null,
        ?string $lastDispatchedBefore = null
    ): Builder
    {
        $query = SurveyProgress::with(['survey', 'member', 'currentQuestion'])
            ->whereNull('completed_at')
            ->whereIn('status', ['ACTIVE', 'UPDATING_DETAILS'])
            ->whereNotNull('current_question_id')
            ->orderBy('created_at', 'asc');

        if ($surveyId !== null) {
            $query->where('survey_id', $surveyId);
        }

        if ($groupId !== null) {
            $query->whereHas('member', function ($q) use ($groupId) {
                $q->where('group_id', $groupId)
                    ->orWhereHas('groups', fn ($gq) => $gq->where('groups.id', $groupId));
            });
        }

        if ($maxReminders !== null) {
            $query->where(function ($q) use ($maxReminders) {
                $q->whereNull('number_of_reminders')
                    ->orWhere('number_of_reminders', '<', $maxReminders);
            });
        }

        if ($lastDispatchedBefore !== null) {
            $query->whereNotNull('last_dispatched_at')
                ->where('last_dispatched_at', '<=', Carbon::parse($lastDispatchedBefore));
        }

        return $query;
    }

    public function loadEligibleProgresses(
        ?int $groupId = null,
        ?int $surveyId = null,
        ?int $maxReminders = null,
        ?int $limit = null,
        ?string $lastDispatchedBefore = null
    ): Collection
    {
        $query = $this->eligibleProgressQuery($groupId, $surveyId, $maxReminders, $lastDispatchedBefore);

        return $limit ? $query->limit($limit)->get() : $query->get();
    }

    public function queueReminderForProgress(int $progressId, ?Survey $survey = null, ?string $dispatchBatchUuid = null): array
    {
        return DB::transaction(function () use ($progressId, $survey, $dispatchBatchUuid) {
            $progress = SurveyProgress::with(['survey', 'member', 'currentQuestion'])
                ->whereKey($progressId)
                ->lockForUpdate()
                ->first();

            if (!$progress || !$progress->member || !$progress->currentQuestion || !$progress->survey) {
                return ['status' => 'skipped', 'reason' => 'Missing progress/member/question/survey'];
            }

            if (!SurveyProgressState::isOpen($progress->status, $progress->completed_at)) {
                return ['status' => 'skipped', 'reason' => 'Progress is no longer open'];
            }

            $targetSurvey = $survey ?? $progress->survey;
            $sequence = ((int) ($progress->number_of_reminders ?? 0)) + 1;
            $dedupeKey = "survey-reminder:{$progress->id}:{$sequence}";

            $existing = SMSInbox::where('dedupe_key', $dedupeKey)->first();
            if ($existing) {
                return ['status' => 'skipped', 'reason' => 'Reminder already queued', 'sms_inbox_id' => $existing->id];
            }

            $sms = $this->messageQueue->queueReminder(
                $progress,
                $progress->member,
                $progress->currentQuestion,
                $targetSurvey,
                $sequence,
                $progress->channel ?? 'sms',
                now(),
                $dispatchBatchUuid
            );

            $progress->update(['last_dispatched_at' => now()]);
            $progress->increment('number_of_reminders');

            return [
                'status' => 'queued',
                'sms_inbox_id' => $sms->id,
                'progress_id' => $progress->id,
                'sequence' => $sequence,
            ];
        });
    }
}
