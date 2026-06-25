<?php

namespace App\Services;

use App\Models\Member;
use App\Models\SMSInbox;
use App\Models\Survey;
use App\Models\SurveyProgress;
use App\Models\SurveyQuestion;
use Carbon\CarbonInterface;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;

class SurveyMessageQueueService
{
    public function queue(array $attributes): SMSInbox
    {
        $payload = array_merge([
            'channel' => 'sms',
            'is_reminder' => false,
            'credits_count' => SMSInbox::calculateCredits((string) ($attributes['message'] ?? '')),
        ], $attributes);

        return SMSInbox::create($payload);
    }

    public function queueInitialQuestion(
        SurveyProgress $progress,
        Member $member,
        SurveyQuestion $question,
        Survey $survey,
        string $channel,
        ?string $dispatchBatchUuid = null
    ): SMSInbox {
        $message = formartQuestion($question, $member, $survey);
        $dedupeKey = "survey-initial:{$progress->id}:{$question->id}";

        return $this->createWithDedupe([
            'message' => $message,
            'phone_number' => $member->phone,
            'member_id' => $member->id,
            'survey_progress_id' => $progress->id,
            'channel' => $channel,
            'is_reminder' => false,
            'dedupe_key' => $dedupeKey,
            'dispatch_batch_uuid' => $dispatchBatchUuid,
        ], $dedupeKey);
    }

    public function queueReminder(
        SurveyProgress $progress,
        Member $member,
        SurveyQuestion $question,
        Survey $survey,
        int $sequence,
        string $channel,
        ?CarbonInterface $referenceTime = null,
        ?string $dispatchBatchUuid = null
    ): SMSInbox {
        $message = formartQuestion($question, $member, $survey, true);
        $referenceTime ??= now();
        $dedupeKey = "survey-reminder:{$progress->id}:{$sequence}";

        return $this->createWithDedupe([
            'message' => $message,
            'phone_number' => $member->phone,
            'member_id' => $member->id,
            'survey_progress_id' => $progress->id,
            'channel' => $channel,
            'is_reminder' => true,
            'dedupe_key' => $dedupeKey,
            'dispatch_batch_uuid' => $dispatchBatchUuid,
            'created_at' => $referenceTime,
            'updated_at' => $referenceTime,
        ], $dedupeKey);
    }

    private function createWithDedupe(array $attributes, string $dedupeKey): SMSInbox
    {
        try {
            return $this->queue($attributes);
        } catch (QueryException $e) {
            if (!$this->isDuplicateKeyException($e)) {
                throw $e;
            }

            Log::warning("SMS dedupe key already exists, reusing queued message: {$dedupeKey}");

            return SMSInbox::where('dedupe_key', $dedupeKey)->firstOrFail();
        }
    }

    private function isDuplicateKeyException(QueryException $e): bool
    {
        $sqlState = $e->errorInfo[0] ?? null;
        $driverCode = $e->errorInfo[1] ?? null;

        return $sqlState === '23000' || $sqlState === '23505' || in_array($driverCode, [19, 1062], true);
    }
}
