<?php

namespace App\Console\Commands;

use App\Models\SMSInbox;
use App\Models\Member;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * RETRY FAILED SURVEYS COMMAND - OVERVIEW
 *
 * 1. Finds members where BOTH first survey message AND first reminder failed
 * 2. Has 2 modes: --dry-run (preview only) and normal (actual execution)
 * 3. Dry-run: Shows count and list of what would be updated
 * 4. Normal: Updates first record status to 'pending', sets amended='sysAdmin'
 * 5. Normal mode: Max 500 records per run to prevent overload
 * 6. Useful for manually retrying surveys after system issues
 */
class RetryFailedSurveys extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'surveys:retry-failed
                            {--dry-run : Preview what would be updated without making changes}
                            {--limit=500 : Maximum number of records to update in normal mode}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Retry failed survey messages where both initial message and reminder failed';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $isDryRun = $this->option('dry-run');
        $limit = (int) $this->option('limit');

        if ($limit > 500) {
            $this->error('Limit cannot exceed 500 records for safety reasons.');
            return Command::FAILURE;
        }

        $this->info($isDryRun ? '🔍 DRY RUN MODE - No changes will be made' : '⚙️  NORMAL MODE - Changes will be applied');
        $this->newLine();

        // Find members with failed survey attempts
        $failedMembers = $this->findFailedSurveyMembers();

        if ($failedMembers->isEmpty()) {
            $this->info('✅ No failed survey attempts found. All surveys are either successful or in progress.');
            return Command::SUCCESS;
        }

        $this->info("Found {$failedMembers->count()} members with failed survey attempts");
        $this->newLine();

        if ($isDryRun) {
            $this->runDryMode($failedMembers);
        } else {
            $this->runNormalMode($failedMembers, $limit);
        }

        return Command::SUCCESS;
    }

    /**
     * Find members where both first survey message and first reminder failed
     */
    protected function findFailedSurveyMembers()
    {
        // Get member_ids where:
        // 1. They have at least 2 SMS records (first message + reminder)
        // 2. The first record (oldest) has status='failed'
        // 3. The second record (first reminder) has status='failed'
        // 4. Survey progress exists (survey_progress_id is not null)

        return DB::table('sms_inboxes')
            ->select('member_id', DB::raw('MIN(id) as first_sms_id'))
            ->whereNotNull('member_id')
            ->where('status', 'failed')
            ->groupBy('member_id')
            ->havingRaw('COUNT(*) >= 2')
            ->get()
            ->filter(function ($item) {
                // Verify that both first and second messages failed
                $messages = SMSInbox::where('member_id', $item->member_id)
                    ->orderBy('id', 'asc')
                    // ->take(2)
                    ->get();

                if ($messages->count() < 2) {
                    return false;
                }

                // Both first and second (reminder) must be failed
                // $firstFailed = $messages[0]->status === 'failed';
                // $secondFailed = $messages[1]->status === 'failed';

                // return $firstFailed && $secondFailed;

                //if all messages are failed, return true
                return $messages->every(function ($message) {
                    return $message->status === 'failed';
                });


            });
    }

    /**
     * Run in dry-run mode - show what would be updated
     */
    protected function runDryMode($failedMembers)
    {
        $this->table(
            ['Member ID', 'Member Name', 'Phone', 'First SMS ID', 'Status', 'Failure Reason'],
            $failedMembers->map(function ($item) {
                $member = Member::find($item->member_id);
                $firstSms = SMSInbox::find($item->first_sms_id);

                return [
                    $item->member_id,
                    $member?->name ?? 'Unknown',
                    $member?->phone ?? 'N/A',
                    $item->first_sms_id,
                    $firstSms?->status ?? 'N/A',
                    $firstSms?->failure_reason ? substr($firstSms->failure_reason, 0, 50) . '...' : 'N/A',
                ];
            })->toArray()
        );

        $this->newLine();
        $this->info("📊 Summary:");
        $this->info("   • Total members affected: {$failedMembers->count()}");
        $this->info("   • SMS records that would be updated: {$failedMembers->count()}");
        $this->info("   • Changes: status → 'pending', amended → 'sysAdmin', retries → 0");
        $this->newLine();
        $this->comment('💡 Run without --dry-run to apply these changes');
    }

    /**
     * Run in normal mode - actually update the records
     */
    protected function runNormalMode($failedMembers, $limit)
    {
        $membersToProcess = $failedMembers->take($limit);
        $actualLimit = $membersToProcess->count();

        if ($failedMembers->count() > $limit) {
            $this->warn("⚠️  Found {$failedMembers->count()} records but limiting to {$limit} for safety");
        }

        $this->info("Processing {$actualLimit} members...");
        $this->newLine();

        $bar = $this->output->createProgressBar($actualLimit);
        $bar->start();

        $updated = 0;
        $failed = 0;

        foreach ($membersToProcess as $item) {
            try {
                DB::beginTransaction();

                $firstSms = SMSInbox::find($item->first_sms_id);

                if ($firstSms) {
                    $firstSms->update([
                        'status' => 'pending',
                        'amended' => 'sysAdmin',
                        'retries' => 0,
                        'failure_reason' => null,
                    ]);

                    $updated++;

                    Log::info("Retry failed survey: Updated SMS {$firstSms->id} for member {$item->member_id} to pending status");
                }

                DB::commit();
            } catch (\Exception $e) {
                DB::rollBack();
                $failed++;
                Log::error("Failed to update SMS for member {$item->member_id}: " . $e->getMessage());
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);

        $this->info("✅ Update Complete!");
        $this->newLine();
        $this->info("📊 Summary:");
        $this->info("   • Successfully updated: {$updated} records");
        if ($failed > 0) {
            $this->warn("   • Failed to update: {$failed} records");
        }
        $this->info("   • Status changed to: 'pending'");
        $this->info("   • Amended flag set to: 'sysAdmin'");
        $this->info("   • Retries reset to: 0");
        $this->newLine();
        $this->comment('💡 These messages will be picked up by the dispatch:sms command on the next run');

        Log::info("RetryFailedSurveys command completed: {$updated} updated, {$failed} failed");
    }
}
