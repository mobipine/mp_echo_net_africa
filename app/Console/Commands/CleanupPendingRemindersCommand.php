<?php

namespace App\Console\Commands;

use App\Models\SMSInbox;
use App\Models\SurveyProgress;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * CLEANUP PENDING REMINDERS COMMAND
 *
 * Deletes pending reminder SMS records from sms_inboxes table
 * and decrements the number_of_reminders in the corresponding survey_progress records
 *
 * Features:
 * - --dry-run: Preview what would be deleted without making changes
 * - Properly handles survey progress adjustments
 * - Transaction-safe operations
 */
class CleanupPendingRemindersCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'surveys:cleanup-pending-reminders
                            {--dry-run : Preview what would be deleted without making changes}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Delete pending reminder SMS records and decrement survey progress reminder counts';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $isDryRun = $this->option('dry-run');

        $this->info($isDryRun ? '🔍 DRY RUN MODE - No changes will be made' : '⚙️  NORMAL MODE - Records will be deleted');
        $this->newLine();

        // Find all pending reminder SMS inbox records
        $pendingReminders = SMSInbox::with(['member', 'surveyProgress'])
            ->where('status', 'pending')
            ->where('is_reminder', true)
            ->get();

        if ($pendingReminders->isEmpty()) {
            $this->info('✅ No pending reminder SMS records found.');
            return Command::SUCCESS;
        }

        $this->info("Found {$pendingReminders->count()} pending reminder SMS records");
        $this->newLine();

        if ($isDryRun) {
            $this->runDryMode($pendingReminders);
        } else {
            $this->runNormalMode($pendingReminders);
        }

        return Command::SUCCESS;
    }

    /**
     * Run in dry-run mode - show what would be deleted
     */
    protected function runDryMode($pendingReminders)
    {
        // Group by survey progress
        $byProgress = $pendingReminders->groupBy('survey_progress_id');
        $byMember = $pendingReminders->groupBy('member_id');

        // Calculate total credits that would be saved
        $totalCredits = 0;
        foreach ($pendingReminders as $sms) {
            $totalCredits += $sms->credits_count ?? ceil(strlen($sms->message) / 160);
        }

        // Show sample of records (first 20)
        $sampleRecords = $pendingReminders->take(20);

        $this->table(
            ['SMS ID', 'Member', 'Phone', 'Progress ID', 'Message Preview', 'Credits', 'Created'],
            $sampleRecords->map(function ($sms) {
                $messagePreview = substr($sms->message, 0, 50) . '...';
                $credits = $sms->credits_count ?? ceil(strlen($sms->message) / 160);

                return [
                    $sms->id,
                    $sms->member?->name ?? 'N/A',
                    $sms->phone_number ?? 'N/A',
                    $sms->survey_progress_id ?? 'N/A',
                    $messagePreview,
                    $credits,
                    $sms->created_at->format('Y-m-d H:i'),
                ];
            })->toArray()
        );

        if ($pendingReminders->count() > 20) {
            $this->newLine();
            $this->comment("... and " . ($pendingReminders->count() - 20) . " more records");
        }

        $this->newLine();
        $this->info("📊 Summary:");
        $this->info("   • Total SMS records to delete: {$pendingReminders->count()}");
        $this->info("   • Unique members affected: {$byMember->count()}");
        $this->info("   • Unique survey progress records to adjust: " . $byProgress->count());
        $this->info("   • Total SMS credits to be saved: {$totalCredits} credits");

        // Show breakdown by survey progress
        $this->newLine();
        $this->info("📋 Breakdown by Survey Progress:");

        $progressAdjustments = [];
        foreach ($byProgress as $progressId => $smsRecords) {
            $count = $smsRecords->count();
            if ($progressId) {
                $progress = $smsRecords->first()->surveyProgress;
                $currentReminders = $progress?->number_of_reminders ?? 0;
                $newReminders = max(0, $currentReminders - $count);

                $this->info("   • Progress ID {$progressId}: Delete {$count} SMS, Reminders: {$currentReminders} → {$newReminders}");
                $progressAdjustments[] = [
                    'progress_id' => $progressId,
                    'current' => $currentReminders,
                    'delete' => $count,
                    'new' => $newReminders,
                ];
            } else {
                $this->info("   • No Progress ID: {$count} orphaned SMS records");
            }
        }

        // Show survey progress adjustments details
        if (!empty($progressAdjustments)) {
            $this->newLine();
            $this->info("🔧 Survey Progress Adjustments:");
            $this->table(
                ['Progress ID', 'Current Reminders', 'SMS to Delete', 'New Reminder Count'],
                collect($progressAdjustments)->map(function ($adj) {
                    return [
                        $adj['progress_id'],
                        $adj['current'],
                        $adj['delete'],
                        $adj['new'],
                    ];
                })->toArray()
            );
        }

        $this->newLine();
        $this->comment('💡 Run without --dry-run to perform the cleanup');
    }

    /**
     * Run in normal mode - actually delete the records and adjust survey progress
     */
    protected function runNormalMode($pendingReminders)
    {
        $this->info("Deleting {$pendingReminders->count()} pending reminder SMS records...");
        $this->newLine();

        $bar = $this->output->createProgressBar($pendingReminders->count());
        $bar->start();

        $deleted = 0;
        $adjusted = 0;
        $failed = 0;
        $totalCredits = 0;
        $adjustedProgressIds = [];

        foreach ($pendingReminders as $sms) {
            try {
                DB::beginTransaction();

                $surveyProgressId = $sms->survey_progress_id;
                $credits = $sms->credits_count ?? ceil(strlen($sms->message) / 160);
                $totalCredits += $credits;

                // Delete the SMS record
                $sms->delete();
                $deleted++;

                // Adjust survey progress if it exists
                if ($surveyProgressId) {
                    $progress = SurveyProgress::find($surveyProgressId);

                    if ($progress && $progress->number_of_reminders > 0) {
                        $progress->decrement('number_of_reminders');
                        $adjusted++;
                        $adjustedProgressIds[] = $surveyProgressId;

                        Log::info("Decremented reminders for Progress ID {$surveyProgressId}: {$progress->number_of_reminders} reminders now");
                    }
                }

                Log::info("Deleted pending reminder SMS ID {$sms->id}, saved {$credits} credits");

                DB::commit();
            } catch (\Exception $e) {
                DB::rollBack();
                $failed++;
                Log::error("Failed to delete SMS ID {$sms->id}: " . $e->getMessage());
                $this->newLine();
                $this->warn("⚠️  Failed: SMS ID {$sms->id} - {$e->getMessage()}");
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);

        $this->info("✅ Cleanup Complete!");
        $this->newLine();
        $this->info("📊 Summary:");
        $this->info("   • SMS records deleted: {$deleted}");
        $this->info("   • Survey progress records adjusted: {$adjusted}");
        $this->info("   • Unique progress IDs adjusted: " . count(array_unique($adjustedProgressIds)));
        $this->info("   • SMS credits saved: {$totalCredits} credits");

        if ($failed > 0) {
            $this->newLine();
            $this->warn("⚠️  Failed to delete: {$failed} records");
        }

        Log::info("CleanupPendingRemindersCommand completed: {$deleted} deleted, {$adjusted} adjusted, {$failed} failed, {$totalCredits} credits saved");
    }
}

