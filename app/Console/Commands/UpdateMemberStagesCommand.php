<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Member;
use App\Models\SurveyProgress;
use App\Models\Survey;
use Illuminate\Support\Facades\Log;

class UpdateMemberStagesCommand extends Command
{
    protected $signature = 'update:member-stages';
    protected $description = 'Update member stages based on survey progress (completed or in progress)';

    public function handle(): void
    {
        $this->info("Checking and updating member stages...");

        // Get all members who have participated in any survey
        $members = Member::whereHas('surveyProgresses')->get();

        if ($members->isEmpty()) {
            $this->info("No members with survey progress found.");
            return;
        }

        foreach ($members as $member) {
            // Get latest survey progress
            $latestProgress = SurveyProgress::where('member_id', $member->id)
                ->latest('updated_at')
                ->first();

            if (!$latestProgress) {
                continue;
            }

            // Fetch the survey name
            $survey = Survey::find($latestProgress->survey_id);
            if (!$survey) {
                Log::warning("Survey ID {$latestProgress->survey_id} not found for member {$member->id}");
                continue;
            }

            // Determine stage name based on completion
            if ($latestProgress->completed_at) {
                $newStage = str_replace(' ', '', ucfirst($survey->title)) . 'Completed';
            } else {
                $newStage = str_replace(' ', '', ucfirst($survey->title)) . 'InProgress';
            }

            // Update member stage if it changed
            if ($member->stage !== $newStage) {
                $member->update(['stage' => $newStage]);
                Log::info("Updated {$member->name}'s stage to {$newStage}");
                $this->info("✔ {$member->name} → {$newStage}");
            }
        }

        $this->info("Member stages successfully updated.");
    }
}
