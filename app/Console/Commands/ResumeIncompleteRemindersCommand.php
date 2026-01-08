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
 * RESUME INCOMPLETE REMINDERS COMMAND
 *
 * Resumes sending reminders for incomplete surveys by skipping those
 * that have already been processed (have recent reminder SMS records).
 *
 * Features:
 * - --dry-run: Preview what would be sent without actually sending
 * - --limit: Limit number of reminders to send (optional)
 * - --since: Only check reminders created since this time (default: 1 hour ago)
 * - Automatically skips already processed reminders
 */
class ResumeIncompleteRemindersCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'surveys:resume-incomplete-reminders
                            {--dry-run : Preview what would be sent without making changes}
                            {--limit= : Maximum number of reminders to send (optional, sends to all if not specified)}
                            {--since= : Only check reminders created since this time (e.g., "1 hour ago", "30 minutes ago")}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Resume sending reminders for incomplete surveys, skipping already processed ones';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $isDryRun = $this->option('dry-run');
        $limit = $this->option('limit') ? (int) $this->option('limit') : null;
        $sinceOption = $this->option('since') ?: '1 hour ago';

        try {
            $sinceTime = Carbon::parse($sinceOption);
        } catch (\Exception $e) {
            $this->error("Invalid --since time format: {$sinceOption}");
            $this->comment('Examples: "1 hour ago", "30 minutes ago", "2024-01-01 10:00:00"');
            return Command::FAILURE;
        }

        // Check if survey messages are enabled
        if (!$isDryRun && !config('survey_settings.messages_enabled', true)) {
            $this->error('❌ Survey messages are disabled via config. Cannot send reminders.');
            $this->comment('💡 Enable in config/survey_settings.php or use --dry-run to preview');
            return Command::FAILURE;
        }

        $this->info($isDryRun ? '🔍 DRY RUN MODE - No reminders will be sent' : '⚙️  NORMAL MODE - Reminders will be sent');
        $this->info("🕐 Checking for reminders created since: {$sinceTime->format('Y-m-d H:i:s')}");
        if ($limit) {
            $this->info("📊 Limit: {$limit} reminders");
        } else {
            $this->info("📊 Limit: Send to ALL remaining incomplete surveys");
        }
        $this->newLine();

        // Find all incomplete survey progress records
        $query = SurveyProgress::with(['survey', 'member', 'currentQuestion'])
            ->whereNull('completed_at')
            ->whereIn('status', ['ACTIVE', 'UPDATING_DETAILS'])
            ->whereNotNull('current_question_id')
            ->orderBy('created_at', 'asc');

        $totalIncomplete = $query->count();

        if ($totalIncomplete === 0) {
            $this->info('✅ No incomplete surveys found.');
            return Command::SUCCESS;
        }

        $this->info("Found {$totalIncomplete} total incomplete survey progress records");
        $this->newLine();

        // Get all progress IDs that have recent reminder SMS records
        $recentReminderProgressIds = SMSInbox::where('is_reminder', true)
            ->whereNotNull('survey_progress_id')
            ->where('created_at', '>=', $sinceTime)
            ->pluck('survey_progress_id')
            ->unique()
            ->toArray();

        $this->info("Found " . count($recentReminderProgressIds) . " progress records with recent reminders (will be skipped)");
        $this->newLine();

        // Filter out already processed ones
        $query->whereNotIn('id', $recentReminderProgressIds);

        $remainingCount = $query->count();

        if ($remainingCount === 0) {
            $this->info('✅ All incomplete surveys have already been processed.');
            $this->comment("💡 All {$totalIncomplete} incomplete surveys have reminder SMS records created since {$sinceTime->format('Y-m-d H:i:s')}");
            return Command::SUCCESS;
        }

        $this->info("Found {$remainingCount} incomplete surveys that still need reminders");
        $this->newLine();

        // Get records based on limit
        $incompleteProgresses = $limit ? $query->limit($limit)->get() : $query->get();

        if ($isDryRun) {
            $this->runDryMode($incompleteProgresses, $totalIncomplete, $remainingCount, count($recentReminderProgressIds));
        } else {
            $this->runNormalMode($incompleteProgresses);
        }

        return Command::SUCCESS;
    }

    /**
     * Run in dry-run mode - show what would be sent
     */
    protected function runDryMode($progresses, $totalIncomplete, $remainingCount, $alreadyProcessed)
    {
        // Group by survey for summary
        $bySurvey = $progresses->groupBy('survey_id');
        $byMember = $progresses->groupBy('member_id');

        // Calculate total SMS credits needed
        $totalCredits = 0;
        $totalMessages = 0;
        $creditBreakdown = [];

        foreach ($progresses as $progress) {
            if ($progress->member && $progress->survey && $progress->currentQuestion) {
                $message = formartQuestion(
                    $progress->currentQuestion,
                    $progress->member,
                    $progress->survey,
                    true
                );
                $messageLength = strlen($message);
                $credits = ceil($messageLength / 160);
                $totalCredits += $credits;
                $totalMessages++;

                // Track credit distribution
                if (!isset($creditBreakdown[$credits])) {
                    $creditBreakdown[$credits] = 0;
                }
                $creditBreakdown[$credits]++;
            }
        }

        // Show sample of reminders (first 20)
        $sampleProgresses = $progresses->take(20);

        $this->table(
            ['Progress ID', 'Member', 'Phone', 'Survey', 'Current Question', 'Created', 'Days Old'],
            $sampleProgresses->map(function ($progress) {
                $daysOld = Carbon::parse($progress->created_at)->diffInDays(now());
                $questionPreview = $progress->currentQuestion
                    ? substr($progress->currentQuestion->question, 0, 50) . '...'
                    : 'N/A';

                return [
                    $progress->id,
                    $progress->member?->name ?? 'N/A',
                    $progress->member?->phone ?? 'N/A',
                    $progress->survey?->title ?? 'N/A',
                    $questionPreview,
                    Carbon::parse($progress->created_at)->format('Y-m-d H:i'),
                    $daysOld,
                ];
            })->toArray()
        );

        if ($progresses->count() > 20) {
            $this->newLine();
            $this->comment("... and " . ($progresses->count() - 20) . " more reminders");
        }

        $this->newLine();
        $this->info("📊 Summary:");
        $this->info("   • Total incomplete surveys in DB: {$totalIncomplete}");
        $this->info("   • Already processed (skipped): {$alreadyProcessed}");
        $this->info("   • Remaining to process: {$remainingCount}");
        $this->info("   • Reminders to be sent: {$progresses->count()}");
        $this->info("   • Unique surveys: {$bySurvey->count()}");
        $this->info("   • Unique members: {$byMember->count()}");

        // Show SMS credits calculation
        $this->newLine();
        $this->info("💳 SMS Credits Calculation:");
        $this->info("   • Total SMS messages: {$totalMessages}");
        $this->info("   • Total SMS credits needed: {$totalCredits} credits");
        if ($totalMessages > 0) {
            $avgCreditsPerMessage = round($totalCredits / $totalMessages, 2);
            $this->info("   • Average credits per message: {$avgCreditsPerMessage}");
        }

        // Show credit distribution
        if (!empty($creditBreakdown)) {
            $this->newLine();
            $this->info("📊 Credit Distribution:");
            ksort($creditBreakdown);
            foreach ($creditBreakdown as $credits => $count) {
                $percentage = round(($count / $totalMessages) * 100, 1);
                $this->info("   • {$credits} credit(s): {$count} messages ({$percentage}%)");
            }
        }

        // Show breakdown by survey
        $this->newLine();
        $this->info("📋 Breakdown by Survey:");
        foreach ($bySurvey as $surveyId => $surveyProgresses) {
            $survey = $surveyProgresses->first()->survey;
            $this->info("   • {$survey->title} (ID: {$surveyId}): {$surveyProgresses->count()} reminders");
        }

        // Show sample message
        $this->newLine();
        $this->info("📨 Sample Reminder Message:");
        $sampleProgress = $progresses->first();
        if ($sampleProgress && $sampleProgress->member && $sampleProgress->survey && $sampleProgress->currentQuestion) {
            $sampleMessage = formartQuestion(
                $sampleProgress->currentQuestion,
                $sampleProgress->member,
                $sampleProgress->survey,
                true
            );
            $messageLength = strlen($sampleMessage);
            $credits = ceil($messageLength / 160);

            $this->comment("Message: " . substr($sampleMessage, 0, 200) . ($messageLength > 200 ? '...' : ''));
            $this->comment("Length: {$messageLength} chars = {$credits} SMS credit(s)");
        }

        $this->newLine();
        $this->comment('💡 Run without --dry-run to send these reminders');
    }

    /**
     * Run in normal mode - actually send the reminders
     */
    protected function runNormalMode($progresses)
    {
        $this->info("Sending {$progresses->count()} reminders...");
        $this->newLine();

        $bar = $this->output->createProgressBar($progresses->count());
        $bar->start();

        $sent = 0;
        $failed = 0;
        $skipped = 0;
        $totalCredits = 0;

        foreach ($progresses as $progress) {
            try {
                // Refresh to ensure we have latest data
                $progress->refresh();

                // Double-check it's still incomplete
                if ($progress->completed_at || !$progress->currentQuestion) {
                    $skipped++;
                    $bar->advance();
                    continue;
                }

                // Double-check it hasn't been processed since we started
                $recentReminder = SMSInbox::where('survey_progress_id', $progress->id)
                    ->where('is_reminder', true)
                    ->where('created_at', '>=', Carbon::now()->subHour())
                    ->exists();

                if ($recentReminder) {
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

                // Calculate credits for this message
                $messageLength = strlen($message);
                $credits = ceil($messageLength / 160);
                $totalCredits += $credits;

                // Create SMS inbox record with is_reminder = true
                SMSInbox::create([
                    'phone_number' => $member->phone,
                    'message' => $message,
                    'channel' => $progress->channel ?? 'sms',
                    'is_reminder' => true,
                    'member_id' => $member->id,
                    'survey_progress_id' => $progress->id,
                ]);

                // Update progress
                $progress->update([
                    'last_dispatched_at' => now(),
                ]);
                $progress->increment('number_of_reminders');

                $sent++;

                Log::info("Resume incomplete survey reminder sent: Member {$member->id} ({$member->name}), Survey {$survey->id} ({$survey->title}), Progress ID {$progress->id}, Credits: {$credits}");

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
            $this->comment("   • Skipped: {$skipped} reminders (completed, invalid, or already processed)");
        }
        if ($failed > 0) {
            $this->warn("   • Failed to send: {$failed} reminders");
        }

        // Show SMS credits used
        $this->newLine();
        $this->info("💳 SMS Credits Used:");
        $this->info("   • Total SMS inbox records created: {$sent}");
        $this->info("   • Total SMS credits used: {$totalCredits} credits");
        if ($sent > 0) {
            $avgCreditsPerMessage = round($totalCredits / $sent, 2);
            $this->info("   • Average credits per message: {$avgCreditsPerMessage}");
        }

        $this->newLine();
        $this->comment('💡 Messages will be sent by the dispatch:sms command');
        $this->comment('💡 Run this command again to continue processing remaining reminders');

        Log::info("ResumeIncompleteRemindersCommand completed: {$sent} sent, {$skipped} skipped, {$failed} failed, {$totalCredits} credits used");
    }
}
