<?php

namespace App\Console\Commands;

use App\Models\SMSInbox;
use App\Models\SurveyProgress;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * BACKFILL SURVEY PROGRESS ID COMMAND - OVERVIEW
 *
 * 1. Finds all SMS inbox records where survey_progress_id is null
 * 2. For each record, gets the member_id
 * 3. Looks up the survey_progress record for that member with survey_id = 5
 * 4. Updates the SMS inbox record with the survey_progress_id
 * 5. Has a dry-run option to preview what would be updated
 */
class BackfillSurveyProgressId extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'sms:backfill-survey-progress-id
                            {--dry-run : Preview what would be updated without making changes}
                            {--survey-id=5 : Survey ID to use for lookup (default: 5)}
                            {--limit= : Maximum number of records to process}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Backfill survey_progress_id for SMS inbox records that are missing it';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $isDryRun = $this->option('dry-run');
        $surveyId = (int) $this->option('survey-id');
        $limit = $this->option('limit') ? (int) $this->option('limit') : null;

        $this->info($isDryRun ? '🔍 DRY RUN MODE - No changes will be made' : '⚙️  NORMAL MODE - Changes will be applied');
        $this->info("📌 Survey ID: {$surveyId}");
        $this->newLine();

        // Find SMS inbox records without survey_progress_id that have a member_id
        $query = SMSInbox::whereNull('survey_progress_id')
            ->whereNotNull('member_id')
            ->orderBy('id', 'asc');

        if ($limit) {
            $query->limit($limit);
        }

        $smsRecords = $query->get();

        if ($smsRecords->isEmpty()) {
            $this->info('✅ No SMS inbox records found that need backfilling.');
            return Command::SUCCESS;
        }

        $this->info("Found {$smsRecords->count()} SMS inbox records without survey_progress_id");
        $this->newLine();

        if ($isDryRun) {
            $this->runDryMode($smsRecords, $surveyId);
        } else {
            $this->runNormalMode($smsRecords, $surveyId);
        }

        return Command::SUCCESS;
    }

    /**
     * Run in dry-run mode - show what would be updated
     */
    protected function runDryMode($smsRecords, $surveyId)
    {
        $canUpdate = 0;
        $cannotUpdate = 0;
        $sampleRecords = [];

        foreach ($smsRecords as $sms) {
            // Find survey progress for this member and survey
            $progress = SurveyProgress::where('member_id', $sms->member_id)
                ->where('survey_id', $surveyId)
                ->first();

            if ($progress) {
                $canUpdate++;
                if (count($sampleRecords) < 20) {
                    $sampleRecords[] = [
                        'SMS ID' => $sms->id,
                        'Member ID' => $sms->member_id,
                        'Phone' => $sms->phone_number,
                        'Progress ID' => $progress->id,
                        'Message Preview' => substr($sms->message ?? '', 0, 50) . '...',
                    ];
                }
            } else {
                $cannotUpdate++;
            }
        }

        if (!empty($sampleRecords)) {
            $this->table(
                ['SMS ID', 'Member ID', 'Phone', 'Progress ID', 'Message Preview'],
                $sampleRecords
            );
            $this->newLine();
        }

        $this->info("📊 Summary:");
        $this->info("   • Total SMS records to check: {$smsRecords->count()}");
        $this->info("   • Can be updated: {$canUpdate}");
        $this->info("   • Cannot be updated (no matching progress): {$cannotUpdate}");

        if ($cannotUpdate > 0) {
            $this->newLine();
            $this->warn("⚠️  {$cannotUpdate} records cannot be updated because no survey progress exists for survey ID {$surveyId}");
            $this->comment("   These records may be from a different survey or have no associated progress.");
        }

        $this->newLine();
        $this->comment('💡 Run without --dry-run to apply these updates');
    }

    /**
     * Run in normal mode - actually update the records
     */
    protected function runNormalMode($smsRecords, $surveyId)
    {
        $this->info("Processing {$smsRecords->count()} SMS inbox records...");
        $this->newLine();

        $bar = $this->output->createProgressBar($smsRecords->count());
        $bar->start();

        $updated = 0;
        $skipped = 0;
        $failed = 0;

        foreach ($smsRecords as $sms) {
            try {
                // Find survey progress for this member and survey
                $progress = SurveyProgress::where('member_id', $sms->member_id)
                    ->where('survey_id', $surveyId)
                    ->first();

                if ($progress) {
                    DB::beginTransaction();

                    $sms->update([
                        'survey_progress_id' => $progress->id,
                    ]);

                    $updated++;
                    Log::info("Backfilled survey_progress_id for SMS inbox ID {$sms->id}: set to progress ID {$progress->id}");

                    DB::commit();
                } else {
                    $skipped++;
                    Log::debug("Skipped SMS inbox ID {$sms->id}: No survey progress found for member {$sms->member_id} and survey {$surveyId}");
                }
            } catch (\Exception $e) {
                DB::rollBack();
                $failed++;
                Log::error("Failed to backfill SMS inbox ID {$sms->id}: " . $e->getMessage());
                $this->newLine();
                $this->warn("⚠️  Failed: SMS ID {$sms->id} - {$e->getMessage()}");
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);

        $this->info("✅ Backfill Complete!");
        $this->newLine();
        $this->info("📊 Summary:");
        $this->info("   • Successfully updated: {$updated} records");
        if ($skipped > 0) {
            $this->comment("   • Skipped: {$skipped} records (no matching survey progress)");
        }
        if ($failed > 0) {
            $this->warn("   • Failed: {$failed} records");
        }
        $this->newLine();

        Log::info("BackfillSurveyProgressId completed: {$updated} updated, {$skipped} skipped, {$failed} failed");
    }
}
