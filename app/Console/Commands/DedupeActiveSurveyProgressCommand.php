<?php

namespace App\Console\Commands;

use App\Models\SMSInbox;
use App\Models\SurveyProgress;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Ensures every member has at most ONE active survey at a time.
 *
 * For each member that currently has more than one incomplete (ACTIVE/UPDATING_DETAILS,
 * completed_at IS NULL) survey progress, this keeps the MOST RECENTLY CREATED one active
 * and CANCELs the rest — and neutralizes the cancelled ones' unsent messages. This stops
 * two surveys (e.g. a leftover Recruitment and a freshly dispatched Finance) from competing
 * for the member's replies. Completed progress is never touched.
 */
class DedupeActiveSurveyProgressCommand extends Command
{
    protected $signature = 'surveys:dedupe-active-progress
                            {--dry-run : Report what would change without modifying anything}
                            {--member= : Limit to a single member ID}';

    protected $description = 'Keep only the most recently created incomplete survey progress active per member and cancel the rest, so overlapping surveys do not compete for replies';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $memberId = $this->option('member');

        // Members who have more than one active/incomplete survey progress.
        $memberIds = SurveyProgress::query()
            ->whereNull('completed_at')
            ->whereIn('status', ['ACTIVE', 'UPDATING_DETAILS'])
            ->when($memberId, fn ($q) => $q->where('member_id', $memberId))
            ->select('member_id')
            ->groupBy('member_id')
            ->havingRaw('COUNT(*) > 1')
            ->pluck('member_id');

        $this->info(($dryRun ? '🔍 DRY RUN — ' : '') . 'Members with more than one active incomplete progress: ' . number_format($memberIds->count()));

        if ($memberIds->isEmpty()) {
            $this->info('Nothing to do — every member already has at most one active survey.');
            return self::SUCCESS;
        }

        $idsToCancel = [];
        $membersAffected = 0;
        $sampleRows = [];

        foreach ($memberIds as $mid) {
            $progresses = SurveyProgress::where('member_id', $mid)
                ->whereNull('completed_at')
                ->whereIn('status', ['ACTIVE', 'UPDATING_DETAILS'])
                ->orderByDesc('created_at')
                ->orderByDesc('id') // tie-breaker: newest id wins
                ->get(['id', 'survey_id']);

            if ($progresses->count() <= 1) {
                continue;
            }

            $membersAffected++;
            $keep = $progresses->first();    // most recently created -> stays active
            $cancel = $progresses->slice(1); // everything older -> cancelled

            foreach ($cancel as $row) {
                $idsToCancel[] = $row->id;
            }

            if (count($sampleRows) < 15) {
                $sampleRows[] = [$mid, $keep->id, $keep->survey_id, $cancel->count()];
            }
        }

        $pendingSms = empty($idsToCancel)
            ? 0
            : SMSInbox::whereIn('survey_progress_id', $idsToCancel)->where('status', 'pending')->count();

        $this->newLine();
        $this->table(['Metric', 'Count'], [
            ['Members affected', number_format($membersAffected)],
            ['Progress records to CANCEL', number_format(count($idsToCancel))],
            ['Unsent (pending) SMS to neutralize', number_format($pendingSms)],
        ]);

        if (!empty($sampleRows)) {
            $this->newLine();
            $this->info('Sample (first ' . count($sampleRows) . '):');
            $this->table(['Member', 'Keep progress', 'Keep survey', '# cancelled'], $sampleRows);
        }

        if (empty($idsToCancel)) {
            return self::SUCCESS;
        }

        if ($dryRun) {
            $this->newLine();
            $this->comment('DRY RUN — nothing changed. Re-run without --dry-run to apply.');
            return self::SUCCESS;
        }

        $cancelled = 0;
        foreach (array_chunk($idsToCancel, 1000) as $batch) {
            DB::transaction(function () use ($batch, &$cancelled) {
                SMSInbox::whereIn('survey_progress_id', $batch)
                    ->where('status', 'pending')
                    ->update(['status' => 'cancelled']);
                $cancelled += SurveyProgress::whereIn('id', $batch)
                    ->update([
                        'status' => 'CANCELLED',
                        'open_progress_guard' => null,
                    ]);
            });
        }

        $this->newLine();
        $this->info('✅ Cancelled ' . number_format($cancelled) . ' overlapping progress record(s) across ' . number_format($membersAffected) . ' member(s). Each member now has at most one active survey.');

        return self::SUCCESS;
    }
}
