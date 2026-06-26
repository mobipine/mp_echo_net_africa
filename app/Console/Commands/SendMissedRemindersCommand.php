<?php

namespace App\Console\Commands;

use App\Models\Group;
use App\Models\SMSInbox;
use App\Models\Survey;
use App\Services\SurveyReminderService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class SendMissedRemindersCommand extends Command
{
    protected $signature = 'survey:send-missed-reminders
                            {--group= : Group ID (required)}
                            {--survey= : Survey ID (required)}
                            {--date= : Calendar date to inspect in YYYY-MM-DD format. Defaults to today}
                            {--limit= : Maximum number of missed reminders to queue}
                            {--dry-run : Preview what would be queued without making changes}';

    protected $description = 'Queue reminders for currently eligible survey progresses that received no reminder on the specified date';

    public function handle(): int
    {
        $groupId = $this->option('group') ? (int) $this->option('group') : null;
        $surveyId = $this->option('survey') ? (int) $this->option('survey') : null;
        $limit = $this->option('limit') ? max(1, (int) $this->option('limit')) : null;
        $isDryRun = (bool) $this->option('dry-run');

        if (!$groupId || !$surveyId) {
            $this->error('Both --group and --survey are required.');
            $this->line('Example: php artisan survey:send-missed-reminders --group=3488 --survey=7 --date=2026-06-26 --dry-run');
            return Command::FAILURE;
        }

        $targetDateInput = $this->option('date') ?: now()->toDateString();

        try {
            $windowStart = Carbon::createFromFormat('Y-m-d', $targetDateInput, config('app.timezone'))
                ->startOfDay();
        } catch (\Throwable $e) {
            $this->error("Could not parse --date={$targetDateInput}. Use YYYY-MM-DD, for example 2026-06-26.");
            return Command::FAILURE;
        }

        $windowEnd = $windowStart->copy()->addDay();

        if (!$isDryRun && !config('survey_settings.messages_enabled', true)) {
            $this->error('Survey messages are disabled via config. Cannot queue reminders.');
            return Command::FAILURE;
        }

        $group = Group::find($groupId);
        $survey = Survey::find($surveyId);

        if (!$group) {
            $this->error("Group with ID {$groupId} not found.");
            return Command::FAILURE;
        }

        if (!$survey) {
            $this->error("Survey with ID {$surveyId} not found.");
            return Command::FAILURE;
        }

        $this->info("Survey: {$survey->title} (ID: {$survey->id})");
        $this->info("Group: {$group->name} (ID: {$group->id})");
        $this->info('Date window: ' . $windowStart->toDateTimeString() . ' to ' . $windowEnd->copy()->subSecond()->toDateTimeString());
        $this->info('Mode: ' . ($isDryRun ? 'DRY RUN' : 'QUEUE MISSED REMINDERS'));
        if ($limit !== null) {
            $this->info("Limit: {$limit}");
        }
        $this->newLine();

        /** @var SurveyReminderService $reminderService */
        $reminderService = app(SurveyReminderService::class);

        $eligibleProgresses = $reminderService
            ->eligibleProgressQuery($groupId, $surveyId)
            ->get();

        if ($eligibleProgresses->isEmpty()) {
            $this->info('No currently eligible survey progress rows were found.');
            return Command::SUCCESS;
        }

        $eligibleProgressIds = $eligibleProgresses->pluck('id');

        $remindersInWindow = SMSInbox::where('is_reminder', true)
            ->whereIn('survey_progress_id', $eligibleProgressIds)
            ->where('created_at', '>=', $windowStart)
            ->where('created_at', '<', $windowEnd)
            ->get(['id', 'survey_progress_id', 'status', 'credits_count']);

        $progressIdsWithReminderInWindow = $remindersInWindow->pluck('survey_progress_id')->unique();

        $missedProgresses = $eligibleProgresses
            ->whereNotIn('id', $progressIdsWithReminderInWindow)
            ->values();

        $duplicateProgressesInWindow = $remindersInWindow
            ->groupBy('survey_progress_id')
            ->filter(fn ($rows) => $rows->count() > 1);

        $toQueue = $limit !== null
            ? $missedProgresses->take($limit)->values()
            : $missedProgresses;

        $this->table(['Metric', 'Value'], [
            ['Eligible progress rows right now', number_format($eligibleProgresses->count())],
            ['Reminder SMS already created on target date', number_format($remindersInWindow->count())],
            ['Progress rows with duplicate reminders on target date', number_format($duplicateProgressesInWindow->count())],
            ['Missed eligible progress rows on target date', number_format($missedProgresses->count())],
            ['Missed unique members on target date', number_format($missedProgresses->pluck('member_id')->unique()->count())],
            ['Will queue now', number_format($toQueue->count())],
        ]);

        $this->newLine();

        if ($toQueue->isEmpty()) {
            $this->info('Nothing to queue. Every currently eligible progress already has a reminder in the target date window.');
            return Command::SUCCESS;
        }

        $sample = $toQueue->take(20)->map(function ($progress) {
            return [
                $progress->id,
                $progress->member?->name ?? 'N/A',
                $progress->member?->phone ?? 'N/A',
                $progress->status,
                $progress->number_of_reminders ?? 0,
            ];
        })->toArray();

        $this->table(
            ['Progress ID', 'Member', 'Phone', 'Status', 'Reminders already counted'],
            $sample
        );

        if ($toQueue->count() > 20) {
            $this->comment('... and ' . ($toQueue->count() - 20) . ' more.');
        }

        if ($duplicateProgressesInWindow->isNotEmpty()) {
            $this->newLine();
            $this->warn('Warning: duplicate reminder rows already exist in the target date window for '
                . $duplicateProgressesInWindow->count()
                . ' progress row(s). This command will only queue the missed rows; it does not clean duplicates.');
        }

        if ($isDryRun) {
            $this->newLine();
            $this->comment('Dry run only. Re-run without --dry-run to queue these missed reminders.');
            return Command::SUCCESS;
        }

        if (!$this->confirm('Queue ' . $toQueue->count() . ' missed reminder(s) now?', false)) {
            $this->info('No reminders were queued.');
            return Command::SUCCESS;
        }

        $dispatchBatchUuid = Str::uuid()->toString();
        $queued = 0;
        $skipped = 0;
        $failed = 0;

        foreach ($toQueue as $progress) {
            try {
                $result = $reminderService->queueReminderForProgress(
                    $progress->id,
                    $survey,
                    $dispatchBatchUuid
                );

                if (($result['status'] ?? null) === 'queued') {
                    $queued++;
                } else {
                    $skipped++;
                    $reason = $result['reason'] ?? 'Skipped';
                    $this->warn("Skipped progress {$progress->id}: {$reason}");
                }
            } catch (\Throwable $e) {
                $failed++;
                Log::error("survey:send-missed-reminders failed for progress {$progress->id}: {$e->getMessage()}");
                $this->warn("Failed progress {$progress->id}: {$e->getMessage()}");
            }
        }

        $this->newLine();
        $this->info("Queued: {$queued}");
        if ($skipped > 0) {
            $this->comment("Skipped: {$skipped}");
        }
        if ($failed > 0) {
            $this->warn("Failed: {$failed}");
        }
        $this->comment('Messages will be delivered by the dispatch:sms command.');

        Log::info('survey:send-missed-reminders completed', [
            'group_id' => $groupId,
            'survey_id' => $surveyId,
            'date' => $windowStart->toDateString(),
            'dispatch_batch_uuid' => $dispatchBatchUuid,
            'queued' => $queued,
            'skipped' => $skipped,
            'failed' => $failed,
        ]);

        return Command::SUCCESS;
    }
}
