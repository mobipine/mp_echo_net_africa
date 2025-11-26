<?php

namespace App\Console\Commands;

use App\Models\Member;
use App\Models\Survey;
use App\Models\SurveyProgress;
use App\Models\SMSInbox;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * INITIALIZE NEW MEMBERS COMMAND - OVERVIEW
 *
 * 1. Finds members who have NEVER started any survey (no survey_progress)
 * 2. Has 2 modes: --dry-run (preview only) and normal (actual execution)
 * 3. Updates member stage to 'New'
 * 4. Creates survey_progress for recruitment survey (ID: 5)
 * 5. Sends first question via SMS (queues in sms_inboxes)
 * 6. Useful for onboarding new members who haven't been initialized
 */
class InitializeNewMembersCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'surveys:initialize-new-members
                            {--dry-run : Preview what would be updated without making changes}
                            {--survey-id=5 : Survey ID to send (default: 5 - Recruitment Survey)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Initialize members without survey progress by sending them the recruitment survey';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $isDryRun = $this->option('dry-run');
        $surveyId = (int) $this->option('survey-id');

        // Check if survey messages are enabled
        if (!$isDryRun && !config('survey_settings.messages_enabled', true)) {
            $this->error('❌ Survey messages are disabled via config. Cannot initialize members.');
            $this->comment('💡 Enable in config/survey_settings.php or use --dry-run to preview');
            return Command::FAILURE;
        }

        $this->info($isDryRun ? '🔍 DRY RUN MODE - No changes will be made' : '⚙️  NORMAL MODE - Changes will be applied');
        $this->info("📌 Survey ID: {$surveyId}");
        $this->newLine();

        // Validate survey exists
        $survey = Survey::find($surveyId);
        if (!$survey) {
            $this->error("❌ Survey with ID {$surveyId} not found!");
            return Command::FAILURE;
        }

        $this->info("✅ Survey found: {$survey->title}");
        $this->newLine();

        // Get first question
        $firstQuestion = getNextQuestion($survey->id, null, null);

        // Check if getNextQuestion returned an error array
        if (is_array($firstQuestion)) {
            $this->error("❌ Error getting first question: " . ($firstQuestion['message'] ?? 'Unknown error'));
            return Command::FAILURE;
        }

        if (!$firstQuestion || !$firstQuestion instanceof \App\Models\SurveyQuestion) {
            $this->error("❌ Survey has no questions configured!");
            return Command::FAILURE;
        }

        $this->info("✅ First question ready: " . substr($firstQuestion->question, 0, 50) . "...");
        $this->newLine();

        // Find members without survey progress
        $uninitializedMembers = $this->findUninitializedMembers();

        if ($uninitializedMembers->isEmpty()) {
            $this->info('✅ No uninitialized members found. All members have started surveys.');
            return Command::SUCCESS;
        }

        $this->info("Found {$uninitializedMembers->count()} members without survey progress");
        $this->newLine();

        if ($isDryRun) {
            $this->runDryMode($uninitializedMembers, $survey);
        } else {
            $this->runNormalMode($uninitializedMembers, $survey, $firstQuestion);
        }

        return Command::SUCCESS;
    }

    /**
     * Find members who have never started any survey
     */
    protected function findUninitializedMembers()
    {
        // Get all active member IDs
        $allMemberIds = Member::where('is_active', true)
            ->pluck('id');

        // Get member IDs that have survey progress
        $membersWithProgress = SurveyProgress::distinct('member_id')
            ->pluck('member_id');

        // Find members without progress
        $uninitializedMemberIds = $allMemberIds->diff($membersWithProgress);

        // Return full member objects
        return Member::whereIn('id', $uninitializedMemberIds)
            ->where('is_active', true)
            ->with('group')
            ->get();
    }

    /**
     * Run in dry-run mode - show what would be updated
     */
    protected function runDryMode($members, $survey)
    {
        // Show sample of members (first 20)
        $sampleMembers = $members->take(20);

        $this->table(
            ['Member ID', 'Name', 'Phone', 'Current Stage', 'Group', 'Would Update To'],
            $sampleMembers->map(function ($member) {
                return [
                    $member->id,
                    $member->name,
                    $member->phone,
                    $member->stage ?? 'null',
                    $member->group?->name ?? 'No Group',
                    'New',
                ];
            })->toArray()
        );

        if ($members->count() > 20) {
            $this->newLine();
            $this->comment("... and " . ($members->count() - 20) . " more members");
        }

        $this->newLine();
        $this->info("📊 Summary:");
        $this->info("   • Total members to initialize: {$members->count()}");
        $this->info("   • Survey to send: {$survey->title} (ID: {$survey->id})");
        $this->info("   • Changes per member:");
        $this->info("      - Stage → 'New'");
        $this->info("      - Create survey_progress record");
        $this->info("      - Queue first question SMS");
        $this->newLine();
        $this->comment('💡 Run without --dry-run to apply these changes');
    }

    /**
     * Run in normal mode - actually initialize the members
     */
    protected function runNormalMode($members, $survey, $firstQuestion)
    {
        $this->info("Initializing {$members->count()} members...");
        $this->newLine();

        $bar = $this->output->createProgressBar($members->count());
        $bar->start();

        $initialized = 0;
        $failed = 0;

        foreach ($members as $member) {
            try {
                DB::beginTransaction();

                // Update member stage to 'New'
                $member->update(['stage' => 'New']);

                // Create survey progress
                $newProgress = SurveyProgress::create([
                    'survey_id' => $survey->id,
                    'member_id' => $member->id,
                    'current_question_id' => $firstQuestion->id,
                    'last_dispatched_at' => now(),
                    'has_responded' => false,
                    'source' => 'command',
                    'channel' => 'sms',
                ]);

                // Format and queue first question
                $message = formartQuestion($firstQuestion, $member, $survey);

                SMSInbox::create([
                    'phone_number' => $member->phone,
                    'message' => $message,
                    'channel' => 'sms',
                    'is_reminder' => false,
                    'member_id' => $member->id,
                    'survey_progress_id' => $newProgress->id,
                ]);

                $initialized++;

                Log::info("Initialize new member: Member {$member->id} ({$member->name}) initialized with survey {$survey->id}");

                DB::commit();
            } catch (\Exception $e) {
                DB::rollBack();
                $failed++;
                Log::error("Failed to initialize member {$member->id} ({$member->name}): " . $e->getMessage());
                $this->newLine();
                $this->warn("⚠️  Failed: {$member->name} - {$e->getMessage()}");
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);

        $this->info("✅ Initialization Complete!");
        $this->newLine();
        $this->info("📊 Summary:");
        $this->info("   • Successfully initialized: {$initialized} members");
        if ($failed > 0) {
            $this->warn("   • Failed to initialize: {$failed} members");
        }
        $this->info("   • Survey sent: {$survey->title}");
        $this->info("   • Stage updated to: 'New'");
        $this->info("   • Survey progress created");
        $this->info("   • First question queued in SMS inbox");
        $this->newLine();
        $this->comment('💡 Messages will be sent by the dispatch:sms command');

        Log::info("InitializeNewMembersCommand completed: {$initialized} initialized, {$failed} failed");
    }
}

