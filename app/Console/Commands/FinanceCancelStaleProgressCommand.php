<?php

namespace App\Console\Commands;

use App\Models\Group;
use App\Models\SMSInbox;
use App\Models\SurveyProgress;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class FinanceCancelStaleProgressCommand extends Command
{
    protected $signature = 'finance:cancel-stale-progress
                            {--group= : Limit to members of this group ID (e.g. the Finance Test/Main batch group)}
                            {--survey=7 : Survey ID (Finance = 7)}
                            {--before=2026-06-01 : Only cancel ACTIVE progress created strictly before this date}
                            {--dry-run : Report what would be cancelled without changing anything}';

    protected $description = 'Cancel stale, never-completed survey progress (and its unsent SMS) so the survey can be re-dispatched to those members';

    public function handle(): int
    {
        $groupId = $this->option('group');
        $surveyId = (int) $this->option('survey');
        $before = $this->option('before');
        $dryRun = (bool) $this->option('dry-run');

        $memberIds = null;
        if ($groupId) {
            $group = Group::find($groupId);
            if (!$group) {
                $this->error("Group {$groupId} not found.");
                return self::FAILURE;
            }
            $memberIds = $group->members()->pluck('members.id');
            $this->line("Scope: group '{$group->name}' (ID {$groupId}) — {$memberIds->count()} members");
        } else {
            $this->warn('No --group given: this will scope to ALL members for the survey. Use --group to limit to a batch.');
        }

        // Stale = ACTIVE, never completed, created before the cutoff.
        $progressQuery = SurveyProgress::where('survey_id', $surveyId)
            ->where('status', 'ACTIVE')
            ->whereNull('completed_at')
            ->whereDate('created_at', '<', $before);
        if ($memberIds !== null) {
            $progressQuery->whereIn('member_id', $memberIds);
        }

        $progressIds = $progressQuery->pluck('id');
        $pendingSms = $progressIds->isEmpty()
            ? 0
            : SMSInbox::whereIn('survey_progress_id', $progressIds)->where('status', 'pending')->count();

        $this->newLine();
        $this->info("Survey {$surveyId}: stale ACTIVE progress created before {$before}");
        $this->table(['Metric', 'Count'], [
            ['Stale progress records to CANCEL', number_format($progressIds->count())],
            ['Unsent (pending) SMS to neutralize', number_format($pendingSms)],
        ]);

        if ($progressIds->isEmpty()) {
            $this->info('Nothing to cancel.');
            return self::SUCCESS;
        }

        if ($dryRun) {
            $this->newLine();
            $this->comment('DRY RUN — nothing changed. Re-run without --dry-run to apply.');
            return self::SUCCESS;
        }

        DB::transaction(function () use ($progressIds) {
            // Neutralize unsent messages first so dispatch:sms never sends the old question.
            SMSInbox::whereIn('survey_progress_id', $progressIds)
                ->where('status', 'pending')
                ->update(['status' => 'cancelled']);

            SurveyProgress::whereIn('id', $progressIds)->update(['status' => 'CANCELLED']);
        });

        $this->newLine();
        $this->info('✅ Cancelled ' . number_format($progressIds->count()) . ' stale progress record(s). Those members can now be re-dispatched.');

        return self::SUCCESS;
    }
}
