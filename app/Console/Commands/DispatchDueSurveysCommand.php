<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\GroupSurvey;
use App\Models\Group;
use App\Models\Member;
use App\Jobs\DispatchSurveyToMemberJob;

class DispatchDueSurveysCommand extends Command
{
    protected $signature = 'surveys:due-dispatch';
    protected $description = 'Dispatch surveys to eligible members based on order and stage (queue optimized)';

    public function handle()
    {
        $dueAssignments = GroupSurvey::where('automated', true)
            ->where('was_dispatched', false)
            ->where('starts_at', '<=', now())
            ->get();

        if ($dueAssignments->isEmpty()) {
            $this->info('No automated survey assignments due.');
            return;
        }

        foreach ($dueAssignments as $assignment) {
            $group = Group::find($assignment->group_id);
            if (!$group) continue;

            $this->info("Dispatching survey '{$assignment->survey->title}' to group '{$group->name}'");

            // ===== Chunk members to avoid memory issues =====
            $group->members()->where('is_active', true)->chunk(500, function ($members) use ($assignment) {
                foreach ($members as $member) {
                    DispatchSurveyToMemberJob::dispatch($member->id, $assignment->id)->onQueue('surveys');
                }
            });

            $assignment->update(['was_dispatched' => true]);
        }

        $this->info("All due survey assignments have been queued.");
    }
}
