<?php

namespace App\Console\Commands;

use App\Models\Group;
use App\Models\Member;
use App\Models\Survey;
use App\Models\SurveyProgress;
use Illuminate\Console\Command;

class CheckSurveyProgressDuplicates extends Command
{
    protected $signature = 'survey:check-duplicate-progress
                            {survey_id : The survey ID (e.g. 7)}
                            {--group= : Limit to members of this group ID}
                            {--fix : Delete the latest duplicate and set the kept progress to ACTIVE}';

    protected $description = 'Check for duplicate survey progresses for a survey (optionally scoped to a group), show counts, and optionally fix by deleting the latest duplicate';

    public function handle(): int
    {
        $surveyId = (int) $this->argument('survey_id');
        $groupId = $this->option('group') ? (int) $this->option('group') : null;
        $fix = $this->option('fix');

        $survey = Survey::find($surveyId);
        if (!$survey) {
            $this->error("Survey with ID {$surveyId} not found.");
            return 1;
        }

        $memberIds = null;
        $groupName = null;
        if ($groupId !== null) {
            $group = Group::find($groupId);
            if (!$group) {
                $this->error("Group with ID {$groupId} not found.");
                return 1;
            }
            $memberIds = $group->members()->pluck('members.id')->toArray();
            $groupName = $group->name;
        }

        $this->info("Survey: {$survey->title} (ID: {$surveyId})");
        if ($groupName) {
            $this->info("Group: {$groupName} (ID: {$groupId})");
        }
        $this->newLine();

        // Base query: survey progresses for this survey
        $progressQuery = SurveyProgress::where('survey_id', $surveyId);
        if ($memberIds !== null) {
            $progressQuery->whereIn('member_id', $memberIds);
        }

        // Members who have at least one progress (received the survey)
        $memberIdsWithProgress = (clone $progressQuery)->distinct()->pluck('member_id')->toArray();

        // Duplicates: member_ids that have more than one progress for this survey
        $duplicateMemberIds = (clone $progressQuery)
            ->select('member_id')
            ->groupBy('member_id')
            ->havingRaw('COUNT(*) > 1')
            ->pluck('member_id')
            ->toArray();

        $duplicateCount = count($duplicateMemberIds);
        $totalProgressRecords = (clone $progressQuery)->count();
        $uniqueMembersWithProgress = count(array_unique($memberIdsWithProgress));

        // Members in scope (all or group) who have NOT received the survey
        if ($memberIds !== null) {
            $totalMembersInGroup = count($memberIds);
            $membersNotReceived = array_diff($memberIds, $memberIdsWithProgress);
            $notReceivedCount = count($membersNotReceived);
        } else {
            $totalMembersInGroup = null;
            $notReceivedCount = null;
        }

        // ---- Report ----
        $this->info('--- Summary ---');
        $this->table(
            ['Metric', 'Value'],
            [
                ['Total survey progress records (in scope)', number_format($totalProgressRecords)],
                ['Unique members with at least one progress', number_format($uniqueMembersWithProgress)],
                ['Members with duplicate progress (need resolving)', number_format($duplicateCount)],
            ]
        );

        if ($totalMembersInGroup !== null) {
            $this->table(
                ['Group metric', 'Value'],
                [
                    ['Total members in group', number_format($totalMembersInGroup)],
                    ['Members who have NOT yet received this survey', number_format($notReceivedCount)],
                ]
            );
        }

        $this->newLine();
        if ($duplicateCount > 0) {
            $this->warn("Needs resolving: Yes – {$duplicateCount} member(s) have duplicate progress records.");
        } else {
            $this->info('Needs resolving: No.');
        }
        $this->newLine();

        if ($duplicateCount > 0) {
            $this->warn("Duplicate progress records: {$duplicateCount} member(s) have more than one progress for this survey.");
            $this->newLine();

            $rows = [];
            foreach ($duplicateMemberIds as $mid) {
                $member = Member::find($mid);
                $progresses = SurveyProgress::where('survey_id', $surveyId)
                    ->where('member_id', $mid)
                    ->orderBy('id')
                    ->get();
                $rows[] = [
                    $mid,
                    $member ? $member->name : 'N/A',
                    $progresses->count(),
                    $progresses->pluck('id')->join(', '),
                    $progresses->pluck('status')->join(', '),
                ];
            }
            $this->info('Duplicate breakdown (member_id, name, count, progress IDs, statuses):');
            $this->table(
                ['Member ID', 'Name', 'Progress count', 'Progress IDs', 'Statuses'],
                $rows
            );

            if ($fix) {
                if (!$this->confirm('Apply fix: for each duplicate member, keep the OLDEST progress (set ACTIVE) and DELETE the latest one(s)?', false)) {
                    $this->info('Fix cancelled.');
                    return 0;
                }
                $fixed = 0;
                $deleted = 0;
                foreach ($duplicateMemberIds as $mid) {
                    $progresses = SurveyProgress::where('survey_id', $surveyId)
                        ->where('member_id', $mid)
                        ->orderBy('id')
                        ->get();
                    $keep = $progresses->first();
                    $toDelete = $progresses->slice(1);
                    $keep->update(['status' => 'ACTIVE']);
                    $fixed++;
                    foreach ($toDelete as $p) {
                        $p->delete();
                        $deleted++;
                    }
                }
                $this->info("Fix applied: kept and set ACTIVE for {$fixed} member(s), deleted {$deleted} duplicate progress record(s).");
            } else {
                $this->comment('Run with --fix to delete the latest duplicate(s) per member and set the kept progress to ACTIVE.');
            }
        } else {
            $this->info('No duplicate progress records found. Nothing to fix.');
        }

        return 0;
    }
}
