<?php

namespace App\Console\Commands;

use App\Models\Group;
use App\Models\Member;
use App\Models\SMSInbox;
use App\Models\Survey;
use App\Models\SurveyProgress;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class InvestigateReminderDiscrepancy extends Command
{
    protected $signature = 'survey:investigate-reminder-discrepancy
                            {--group= : Group ID}
                            {--survey= : Survey ID}
                            {--limit=50 : Max progress records to list when showing duplicates}
                            {--fix : Update number_of_reminders to match SMSInbox count}
                            {--dry-run : With --fix, show what would be updated without making changes}';

    protected $description = 'Investigate discrepancy between SMSInbox reminder count and survey_progress.number_of_reminders';

    public function handle(): int
    {
        $groupId = $this->option('group');
        $surveyId = $this->option('survey');

        if (!$groupId || !$surveyId) {
            $this->error('Both --group and --survey are required.');
            $this->line('Example: php artisan survey:investigate-reminder-discrepancy --group=3487 --survey=7');
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

        $memberIds = $group->members()
            ->pluck('members.id')
            ->merge(Member::where('group_id', $groupId)->pluck('id'))
            ->unique()
            ->values();

        $progressIds = SurveyProgress::where('survey_id', $surveyId)
            ->whereIn('member_id', $memberIds)
            ->pluck('id');

        $this->info("Survey: {$survey->title} (ID: {$surveyId})");
        $this->info("Group: {$group->name} (ID: {$groupId})");
        $this->newLine();

        if ($progressIds->isEmpty()) {
            $this->warn('No survey progress records found for this group/survey.');
            return Command::SUCCESS;
        }

        // --- Counts ---
        $smsInboxReminderCount = SMSInbox::whereIn('survey_progress_id', $progressIds)
            ->where('is_reminder', true)
            ->where('status', 'sent')
            ->count();

        $sumNumberOfReminders = (int) SurveyProgress::whereIn('id', $progressIds)->sum('number_of_reminders');

        $this->info('=== Summary ===');
        $this->table(
            ['Source', 'Count'],
            [
                ['SMSInbox reminders (is_reminder=true, status=sent)', number_format($smsInboxReminderCount)],
                ['SUM(survey_progress.number_of_reminders)', number_format($sumNumberOfReminders)],
                ['Discrepancy', number_format($smsInboxReminderCount - $sumNumberOfReminders)],
            ]
        );
        $this->newLine();

        // --- Get SMSInbox reminder counts per survey_progress_id ---
        $inboxCounts = DB::table('sms_inboxes')
            ->select('survey_progress_id', DB::raw('COUNT(*) as sms_count'))
            ->whereIn('survey_progress_id', $progressIds)
            ->where('is_reminder', true)
            ->where('status', 'sent')
            ->groupBy('survey_progress_id')
            ->get()
            ->keyBy('survey_progress_id');

        $progressRecords = SurveyProgress::whereIn('id', $progressIds)
            ->select('id', 'member_id', 'number_of_reminders')
            ->get()
            ->keyBy('id');

        $discrepancies = [];
        foreach ($inboxCounts as $progressId => $row) {
            $progress = $progressRecords->get($progressId);
            $nr = $progress ? ($progress->number_of_reminders ?? 0) : 0;
            $smsCount = (int) $row->sms_count;
            if ($smsCount > $nr) {
                $discrepancies[] = [
                    'progress_id' => $progressId,
                    'member_id' => $progress?->member_id,
                    'number_of_reminders' => $nr,
                    'sms_inbox_count' => $smsCount,
                    'extra' => $smsCount - $nr,
                ];
            }
        }

        // --- Find progress with multiple SMSInbox reminders (duplicates) ---
        $duplicates = $inboxCounts->filter(fn ($row) => (int) $row->sms_count > 1)->values();

        $this->info('=== Duplicate SMSInbox reminders (same survey_progress_id) ===');
        if ($duplicates->isEmpty()) {
            $this->line('None found.');
        } else {
            $this->line("Found {$duplicates->count()} progress records with multiple reminder SMS records:");
            $this->newLine();

            $limit = (int) $this->option('limit');
            $rows = $duplicates->take($limit)->map(function ($row) use ($progressRecords) {
                $p = $progressRecords->get($row->survey_progress_id);
                return [
                    $row->survey_progress_id,
                    $p?->member_id ?? '-',
                    $p?->number_of_reminders ?? '-',
                    (int) $row->sms_count,
                    (int) $row->sms_count - ($p?->number_of_reminders ?? 0),
                ];
            })->toArray();

            $this->table(
                ['Progress ID', 'Member ID', 'number_of_reminders', 'SMSInbox count', 'Extra records'],
                $rows
            );

            if ($duplicates->count() > $limit) {
                $this->comment("... and " . ($duplicates->count() - $limit) . " more. Use --limit=" . $duplicates->count() . " to see all.");
            }
        }
        $this->newLine();

        // --- List actual duplicate SMSInbox records for first few progress IDs ---
        if ($duplicates->isNotEmpty()) {
            $this->info('=== Sample duplicate SMSInbox records (first 5 progress IDs) ===');
            $sampleProgressIds = $duplicates->take(5)->pluck('survey_progress_id');

            foreach ($sampleProgressIds as $pid) {
                $records = SMSInbox::where('survey_progress_id', $pid)
                    ->where('is_reminder', true)
                    ->where('status', 'sent')
                    ->orderBy('created_at')
                    ->get(['id', 'phone_number', 'created_at', 'message']);

                $this->line("Progress ID {$pid} ({$records->count()} records):");
                foreach ($records as $r) {
                    $preview = strlen($r->message) > 60 ? substr($r->message, 0, 60) . '...' : $r->message;
                    $this->line("  - sms_inbox id={$r->id}, phone={$r->phone_number}, created_at={$r->created_at}");
                    $this->line("    Message: {$preview}");
                }
                $this->newLine();
            }
        }

        // --- Discrepancy: progress with extra SMS vs stored nr ---
        $this->info('=== Progress records where SMSInbox count > number_of_reminders ===');
        if (empty($discrepancies)) {
            $this->line('None. All progress records have SMSInbox count <= number_of_reminders.');
        } else {
            $this->line(count($discrepancies) . ' progress records have more SMSInbox reminders than number_of_reminders:');
            $limit = (int) $this->option('limit');
            $display = array_slice($discrepancies, 0, $limit);
            $this->table(
                ['Progress ID', 'Member ID', 'number_of_reminders', 'SMSInbox count', 'Extra'],
                array_map(fn ($d) => array_values($d), $display)
            );
            if (count($discrepancies) > $limit) {
                $this->comment("... and " . (count($discrepancies) - $limit) . " more.");
            }
        }
        $this->newLine();

        // --- Progress with reminders in SMSInbox but number_of_reminders = 0 ---
        $missingIncrement = [];
        foreach ($inboxCounts as $progressId => $row) {
            $progress = $progressRecords->get($progressId);
            $nr = $progress ? (int) ($progress->number_of_reminders ?? 0) : 0;
            if ($nr === 0 && (int) $row->sms_count > 0) {
                $missingIncrement[] = [
                    'progress_id' => $progressId,
                    'member_id' => $progress?->member_id ?? '-',
                    'sms_inbox_count' => (int) $row->sms_count,
                ];
            }
        }
        $this->info('=== Progress with SMSInbox reminders but number_of_reminders = 0 ===');
        if (empty($missingIncrement)) {
            $this->line('None.');
        } else {
            $this->line(count($missingIncrement) . ' progress records have reminder SMS but number_of_reminders=0 (increment may have failed):');
            $limit = (int) $this->option('limit');
            $display = array_slice($missingIncrement, 0, $limit);
            $this->table(
                ['Progress ID', 'Member ID', 'SMSInbox count'],
                array_map(fn ($d) => array_values($d), $display)
            );
            if (count($missingIncrement) > $limit) {
                $this->comment("... and " . (count($missingIncrement) - $limit) . " more.");
            }
        }

        // --- Fix: update number_of_reminders to match SMSInbox count ---
        if ($this->option('fix')) {
            $this->newLine();
            $this->runFix($progressIds, $inboxCounts, $progressRecords);
        }

        return Command::SUCCESS;
    }

    protected function runFix($progressIds, $inboxCounts, $progressRecords): void
    {
        $dryRun = $this->option('dry-run');
        $updates = [];

        foreach ($progressIds as $pid) {
            $progress = $progressRecords->get($pid);
            $currentNr = $progress ? (int) ($progress->number_of_reminders ?? 0) : 0;
            $correctNr = isset($inboxCounts[$pid]) ? (int) $inboxCounts[$pid]->sms_count : 0;

            if ($currentNr !== $correctNr) {
                $updates[] = [
                    'progress_id' => $pid,
                    'member_id' => $progress?->member_id ?? '-',
                    'current' => $currentNr,
                    'correct' => $correctNr,
                ];
            }
        }

        if (empty($updates)) {
            $this->info('No updates needed. All number_of_reminders values are correct.');
            return;
        }

        $limit = (int) $this->option('limit');
        $display = array_slice($updates, 0, $limit);

        if ($dryRun) {
            $this->info('=== Dry run: would update ' . count($updates) . ' survey_progress records ===');
            $this->table(
                ['Progress ID', 'Member ID', 'Current number_of_reminders', 'Correct (from SMSInbox)'],
                array_map(fn ($u) => [$u['progress_id'], $u['member_id'], $u['current'], $u['correct']], $display)
            );
            if (count($updates) > $limit) {
                $this->comment("... and " . (count($updates) - $limit) . " more.");
            }
            $this->newLine();
            $this->comment('Run without --dry-run to apply fixes.');
            return;
        }

        $this->info('=== Fixing ' . count($updates) . ' survey_progress records ===');
        $bar = $this->output->createProgressBar(count($updates));
        $bar->start();

        $fixed = 0;
        foreach ($updates as $u) {
            SurveyProgress::where('id', $u['progress_id'])->update(['number_of_reminders' => $u['correct']]);
            $fixed++;
            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);
        $this->info("Updated {$fixed} survey_progress records. number_of_reminders now matches SMSInbox count.");
    }
}
