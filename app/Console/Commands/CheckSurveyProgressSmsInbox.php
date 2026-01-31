<?php

namespace App\Console\Commands;

use App\Models\Member;
use App\Models\SMSInbox;
use App\Models\Survey;
use App\Models\SurveyProgress;
use Carbon\Carbon;
use Illuminate\Console\Command;

class CheckSurveyProgressSmsInbox extends Command
{
    protected $signature = 'survey:check-progress-sms-inbox
                            {--fix : Create missing SMSInbox records for first question}
                            {--dry-run : With --fix, show what would be created without making changes}';

    protected $description = 'Check SurveyProgress created today: ensure each has at least one SMSInbox (first question), and optionally fix missing ones';

    public function handle(): int
    {
        $fix = $this->option('fix');
        $dryRun = $this->option('dry-run');

        $todayStart = Carbon::today();
        $todayEnd = Carbon::today()->endOfDay();

        $progressCreatedToday = SurveyProgress::whereBetween('created_at', [$todayStart, $todayEnd])
            ->orderBy('id')
            ->get();

        $totalToday = $progressCreatedToday->count();
        if ($totalToday === 0) {
            $this->info('No SurveyProgress records were created today.');
            return 0;
        }

        $needsFix = [];
        $hasSms = 0;
        foreach ($progressCreatedToday as $p) {
            $hasAnySms = SMSInbox::where('survey_progress_id', $p->id)->exists();
            if ($hasAnySms) {
                $hasSms++;
            } else {
                $needsFix[] = $p;
            }
        }
        $needsFixCount = count($needsFix);

        $this->info('--- Summary (SurveyProgress created today) ---');
        $this->table(
            ['Metric', 'Value'],
            [
                ['SurveyProgress records created today', number_format($totalToday)],
                ['With at least one SMSInbox (first question or more)', number_format($hasSms)],
                ['Missing SMSInbox (need fix)', number_format($needsFixCount)],
            ]
        );
        $this->newLine();

        if ($needsFixCount === 0) {
            $this->info('All progress records created today have at least one SMSInbox. Nothing to fix.');
            return 0;
        }

        $this->warn("{$needsFixCount} SurveyProgress record(s) created today have no SMSInbox.");
        $this->newLine();

        $rows = [];
        foreach ($needsFix as $p) {
            $survey = Survey::find($p->survey_id);
            $member = Member::find($p->member_id);
            $rows[] = [
                $p->id,
                $p->survey_id,
                $survey ? $survey->title : 'N/A',
                $p->member_id,
                $member ? $member->name : 'N/A',
                $member ? ($member->phone ?? 'N/A') : 'N/A',
                $p->current_question_id ?? 'N/A',
            ];
        }
        $this->info('Progress records missing SMSInbox:');
        $this->table(
            ['Progress ID', 'Survey ID', 'Survey', 'Member ID', 'Member', 'Phone', 'current_question_id'],
            $rows
        );

        if (!$fix) {
            $this->comment('Run with --fix to create missing SMSInbox records (first question). Use --fix --dry-run to preview.');
            return 0;
        }

        if ($dryRun) {
            $this->warn('--- DRY RUN: No SMSInbox records will be created ---');
            $this->info("Would create " . count($needsFix) . " SMSInbox record(s) for the first question (one per progress above).");
            $this->comment('Run without --dry-run to apply the fix.');
            return 0;
        }

        $created = 0;
        $failed = 0;
        foreach ($needsFix as $p) {
            $survey = Survey::find($p->survey_id);
            $member = Member::find($p->member_id);
            if (!$survey || !$member) {
                $this->warn("Skipping progress {$p->id}: survey or member not found.");
                $failed++;
                continue;
            }
            if (empty($member->phone)) {
                $this->warn("Skipping progress {$p->id}: member {$member->id} has no phone.");
                $failed++;
                continue;
            }

            $firstQuestion = $p->current_question_id
                ? \App\Models\SurveyQuestion::find($p->current_question_id)
                : getNextQuestion($survey->id, null, null);

            if (is_array($firstQuestion)) {
                $this->warn("Skipping progress {$p->id}: could not get first question - " . ($firstQuestion['message'] ?? 'unknown').'.');
                $failed++;
                continue;
            }
            if (!$firstQuestion || !$firstQuestion instanceof \App\Models\SurveyQuestion) {
                $this->warn("Skipping progress {$p->id}: survey has no first question.");
                $failed++;
                continue;
            }

            $message = formartQuestion($firstQuestion, $member, $survey);
            try {
                SMSInbox::create([
                    'message' => $message,
                    'phone_number' => $member->phone,
                    'member_id' => $member->id,
                    'survey_progress_id' => $p->id,
                    'channel' => 'sms',
                    'is_reminder' => false,
                ]);
                $created++;
            } catch (\Exception $e) {
                $this->warn("Failed to create SMSInbox for progress {$p->id}: " . $e->getMessage());
                $failed++;
            }
        }

        $this->info("Fix applied: created {$created} SMSInbox record(s)." . ($failed > 0 ? " Skipped/failed: {$failed}." : ''));

        return 0;
    }
}
