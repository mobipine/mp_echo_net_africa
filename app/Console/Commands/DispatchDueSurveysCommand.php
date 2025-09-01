<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use App\Jobs\SendSurveyToGroupJob;
use App\Models\Group;
use App\Models\Survey;

class DispatchDueSurveysCommand extends Command
{
    protected $signature = 'surveys:dispatch-due';
    protected $description = 'Check for automated surveys that are due and dispatch them to groups.';

    public function handle(): void
    {
        
        $dueAssignments = DB::table('group_survey')
                            ->where('automated', true) // Check the 'automated' flag on the pivot
                            ->where('was_dispatched', false) // Ensure it hasn't been sent yet
                            ->where('starts_at', '<=', now()) // Start time has passed
                            ->get();

        if ($dueAssignments->isEmpty()) {
            $this->info('No automated survey assignments due for dispatch.');
            return;
        }

        foreach ($dueAssignments as $assignment) {
            $group = Group::find($assignment->group_id);
            $survey = Survey::find($assignment->survey_id);

            if ($group && $survey) {
                $this->info("Dispatching survey '{$survey->title}' to group '{$group->name}'");
                // Dispatch the job for this specific group-survey combo
                SendSurveyToGroupJob::dispatch($group, $survey);

                // Mark as dispatched to prevent duplicate sends
                DB::table('group_survey')
                  ->where('group_id', $assignment->group_id)
                  ->where('survey_id', $assignment->survey_id)
                  ->update(['was_dispatched' => true]);
            }
        }

        $this->info("Dispatched jobs for {$dueAssignments->count()} survey assignments.");
    }
}