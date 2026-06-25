<?php

namespace App\Jobs;

use App\Models\Survey;
use App\Models\SurveyProgress;
use App\Services\SurveyReminderService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class SendIncompleteRemindersJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $uniqueFor = 3600;
    public int $timeout = 3600;

    public function __construct(
        public int $groupId,
        public int $surveyId,
        public ?int $maxReminders = null,
        public ?int $limit = null,
        public ?string $dispatchBatchUuid = null
    ) {
        $this->dispatchBatchUuid ??= Str::uuid()->toString();
    }

    public function uniqueId(): string
    {
        return implode(':', [
            'send-incomplete-reminders',
            $this->groupId,
            $this->surveyId,
            $this->maxReminders ?? 'all',
            $this->limit ?? 'all',
        ]);
    }

    public function handle(SurveyReminderService $reminderService): void
    {
        Log::info("SendIncompleteRemindersJob started: group={$this->groupId}, survey={$this->surveyId}, maxReminders={$this->maxReminders}, limit={$this->limit}");

        $progresses = $reminderService->loadEligibleProgresses(
            $this->groupId,
            $this->surveyId,
            $this->maxReminders,
            $this->limit
        );

        $sent = 0;
        $failed = 0;
        $skipped = 0;

        foreach ($progresses as $progress) {
            try {
                $result = $reminderService->queueReminderForProgress(
                    $progress->id,
                    $progress->survey,
                    $this->dispatchBatchUuid
                );

                if ($result['status'] === 'queued') {
                    $sent++;
                } else {
                    $skipped++;
                }
            } catch (\Exception $e) {
                $failed++;
                Log::error("SendIncompleteRemindersJob: Failed progress ID {$progress->id}: {$e->getMessage()}");
            }
        }

        Log::info("SendIncompleteRemindersJob completed: {$sent} sent, {$skipped} skipped, {$failed} failed");
    }
}
