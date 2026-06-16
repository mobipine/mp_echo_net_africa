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

    protected $description = 'Cancel stale, never-completed survey progress (and neutralize its unsent SMS) so the survey can be re-dispatched to those members under participant_uniqueness = ON';

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

        // Stale = active/incomplete (ACTIVE or UPDATING_DETAILS, completed_at IS NULL)
        // created before the cutoff. We CANCEL these (non-destructive): dispatch now treats
        // a CANCELLED row as inert, so the member is re-included in a fresh dispatch, while
        // the record is kept for history. (Already-cancelled rows are skipped by the status filter.)
        $progressQuery = SurveyProgress::where('survey_id', $surveyId)
            ->whereNull('completed_at')
            ->whereIn('status', ['ACTIVE', 'UPDATING_DETAILS'])
            ->whereDate('created_at', '<', $before);
        if ($memberIds !== null) {
            $progressQuery->whereIn('member_id', $memberIds);
        }

        $progressIds = $progressQuery->pluck('id');
        $pendingSms = $progressIds->isEmpty()
            ? 0
            : SMSInbox::whereIn('survey_progress_id', $progressIds)->where('status', 'pending')->count();

        $this->newLine();
        $this->info("Survey {$surveyId}: stale active progress (completed_at IS NULL) created before {$before}");
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
            // Neutralize unsent messages first: dispatch:sms sends any sms_inboxes row with
            // status 'pending' regardless of its progress status, so the old question would
            // otherwise still fire even though the progress is cancelled.
            SMSInbox::whereIn('survey_progress_id', $progressIds)
                ->where('status', 'pending')
                ->update(['status' => 'cancelled']);

            // Cancel (don't delete) the stale progress. Dispatch treats CANCELLED as inert,
            // so these members are re-included in a fresh dispatch under uniqueness = ON.
            SurveyProgress::whereIn('id', $progressIds)->update(['status' => 'CANCELLED']);
        });

        $this->newLine();
        $this->info('✅ Cancelled ' . number_format($progressIds->count()) . ' stale progress record(s). Those members can now be re-dispatched.');

        return self::SUCCESS;
    }
}
