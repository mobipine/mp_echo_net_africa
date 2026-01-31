<?php

namespace App\Console\Commands;

use App\Models\Group;
use App\Models\Survey;
use App\Models\SurveyProgress;
use Illuminate\Console\Command;

class FixCompletedSurveyProgressStatus extends Command
{
    protected $signature = 'survey:fix-completed-status
                            {--survey= : Limit to this survey ID}
                            {--group= : Limit to members of this group ID}
                            {--dry-run : Show what would be updated without making changes}';

    protected $description = 'Set status to COMPLETED for SurveyProgress records that have completed_at set but status is not COMPLETED';

    public function handle(): int
    {
        $surveyId = $this->option('survey') ? (int) $this->option('survey') : null;
        $groupId = $this->option('group') ? (int) $this->option('group') : null;
        $dryRun = $this->option('dry-run');

        $query = SurveyProgress::whereNotNull('completed_at')
            ->where('status', '!=', 'COMPLETED');

        if ($surveyId !== null) {
            $survey = Survey::find($surveyId);
            if (!$survey) {
                $this->error("Survey with ID {$surveyId} not found.");
                return 1;
            }
            $query->where('survey_id', $surveyId);
            $this->info("Survey: {$survey->title} (ID: {$surveyId})");
        }

        if ($groupId !== null) {
            $group = Group::find($groupId);
            if (!$group) {
                $this->error("Group with ID {$groupId} not found.");
                return 1;
            }
            $memberIds = $group->members()->pluck('members.id')->toArray();
            $query->whereIn('member_id', $memberIds);
            $this->info("Group: {$group->name} (ID: {$groupId})");
        }

        $progresses = $query->orderBy('id')->get();
        $count = $progresses->count();

        if ($count === 0) {
            $this->info('No SurveyProgress records found with completed_at set and status != COMPLETED.');
            return 0;
        }

        $this->newLine();
        $this->info("Found {$count} record(s) with completed_at set but status is not COMPLETED.");
        $this->newLine();

        $rows = $progresses->map(fn ($p) => [
            $p->id,
            $p->survey_id,
            $p->member_id,
            $p->completed_at?->format('Y-m-d H:i:s') ?? 'N/A',
            $p->status,
        ])->toArray();

        $this->table(
            ['Progress ID', 'Survey ID', 'Member ID', 'completed_at', 'Current status'],
            $rows
        );

        if ($dryRun) {
            $this->warn('--- DRY RUN: No changes made ---');
            $this->comment("Would update {$count} record(s) to status COMPLETED. Run without --dry-run to apply.");
            return 0;
        }

        $ids = $progresses->pluck('id')->toArray();
        $updated = SurveyProgress::whereIn('id', $ids)->update(['status' => 'COMPLETED']);
        $this->info("Updated {$updated} SurveyProgress record(s) to status COMPLETED.");

        return 0;
    }
}
