<?php

namespace App\Services;

use App\Models\Member;
use App\Models\SMSInbox;
use App\Models\Survey;
use App\Models\SurveyProgress;
use App\Models\SurveyQuestion;
use App\Support\SurveyProgressState;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

class SurveyDispatchService
{
    public function __construct(protected SurveyMessageQueueService $messageQueue)
    {
    }

    public function normalizeGroupIds(array $groupIds): array
    {
        $normalized = array_values(array_unique(array_map('intval', $groupIds)));
        sort($normalized);

        return $normalized;
    }

    public function eligibleMembersQuery(array $groupIds): Builder
    {
        $groupIds = $this->normalizeGroupIds($groupIds);

        return DB::table('members')
            ->select('members.id')
            ->join('group_member', 'group_member.member_id', '=', 'members.id')
            ->whereIn('group_member.group_id', $groupIds)
            ->whereNull('members.deleted_at')
            ->where('members.is_active', true)
            ->distinct();
    }

    public function dispatchToMember(
        Member $member,
        Survey $survey,
        SurveyQuestion $firstQuestion,
        string $channel,
        string $source = 'manual',
        ?string $dispatchBatchUuid = null
    ): array {
        return DB::transaction(function () use ($member, $survey, $firstQuestion, $channel, $source, $dispatchBatchUuid) {
            if (empty($member->phone)) {
                return ['status' => 'skipped', 'reason' => 'No phone number'];
            }

            $hasCompleted = SurveyProgress::where('member_id', $member->id)
                ->where('survey_id', $survey->id)
                ->whereNotNull('completed_at')
                ->exists();

            if ($hasCompleted) {
                return ['status' => 'skipped', 'reason' => 'Survey already completed'];
            }

            $activeSameSurvey = SurveyProgress::where('member_id', $member->id)
                ->where('survey_id', $survey->id)
                ->whereNull('completed_at')
                ->whereIn('status', SurveyProgressState::OPEN_STATUSES)
                ->lockForUpdate()
                ->get();

            if ($survey->participant_uniqueness && $activeSameSurvey->isNotEmpty()) {
                return ['status' => 'skipped', 'reason' => 'Participant uniqueness is ON and survey already started'];
            }

            if ($activeSameSurvey->isNotEmpty()) {
                $sameSurveyIds = $activeSameSurvey->pluck('id');
                SMSInbox::whereIn('survey_progress_id', $sameSurveyIds)
                    ->where('status', 'pending')
                    ->update(['status' => 'cancelled']);
                SurveyProgress::whereIn('id', $sameSurveyIds)
                    ->update(['status' => 'CANCELLED', 'open_progress_guard' => null]);
            }

            $newProgress = SurveyProgress::create([
                'survey_id' => $survey->id,
                'member_id' => $member->id,
                'current_question_id' => $firstQuestion->id,
                'last_dispatched_at' => now(),
                'has_responded' => false,
                'source' => $source,
                'channel' => $channel,
                'dispatch_batch_uuid' => $dispatchBatchUuid,
            ]);

            $otherIncompleteIds = SurveyProgress::where('member_id', $member->id)
                ->where('id', '!=', $newProgress->id)
                ->whereNull('completed_at')
                ->whereIn('status', SurveyProgressState::OPEN_STATUSES)
                ->pluck('id');

            if ($otherIncompleteIds->isNotEmpty()) {
                SMSInbox::whereIn('survey_progress_id', $otherIncompleteIds)
                    ->where('status', 'pending')
                    ->update(['status' => 'cancelled']);
                SurveyProgress::whereIn('id', $otherIncompleteIds)
                    ->update(['status' => 'CANCELLED', 'open_progress_guard' => null]);
            }

            $memberStage = str_replace(' ', '', ucfirst($survey->title)) . 'InProgress';
            if ($member->stage !== $memberStage) {
                $member->update(['stage' => $memberStage]);
            }

            $sms = $this->messageQueue->queueInitialQuestion(
                $newProgress,
                $member,
                $firstQuestion,
                $survey,
                $channel,
                $dispatchBatchUuid
            );

            return [
                'status' => 'queued',
                'progress_id' => $newProgress->id,
                'sms_inbox_id' => $sms->id,
            ];
        });
    }
}
