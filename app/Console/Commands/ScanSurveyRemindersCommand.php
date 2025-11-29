<?php

namespace App\Console\Commands;

use App\Models\Member;
use App\Models\Survey;
use App\Models\SurveyProgress;
use App\Models\SMSInbox;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

/**
 * SCAN SURVEY REMINDERS COMMAND - OVERVIEW
 *
 * 1. Scans all active survey_progress records
 * 2. Checks if last_dispatched_at is more than 1 day ago
 * 3. Has 2 modes: --dry-run (preview only) and normal (actual execution)
 * 4. In normal mode, limits execution to 500 reminders
 * 5. Sends reminder messages and marks is_reminder as true in SMS inbox
 */
class ScanSurveyRemindersCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'surveys:scan-reminders
                            {--dry-run : Preview what would be sent without making changes}
                            {--limit=500 : Maximum number of reminders to send in normal mode}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Scan surveys and count/send reminders for members who have not responded in more than 1 day';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $isDryRun = $this->option('dry-run');
        $limit = (int) $this->option('limit');

        // Check if survey messages are enabled
        if (!$isDryRun && !config('survey_settings.messages_enabled', true)) {
            $this->error('❌ Survey messages are disabled via config. Cannot send reminders.');
            $this->comment('💡 Enable in config/survey_settings.php or use --dry-run to preview');
            return Command::FAILURE;
        }

        $this->info($isDryRun ? '🔍 DRY RUN MODE - No reminders will be sent' : '⚙️  NORMAL MODE - Reminders will be sent');
        $this->newLine();

        // Find survey progress records that need reminders
        // Criteria: last_dispatched_at is more than 1 day ago, not completed, and has not responded
        $oneDayAgo = Carbon::now()->subDay();

        $remindersNeeded = SurveyProgress::with(['survey', 'member', 'currentQuestion'])
            ->whereNull('completed_at')
            ->whereIn('status', ['ACTIVE', 'UPDATING_DETAILS'])
            ->where('has_responded', false)
            ->whereNotNull('last_dispatched_at')
            ->where('last_dispatched_at', '<', $oneDayAgo)
            ->where(function ($query) {
                // Only send reminders if they haven't reached the max (3 reminders)
                $query->whereNull('number_of_reminders')
                    ->orWhere('number_of_reminders', '<', 3);
            })
            ->orderBy('last_dispatched_at', 'asc')
            ->get();

        if ($remindersNeeded->isEmpty()) {
            $this->info('✅ No reminders needed. All surveys are up to date.');
            return Command::SUCCESS;
        }

        $this->info("Found {$remindersNeeded->count()} survey progress records that need reminders");
        $this->newLine();

        if ($isDryRun) {
            $this->runDryMode($remindersNeeded);
        } else {
            // Apply limit in normal mode
            $remindersToProcess = $remindersNeeded->take($limit);
            $this->info("Processing {$remindersToProcess->count()} reminders (limit: {$limit})");
            $this->newLine();
            $this->runNormalMode($remindersToProcess);
        }

        return Command::SUCCESS;
    }

    /**
     * Run in dry-run mode - show what would be sent
     */
    protected function runDryMode($reminders)
    {
        // Group by survey for summary
        $bySurvey = $reminders->groupBy('survey_id');
        $byMember = $reminders->groupBy('member_id');

        // Show sample of reminders (first 20)
        $sampleReminders = $reminders->take(20);

        $this->table(
            ['Progress ID', 'Member', 'Phone', 'Survey', 'Last Dispatched', 'Days Ago', 'Reminders Sent'],
            $sampleReminders->map(function ($progress) {
                $daysAgo = Carbon::parse($progress->last_dispatched_at)->diffInDays(now());
                return [
                    $progress->id,
                    $progress->member?->name ?? 'N/A',
                    $progress->member?->phone ?? 'N/A',
                    $progress->survey?->title ?? 'N/A',
                    Carbon::parse($progress->last_dispatched_at)->format('Y-m-d H:i:s'),
                    $daysAgo,
                    $progress->number_of_reminders ?? 0,
                ];
            })->toArray()
        );

        if ($reminders->count() > 20) {
            $this->newLine();
            $this->comment("... and " . ($reminders->count() - 20) . " more reminders");
        }

        $this->newLine();
        $this->info("📊 Summary:");
        $this->info("   • Total reminders needed: {$reminders->count()}");
        $this->info("   • Unique surveys: {$bySurvey->count()}");
        $this->info("   • Unique members: {$byMember->count()}");

        // Show breakdown by survey
        $this->newLine();
        $this->info("📋 Breakdown by Survey:");
        foreach ($bySurvey as $surveyId => $surveyReminders) {
            $survey = $surveyReminders->first()->survey;
            $this->info("   • {$survey->title} (ID: {$surveyId}): {$surveyReminders->count()} reminders");
        }

        // Show breakdown by days overdue
        $this->newLine();
        $this->info("📅 Breakdown by Days Overdue:");
        $byDays = $reminders->groupBy(function ($progress) {
            $daysAgo = Carbon::parse($progress->last_dispatched_at)->diffInDays(now());
            if ($daysAgo <= 2) return '1-2 days';
            if ($daysAgo <= 7) return '3-7 days';
            if ($daysAgo <= 30) return '8-30 days';
            return '30+ days';
        });
        foreach ($byDays as $range => $group) {
            $this->info("   • {$range}: {$group->count()} reminders");
        }

        $this->newLine();
        $this->comment('💡 Run without --dry-run to send these reminders (limit: 500)');
    }

    /**
     * Run in normal mode - actually send the reminders
     */
    protected function runNormalMode($reminders)
    {
        $this->info("Sending {$reminders->count()} reminders...");
        $this->newLine();

        $bar = $this->output->createProgressBar($reminders->count());
        $bar->start();

        $sent = 0;
        $failed = 0;
        $skipped = 0;

        foreach ($reminders as $progress) {
            try {
                // Double-check that we still need to send (might have been processed by another command)
                $oneDayAgo = Carbon::now()->subDay();
                $progress->refresh();

                if ($progress->completed_at ||
                    $progress->has_responded ||
                    !$progress->last_dispatched_at ||
                    Carbon::parse($progress->last_dispatched_at)->gte($oneDayAgo) ||
                    ($progress->number_of_reminders ?? 0) >= 3) {
                    $skipped++;
                    $bar->advance();
                    continue;
                }

                $member = $progress->member;
                $survey = $progress->survey;
                $currentQuestion = $progress->currentQuestion;

                if (!$member || !$survey || !$currentQuestion) {
                    $skipped++;
                    Log::warning("Skipping reminder for progress ID {$progress->id}: Missing member, survey, or question");
                    $bar->advance();
                    continue;
                }

                DB::beginTransaction();

                // Format reminder message
                $message = formartQuestion($currentQuestion, $member, $survey, true);

                // Create SMS inbox record with is_reminder = true
                SMSInbox::create([
                    'phone_number' => $member->phone,
                    'message' => $message,
                    'channel' => $progress->channel ?? 'sms',
                    'is_reminder' => true, // Mark as reminder
                    'member_id' => $member->id,
                    'survey_progress_id' => $progress->id,
                ]);

                // Update progress
                $progress->update([
                    'last_dispatched_at' => now(),
                ]);
                $progress->increment('number_of_reminders');

                $sent++;

                Log::info("Reminder sent: Member {$member->id} ({$member->name}), Survey {$survey->id} ({$survey->title}), Progress ID {$progress->id}");

                DB::commit();
            } catch (\Exception $e) {
                DB::rollBack();
                $failed++;
                Log::error("Failed to send reminder for progress ID {$progress->id}: " . $e->getMessage());
                $this->newLine();
                $this->warn("⚠️  Failed: Progress ID {$progress->id} - {$e->getMessage()}");
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);

        $this->info("✅ Reminder Processing Complete!");
        $this->newLine();
        $this->info("📊 Summary:");
        $this->info("   • Successfully sent: {$sent} reminders");
        if ($skipped > 0) {
            $this->comment("   • Skipped: {$skipped} reminders (already processed or invalid)");
        }
        if ($failed > 0) {
            $this->warn("   • Failed to send: {$failed} reminders");
        }
        $this->newLine();
        $this->comment('💡 Messages will be sent by the dispatch:sms command');

        Log::info("ScanSurveyRemindersCommand completed: {$sent} sent, {$skipped} skipped, {$failed} failed");
    }
}

