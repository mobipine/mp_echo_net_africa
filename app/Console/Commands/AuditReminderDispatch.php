<?php

namespace App\Console\Commands;

use App\Models\Group;
use App\Models\Member;
use App\Models\SMSInbox;
use App\Models\Survey;
use App\Models\SurveyProgress;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * AUDIT REMINDER DISPATCH
 *
 * A single, self-contained audit for a reminder run that misbehaved (e.g. a group of
 * ~8,000 members that ended up creating >11,000 reminder SMS records).
 *
 * Reminders are created one SMSInbox row PER survey_progress, not per member
 * (see SendIncompleteRemindersJob). So an overshoot can come from two sources, both of
 * which this command detects:
 *   1. Duplicate reminder SMS for the SAME survey_progress_id  -> the job looped / ran twice.
 *   2. Members with MORE THAN ONE active progress for the survey -> each progress got its
 *      own reminder. (Fix the root cause with surveys:dedupe-active-progress.)
 *
 * It also finds eligible members (active, incomplete progress) who received NO reminder at
 * all, and can queue exactly one reminder to each of them.
 *
 * Default run = read-only audit (dry run). Nothing is changed without --fix.
 *   --fix          Delete the surplus duplicate reminder SMS (keeping one per progress)
 *                  and reconcile survey_progress.number_of_reminders to surviving rows.
 *   --send-missed  (requires --fix) Also queue ONE reminder to each eligible member who
 *                  received nothing. This costs SMS credits, so it asks to confirm.
 *   --since=...    Only consider reminder SMS created at/after this time (scopes the audit
 *                  to the bad batch, leaving older legitimate reminders untouched).
 */
class AuditReminderDispatch extends Command
{
    protected $signature = 'survey:audit-reminder-dispatch
                            {--group= : Group ID the reminder run targeted (required)}
                            {--survey= : Survey ID the reminder run targeted (required)}
                            {--since= : Only consider reminder SMS created at/after this datetime (e.g. "2026-06-24 09:00"). Scopes to the bad batch.}
                            {--fix : Delete surplus duplicate reminder SMS and reconcile number_of_reminders}
                            {--cancel-stale : With --fix, also cancel pending reminders whose progress is no longer eligible (cancelled/completed)}
                            {--send-missed : With --fix, also queue one reminder to eligible members who received nothing (costs credits)}
                            {--delete-sent : Also delete already-SENT duplicate rows (default keeps sent rows for the audit/credit trail)}
                            {--limit=30 : Rows to show per detail table}';

    protected $description = 'Audit a reminder dispatch for a group/survey: find duplicate reminder SMS, members who were missed, and optionally clean up and resend.';

    public function handle(): int
    {
        $groupId = $this->option('group') ? (int) $this->option('group') : null;
        $surveyId = $this->option('survey') ? (int) $this->option('survey') : null;
        $fix = (bool) $this->option('fix');
        $cancelStale = (bool) $this->option('cancel-stale');
        $sendMissed = (bool) $this->option('send-missed');
        $deleteSent = (bool) $this->option('delete-sent');
        $limit = max(1, (int) $this->option('limit'));

        if (!$groupId || !$surveyId) {
            $this->error('Both --group and --survey are required.');
            $this->line('Example: php artisan survey:audit-reminder-dispatch --group=3487 --survey=7 --since="2026-06-24 09:00"');
            return Command::FAILURE;
        }

        $since = null;
        if ($this->option('since')) {
            try {
                $since = Carbon::parse($this->option('since'));
            } catch (\Throwable $e) {
                $this->error('Could not parse --since value. Use e.g. --since="2026-06-24 09:00".');
                return Command::FAILURE;
            }
        }

        $survey = Survey::find($surveyId);
        $group = Group::find($groupId);
        if (!$survey) {
            $this->error("Survey with ID {$surveyId} not found.");
            return Command::FAILURE;
        }
        if (!$group) {
            $this->error("Group with ID {$groupId} not found.");
            return Command::FAILURE;
        }

        $this->info("Survey: {$survey->title} (ID: {$surveyId})");
        $this->info("Group:  {$group->name} (ID: {$groupId})");
        $this->line('Mode:   ' . ($fix ? '⚙️  FIX (changes WILL be made)' : '🔍 DRY RUN (read-only audit)'));
        if ($since) {
            $this->line('Scope:  reminder SMS created at/after ' . $since->toDateTimeString());
        } else {
            $this->line('Scope:  ALL reminder SMS for this group/survey (no --since given)');
        }
        $this->newLine();

        // --- Resolve the group's members (legacy group_id column + group_member pivot) ---
        $memberIds = $group->members()->pluck('members.id')
            ->merge(Member::where('group_id', $groupId)->pluck('id'))
            ->unique()
            ->values();

        // All progress rows for this survey belonging to those members (any status) — used to
        // scope which SMS rows "belong" to this group/survey.
        $allProgress = SurveyProgress::where('survey_id', $surveyId)
            ->whereIn('member_id', $memberIds)
            ->get(['id', 'member_id', 'status', 'completed_at', 'current_question_id', 'number_of_reminders']);
        $allProgressIds = $allProgress->pluck('id');

        if ($allProgressIds->isEmpty()) {
            $this->warn('No survey progress records found for this group/survey. Nothing to audit.');
            return Command::SUCCESS;
        }

        // "Eligible" = exactly the rows SendIncompleteRemindersJob would have targeted:
        // incomplete, active/updating, with a current question.
        $eligible = $allProgress->filter(function ($p) {
            return is_null($p->completed_at)
                && in_array($p->status, ['ACTIVE', 'UPDATING_DETAILS'], true)
                && !is_null($p->current_question_id);
        })->keyBy('id');

        // --- Reminder SMS in scope ---
        $smsQuery = SMSInbox::where('is_reminder', true)
            ->whereIn('survey_progress_id', $allProgressIds);
        if ($since) {
            $smsQuery->where('created_at', '>=', $since);
        }

        // Count per progress, broken down by status, in one pass.
        $smsRows = $smsQuery->get(['id', 'survey_progress_id', 'member_id', 'status', 'credits_count', 'created_at']);
        $totalReminderSms = $smsRows->count();
        $byProgress = $smsRows->groupBy('survey_progress_id');

        $statusBreakdown = $smsRows->groupBy('status')->map->count();

        // --- Per-member duplicate PROGRESS rows (root cause #2) ---
        $eligibleByMember = $eligible->groupBy('member_id');
        $membersWithMultipleProgress = $eligibleByMember->filter(fn ($rows) => $rows->count() > 1);

        // --- Eligible members who received NOTHING (missed) ---
        $missed = $eligible->filter(fn ($p) => !isset($byProgress[$p->id]));
        $missedMemberIds = $missed->pluck('member_id')->unique();

        // --- Reminder SMS sitting on a progress that is NO LONGER eligible ---
        // A 'pending' reminder here will still be delivered by the dispatcher even though the
        // member has cancelled or completed the survey (SmsDispatcher does not check progress
        // status). Those pending rows should be cancelled, not sent.
        $progressById = $allProgress->keyBy('id');
        $effectiveStatus = function ($pid) use ($progressById, $eligible) {
            $p = $progressById->get($pid);
            if (!$p) {
                return 'no-progress';
            }
            if ($eligible->has($pid)) {
                return 'ELIGIBLE';
            }
            if (!is_null($p->completed_at)) {
                return 'completed';
            }
            if (is_null($p->current_question_id)) {
                return $p->status . ' (no current question)';
            }
            return $p->status; // e.g. CANCELLED
        };

        // Break every in-scope reminder down by the CURRENT status of its progress.
        $reminderByProgressStatus = $smsRows
            ->groupBy(fn ($r) => $effectiveStatus($r->survey_progress_id))
            ->map->count();

        // Pending reminders whose progress is no longer eligible -> should be cancelled.
        $stalePending = $smsRows->filter(
            fn ($r) => $r->status === 'pending' && !$eligible->has($r->survey_progress_id)
        );
        $stalePendingIds = $stalePending->pluck('id')->all();
        $stalePendingByStatus = $stalePending
            ->groupBy(fn ($r) => $effectiveStatus($r->survey_progress_id))
            ->map->count();
        $stalePendingCredits = (int) $stalePending->sum(fn ($r) => (int) ($r->credits_count ?? 0));

        // --- Surplus duplicate reminder SMS per progress (root cause #1) ---
        // For each progress with >1 reminder SMS, everything beyond the one we keep is surplus.
        $surplusIds = [];          // sms ids that would be deleted
        $surplusCredits = 0;       // credits reclaimed by deleting unsent surplus
        $progressesWithDupes = []; // for reporting
        $sentDuplicateProgressIds = []; // progresses with >1 SENT row (need eyeballs)

        foreach ($byProgress as $pid => $rows) {
            if ($rows->count() <= 1) {
                continue;
            }
            // Keep exactly one row. Prefer keeping a SENT row (already delivered, credit spent),
            // otherwise keep the earliest-created row.
            $sent = $rows->where('status', 'sent');
            if ($sent->isNotEmpty()) {
                $keep = $sent->sortBy('id')->first();
                if ($sent->count() > 1) {
                    $sentDuplicateProgressIds[] = $pid;
                }
            } else {
                $keep = $rows->sortBy('id')->first();
            }

            // Statuses we are allowed to delete. NEVER delete 'processing' (a row the SMS
            // dispatcher is sending right now) — that would race the live dispatcher. 'sent'
            // is only deletable when the operator explicitly opts in with --delete-sent.
            $deletableStatuses = $deleteSent ? ['pending', 'failed', 'sent'] : ['pending', 'failed'];

            $toDelete = $rows
                ->reject(fn ($r) => $r->id === $keep->id)
                ->filter(fn ($r) => in_array($r->status, $deletableStatuses, true));

            if ($toDelete->isEmpty()) {
                continue;
            }

            foreach ($toDelete as $r) {
                $surplusIds[] = $r->id;
                if ($r->status !== 'sent') {
                    $surplusCredits += (int) ($r->credits_count ?? 0);
                }
            }

            $progressesWithDupes[] = [
                'progress_id' => $pid,
                'member_id' => $rows->first()->member_id,
                'total_sms' => $rows->count(),
                'keep_id' => $keep->id,
                'delete_count' => $toDelete->count(),
                'statuses' => $rows->groupBy('status')->map->count()
                    ->map(fn ($c, $s) => "{$s}:{$c}")->values()->join(', '),
            ];
        }

        // ============================ REPORT ============================
        $this->info('=== Summary ===');
        $this->table(['Metric', 'Value'], [
            ['Group members (group_id + pivot)', number_format($memberIds->count())],
            ['Progress rows for this survey (any status)', number_format($allProgress->count())],
            ['Eligible/incomplete progress (job targets)', number_format($eligible->count())],
            ['Members with >1 eligible progress (root cause)', number_format($membersWithMultipleProgress->count())],
            ['Reminder SMS in scope', number_format($totalReminderSms)],
            ['Progress rows with duplicate reminder SMS', number_format(count($progressesWithDupes))],
            ['Surplus reminder SMS to delete', number_format(count($surplusIds))],
            ['PENDING reminders on cancelled/completed progress', number_format(count($stalePendingIds))],
            ['Eligible members who got NO reminder (missed)', number_format($missed->count())],
            ['SMS credits reclaimed by deleting surplus', number_format($surplusCredits)],
            ['SMS credits saved by cancelling stale pending', number_format($stalePendingCredits)],
        ]);
        $this->newLine();

        $this->info('=== Reminder SMS in scope by status ===');
        if ($statusBreakdown->isEmpty()) {
            $this->line('None.');
        } else {
            $this->table(['Status', 'Count'],
                $statusBreakdown->map(fn ($c, $s) => [$s, number_format($c)])->values()->toArray());
        }
        $this->newLine();

        // Reminders grouped by the CURRENT status of the progress they belong to.
        $this->info('=== Reminder SMS by current progress status ===');
        $this->table(['Progress status', 'Reminder SMS'],
            $reminderByProgressStatus->map(fn ($c, $s) => [$s, number_format($c)])->values()->toArray());
        $this->newLine();

        // The actionable problem: pending reminders that will still be sent to people who
        // have cancelled or completed the survey.
        $this->info('=== PENDING reminders on cancelled/completed (non-eligible) progress ===');
        if (empty($stalePendingIds)) {
            $this->line('None — every pending reminder belongs to a still-eligible progress.');
        } else {
            $this->line(count($stalePendingIds) . ' pending reminder(s) will be sent to members who are no longer eligible '
                . '(they have cancelled/completed). These should be cancelled:');
            $this->table(['Progress status', 'Pending reminders'],
                $stalePendingByStatus->map(fn ($c, $s) => [$s, number_format($c)])->values()->toArray());
            $this->comment('➡  These can be neutralized (status=pending → cancelled) with --fix --cancel-stale.');
        }
        $this->newLine();

        // Root cause #1: duplicate SMS per progress
        $this->info('=== Duplicate reminder SMS per progress (job looped/over-created) ===');
        if (empty($progressesWithDupes)) {
            $this->line('None — every progress has at most one reminder SMS in scope.');
        } else {
            $this->line(count($progressesWithDupes) . ' progress rows have more than one reminder SMS:');
            $this->table(
                ['Progress ID', 'Member ID', 'Total SMS', 'Keep SMS ID', 'Delete', 'Statuses'],
                array_map(fn ($d) => [
                    $d['progress_id'], $d['member_id'], $d['total_sms'],
                    $d['keep_id'], $d['delete_count'], $d['statuses'],
                ], array_slice($progressesWithDupes, 0, $limit))
            );
            if (count($progressesWithDupes) > $limit) {
                $this->comment('... and ' . (count($progressesWithDupes) - $limit) . ' more.');
            }
            if (!empty($sentDuplicateProgressIds)) {
                $this->newLine();
                $this->warn(count($sentDuplicateProgressIds) . ' progress row(s) had MORE THAN ONE reminder actually SENT '
                    . '(members received multiple texts). These sent rows are kept for the audit trail by default; '
                    . 'progress IDs: ' . collect($sentDuplicateProgressIds)->take(20)->join(', ')
                    . (count($sentDuplicateProgressIds) > 20 ? ' ...' : ''));
            }
        }
        $this->newLine();

        // Root cause #2: duplicate progress per member
        $this->info('=== Members with more than one active progress for this survey ===');
        if ($membersWithMultipleProgress->isEmpty()) {
            $this->line('None.');
        } else {
            $this->line($membersWithMultipleProgress->count() . ' member(s) have multiple active progress rows for this survey '
                . '(each one gets its own reminder — this inflates the send count):');
            $rows = $membersWithMultipleProgress->take($limit)->map(function ($rows, $mid) {
                return [$mid, $rows->count(), $rows->pluck('id')->join(', ')];
            })->values()->toArray();
            $this->table(['Member ID', 'Active progress count', 'Progress IDs'], $rows);
            if ($membersWithMultipleProgress->count() > $limit) {
                $this->comment('... and ' . ($membersWithMultipleProgress->count() - $limit) . ' more.');
            }
            $this->comment('➡  Root-cause fix: run  php artisan surveys:dedupe-active-progress  (cancels the extra progress rows).');
        }
        $this->newLine();

        // Missed members
        $this->info('=== Eligible members who received NO reminder ===');
        if ($missed->isEmpty()) {
            $this->line('None — every eligible member has at least one reminder SMS in scope.');
        } else {
            $this->line($missed->count() . ' eligible progress row(s) across ' . $missedMemberIds->count()
                . ' member(s) got no reminder:');
            $rows = $missed->take($limit)->map(fn ($p) => [$p->id, $p->member_id, $p->status])->values()->toArray();
            $this->table(['Progress ID', 'Member ID', 'Status'], $rows);
            if ($missed->count() > $limit) {
                $this->comment('... and ' . ($missed->count() - $limit) . ' more.');
            }
        }
        $this->newLine();

        // ============================ RECOMMENDED FIXES ============================
        $this->info('=== Recommended fixes ===');
        $recs = [];
        if (!empty($surplusIds)) {
            $recs[] = 'Delete ' . count($surplusIds) . ' surplus reminder SMS (reclaims '
                . $surplusCredits . ' credits) and reconcile number_of_reminders  →  re-run with --fix';
        }
        if (!empty($stalePendingIds)) {
            $recs[] = 'Cancel ' . count($stalePendingIds) . ' pending reminder(s) queued for cancelled/completed members (saves '
                . $stalePendingCredits . ' credits)  →  re-run with --fix --cancel-stale';
        }
        if (!$membersWithMultipleProgress->isEmpty()) {
            $recs[] = 'Resolve ' . $membersWithMultipleProgress->count()
                . ' members with multiple active progress  →  php artisan surveys:dedupe-active-progress';
        }
        if (!$missed->isEmpty()) {
            $recs[] = 'Queue one reminder to ' . $missedMemberIds->count()
                . ' missed member(s)  →  re-run with --fix --send-missed';
        }
        if (empty($recs)) {
            $this->line('✅ Nothing to fix. Reminder dispatch looks clean for this scope.');
        } else {
            foreach ($recs as $i => $r) {
                $this->line('  ' . ($i + 1) . '. ' . $r);
            }
        }
        $this->newLine();

        if (!$fix) {
            $this->comment('Read-only audit complete. Re-run with --fix (optionally --cancel-stale / --send-missed) to apply.');
            return Command::SUCCESS;
        }

        // ============================ APPLY FIXES ============================
        $changed = false;

        // 1) Delete surplus duplicate reminder SMS.
        if (!empty($surplusIds)) {
            if (!$this->confirm('Delete ' . count($surplusIds) . ' surplus reminder SMS and reconcile number_of_reminders?', false)) {
                $this->info('Skipped duplicate deletion.');
            } else {
                $changed = true;
                $allowedStatuses = $deleteSent ? ['pending', 'failed', 'sent'] : ['pending', 'failed'];
                $result = $this->deleteSurplus($surplusIds, $progressesWithDupes, $allowedStatuses);
                $this->info("Deleted {$result['deleted']} surplus reminder SMS and reconciled number_of_reminders for affected progress rows.");
                if ($result['skipped'] > 0) {
                    $this->comment("Skipped {$result['skipped']} row(s) that were no longer deletable "
                        . '(the dispatcher sent/claimed them between the audit and the delete) — left untouched.');
                }
            }
            $this->newLine();
        }

        // 2) Cancel pending reminders queued for cancelled/completed members.
        if (!empty($stalePendingIds)) {
            if (!$cancelStale) {
                $this->comment('Note: ' . count($stalePendingIds) . ' pending reminder(s) are queued for cancelled/completed members. '
                    . 'Add --cancel-stale to neutralize them.');
            } elseif (!$this->confirm('Cancel ' . count($stalePendingIds) . ' pending reminder(s) on cancelled/completed progress?', false)) {
                $this->info('Skipped cancelling stale pending reminders.');
            } else {
                $changed = true;
                $cancelled = $this->cancelStalePending($stalePendingIds);
                $this->info("Cancelled {$cancelled} pending reminder(s) (status=pending → cancelled); they will not be sent.");
                if ($cancelled < count($stalePendingIds)) {
                    $this->comment('Skipped ' . (count($stalePendingIds) - $cancelled) . ' row(s) the dispatcher had already claimed/sent.');
                }
            }
            $this->newLine();
        }

        // 3) Queue reminders to missed members.
        if ($sendMissed && $missed->isNotEmpty()) {
            $this->warn('About to QUEUE ' . $missed->count() . ' reminder SMS to missed members. This will cost SMS credits once dispatched.');
            if (!$this->confirm('Queue ' . $missed->count() . ' reminders now?', false)) {
                $this->info('Skipped sending to missed members.');
            } else {
                $changed = true;
                $queued = $this->queueMissedReminders($missed->pluck('id'), $survey);
                $this->info("Queued {$queued} reminder SMS to missed members (status=pending; the SMS dispatcher will deliver them).");
            }
            $this->newLine();
        } elseif ($missed->isNotEmpty() && !$sendMissed) {
            $this->comment('Note: ' . $missed->count() . ' members were missed. Add --send-missed to queue reminders to them.');
        }

        if (!$changed) {
            $this->comment('No changes were applied.');
        }

        return Command::SUCCESS;
    }

    /**
     * Delete surplus reminder SMS in chunks and reset number_of_reminders on each affected
     * progress to the number of reminder SMS that survive for it (whole table, any status).
     *
     * The DELETE re-checks status against $allowedStatuses so a row the live dispatcher
     * sent/claimed between the audit snapshot and this delete is left untouched (no race).
     *
     * @return array{deleted:int, skipped:int}
     */
    protected function deleteSurplus(array $surplusIds, array $progressesWithDupes, array $allowedStatuses): array
    {
        $deleted = 0;
        $affectedProgressIds = array_column($progressesWithDupes, 'progress_id');

        // Deletes in chunks — each chunk DELETE is atomic on its own. We deliberately avoid
        // one big transaction: holding survey_progress locks across thousands of rows would
        // contend with the live inbound webhook handler. The reconcile below is idempotent,
        // so an interrupted run is fully recovered by simply re-running the audit with --fix.
        foreach (array_chunk($surplusIds, 500) as $chunk) {
            $deleted += SMSInbox::whereIn('id', $chunk)
                ->whereIn('status', $allowedStatuses)
                ->delete();
        }

        // Reconcile number_of_reminders to what actually survives for each progress.
        foreach (array_chunk($affectedProgressIds, 500) as $chunk) {
            foreach ($chunk as $pid) {
                $remaining = SMSInbox::where('survey_progress_id', $pid)
                    ->where('is_reminder', true)
                    ->count();
                SurveyProgress::where('id', $pid)->update(['number_of_reminders' => $remaining]);
            }
        }

        $skipped = count($surplusIds) - $deleted;

        Log::info("AuditReminderDispatch: deleted {$deleted} surplus reminder SMS across "
            . count($affectedProgressIds) . " progress rows ({$skipped} skipped as no longer deletable).");

        return ['deleted' => $deleted, 'skipped' => $skipped];
    }

    /**
     * Neutralize pending reminders (pending -> cancelled) so the dispatcher won't send them.
     * Re-checks status='pending' so a row the dispatcher already claimed/sent is left alone.
     */
    protected function cancelStalePending(array $stalePendingIds): int
    {
        $cancelled = 0;

        foreach (array_chunk($stalePendingIds, 500) as $chunk) {
            $cancelled += SMSInbox::whereIn('id', $chunk)
                ->where('status', 'pending')
                ->update(['status' => 'cancelled']);
        }

        Log::info("AuditReminderDispatch: cancelled {$cancelled} stale pending reminder(s) on non-eligible progress.");

        return $cancelled;
    }

    /**
     * Queue exactly one reminder SMS per missed progress, mirroring SendIncompleteRemindersJob.
     */
    protected function queueMissedReminders($progressIds, Survey $survey): int
    {
        $queued = 0;

        foreach ($progressIds as $pid) {
            try {
                $progress = SurveyProgress::with(['member', 'currentQuestion'])->find($pid);
                if (!$progress || $progress->completed_at || !$progress->currentQuestion || !$progress->member) {
                    continue; // state changed since the audit snapshot — skip safely
                }

                // Guard against a race: if a reminder appeared in the meantime, don't double-send.
                $already = SMSInbox::where('survey_progress_id', $pid)->where('is_reminder', true)->exists();
                if ($already) {
                    continue;
                }

                DB::transaction(function () use ($progress, $survey, &$queued) {
                    $message = formartQuestion($progress->currentQuestion, $progress->member, $survey, true);

                    SMSInbox::create([
                        'phone_number' => $progress->member->phone,
                        'message' => $message,
                        'channel' => $progress->channel ?? 'sms',
                        'is_reminder' => true,
                        'member_id' => $progress->member->id,
                        'survey_progress_id' => $progress->id,
                    ]);

                    $progress->update(['last_dispatched_at' => now()]);
                    $progress->increment('number_of_reminders');
                    $queued++;
                });
            } catch (\Throwable $e) {
                Log::error("AuditReminderDispatch: failed to queue reminder for progress {$pid}: {$e->getMessage()}");
                $this->warn("  Failed to queue reminder for progress {$pid}: {$e->getMessage()}");
            }
        }

        Log::info("AuditReminderDispatch: queued {$queued} reminders to missed members for survey {$survey->id}.");

        return $queued;
    }
}
