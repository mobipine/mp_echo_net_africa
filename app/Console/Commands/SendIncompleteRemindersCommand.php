<?php

namespace App\Console\Commands;

use App\Models\Group;
use App\Models\Member;
use App\Models\Survey;
use App\Models\SurveyProgress;
use App\Models\SMSInbox;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

/**
 * SEND INCOMPLETE REMINDERS COMMAND
 *
 * Scans all incomplete survey progress records and sends reminders
 * for the current question they are on.
 *
 * Features:
 * - --dry-run: Preview what would be sent without actually sending
 * - --survey=ID: Filter by survey ID (optional)
 * - --group=ID: Filter by group ID (optional)
 * - --max-reminders=N: Only send to members who have received fewer than N reminders (e.g. 1=0 reminders, 2=0-1, 3=0-2)
 * - --limit: Limit number of reminders to send (optional, defaults to all)
 */
class SendIncompleteRemindersCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'surveys:send-incomplete-reminders
                            {--dry-run : Preview what would be sent without making changes}
                            {--survey= : Filter by survey ID (optional)}
                            {--group= : Filter by group ID (optional)}
                            {--max-reminders= : Only send to members who have received fewer than N reminders (e.g. 1=only 0 reminders, 2=0-1, 3=0-2 reminders)}
                            {--limit= : Maximum number of reminders to send (optional, sends to all if not specified)}
                            {--chunk=1000 : Process reminders in chunks to avoid memory issues}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Send reminders to all members with incomplete surveys on their current question';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $isDryRun = $this->option('dry-run');
        $limit = $this->option('limit') ? (int) $this->option('limit') : null;
        $surveyId = $this->option('survey') !== null && $this->option('survey') !== ''
            ? (int) $this->option('survey')
            : null;
        $groupId = $this->option('group') !== null && $this->option('group') !== ''
            ? (int) $this->option('group')
            : null;
        $maxReminders = $this->option('max-reminders') !== null && $this->option('max-reminders') !== ''
            ? (int) $this->option('max-reminders')
            : null;

        // Check if survey messages are enabled
        if (!$isDryRun && !config('survey_settings.messages_enabled', true)) {
            $this->error('❌ Survey messages are disabled via config. Cannot send reminders.');
            $this->comment('💡 Enable in config/survey_settings.php or use --dry-run to preview');
            return Command::FAILURE;
        }

        $this->info($isDryRun ? '🔍 DRY RUN MODE - No reminders will be sent' : '⚙️  NORMAL MODE - Reminders will be sent');
        if ($surveyId !== null) {
            $survey = Survey::find($surveyId);
            if (!$survey) {
                $this->error("❌ Survey with ID {$surveyId} not found.");
                return Command::FAILURE;
            }
            $this->info("📊 Filter: Survey '{$survey->title}' (ID: {$surveyId})");
        }
        if ($groupId !== null) {
            $group = Group::find($groupId);
            if (!$group) {
                $this->error("❌ Group with ID {$groupId} not found.");
                return Command::FAILURE;
            }
            $this->info("📊 Filter: Group '{$group->name}' (ID: {$groupId})");
        }
        if ($maxReminders !== null) {
            $this->info("📊 Filter: Only members who have received fewer than {$maxReminders} reminder(s)");
        }
        if ($limit) {
            $this->info("📊 Limit: {$limit} reminders");
        } else {
            $this->info("📊 Limit: Send to ALL matching incomplete surveys");
        }
        $this->newLine();

        // Find all incomplete survey progress records
        $query = SurveyProgress::with(['survey', 'member', 'currentQuestion'])
            ->whereNull('completed_at')
            ->whereIn('status', ['ACTIVE', 'UPDATING_DETAILS'])
            ->whereNotNull('current_question_id')
            ->orderBy('created_at', 'asc');

        if ($surveyId !== null) {
            $query->where('survey_id', $surveyId);
        }
        if ($groupId !== null) {
            $query->whereHas('member', fn ($q) => $q->where('group_id', $groupId));
        }

        $totalIncomplete = (clone $query)->count();

        // Filter by max reminders: only send to those who have received fewer than N reminders
        if ($maxReminders !== null) {
            $query->where(function ($q) use ($maxReminders) {
                $q->whereNull('number_of_reminders')
                    ->orWhere('number_of_reminders', '<', $maxReminders);
            });
        }

        $matchingCount = $query->count();

        if ($matchingCount === 0) {
            $this->info('✅ No incomplete surveys found' . ($maxReminders !== null ? " with fewer than {$maxReminders} reminder(s) received" : '') . '.');
            return Command::SUCCESS;
        }

        $this->info("Found {$matchingCount} incomplete survey progress records" . ($totalIncomplete !== $matchingCount ? " (of {$totalIncomplete} total)" : ''));
        $this->newLine();

        // Get records based on limit
        $incompleteProgresses = $limit ? $query->limit($limit)->get() : $query->get();

        if ($isDryRun) {
            $this->runDryMode($incompleteProgresses, $totalIncomplete, $matchingCount, $maxReminders);
        } else {
            $this->runNormalMode($incompleteProgresses);
        }

        return Command::SUCCESS;
    }

    /**
     * Run in dry-run mode - show what would be sent
     */
    protected function runDryMode($progresses, $totalIncomplete, $matchingCount, $maxReminders = null)
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
            ['Progress ID', 'Member', 'Phone', 'Survey', 'Reminders', 'Created', 'Days Old'],
            $sampleProgresses->map(function ($progress) {
                $daysOld = Carbon::parse($progress->created_at)->diffInDays(now());

                return [
                    $progress->id,
                    $progress->member?->name ?? 'N/A',
                    $progress->member?->phone ?? 'N/A',
                    $progress->survey?->title ?? 'N/A',
                    $progress->number_of_reminders ?? 0,
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
        if ($maxReminders !== null) {
            $this->info("   • Matching filter (fewer than {$maxReminders} reminders): {$matchingCount}");
        }
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

        // Show breakdown by reminders received
        $this->newLine();
        $this->info("🔔 Breakdown by Reminders Received:");
        $byReminders = $progresses->groupBy(fn ($p) => $p->number_of_reminders ?? 0);
        foreach ($byReminders->sortKeys() as $count => $group) {
            $label = $count === 0 ? '0 (none yet)' : $count;
            $this->info("   • {$label} reminder(s): {$group->count()} progress records");
        }

        // Show breakdown by age
        $this->newLine();
        $this->info("📅 Breakdown by Age:");
        $byAge = $progresses->groupBy(function ($progress) {
            $daysOld = Carbon::parse($progress->created_at)->diffInDays(now());
            if ($daysOld === 0) return 'Today';
            if ($daysOld === 1) return '1 day';
            if ($daysOld <= 7) return '2-7 days';
            if ($daysOld <= 30) return '8-30 days';
            return '30+ days';
        });
        foreach ($byAge as $range => $group) {
            $this->info("   • {$range}: {$group->count()} reminders");
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
        $chunkSize = (int) $this->option('chunk');
        $totalCount = $progresses->count();

        $this->info("Sending {$totalCount} reminders in chunks of {$chunkSize}...");
        $this->newLine();

        $bar = $this->output->createProgressBar($totalCount);
        $bar->start();

        $sent = 0;
        $failed = 0;
        $skipped = 0;
        $totalCredits = 0;

        // Process in chunks to avoid memory issues
        $progresses->chunk($chunkSize)->each(function ($chunk) use (&$sent, &$failed, &$skipped, &$totalCredits, $bar) {
            foreach ($chunk as $progress) {
                try {
                    // Refresh to ensure we have latest data
                    $progress->refresh();

                    // Double-check it's still incomplete
                    if ($progress->completed_at || !$progress->currentQuestion) {
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

                    Log::info("Incomplete survey reminder sent: Member {$member->id} ({$member->name}), Survey {$survey->id} ({$survey->title}), Progress ID {$progress->id}, Credits: {$credits}");

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

            // Clear memory after each chunk
            unset($chunk);
            if (function_exists('gc_collect_cycles')) {
                gc_collect_cycles();
            }
        });

        $bar->finish();
        $this->newLine(2);

        $this->info("✅ Reminder Processing Complete!");
        $this->newLine();
        $this->info("📊 Summary:");
        $this->info("   • Successfully sent: {$sent} reminders");
        if ($skipped > 0) {
            $this->comment("   • Skipped: {$skipped} reminders (completed or invalid)");
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

        Log::info("SendIncompleteRemindersCommand completed: {$sent} sent, {$skipped} skipped, {$failed} failed, {$totalCredits} credits used");
    }
}

