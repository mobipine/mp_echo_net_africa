<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Group;
use App\Models\GroupSurvey;
use App\Models\SMSInbox;
use App\Models\Survey;
use App\Models\SurveyProgress;
use Illuminate\Support\Facades\Log;

class DispatchDueSurveysCommand extends Command
{
    protected $signature = 'surveys:due-dispatch';
    protected $description = 'Dispatch surveys to eligible members based on order and stage';

    public function handle(): void
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
            $survey = Survey::find($assignment->survey_id);

            if (!$group || !$survey) continue;

            $this->info("Dispatching survey '{$survey->title}' to group '{$group->name}'");

            $members = $group->members()->where('is_active', true)->get();
            $firstQuestion = getNextQuestion($survey->id, null, null);

            if (!$firstQuestion) {
                Log::info("Survey '{$survey->title}' has no questions.");
                continue;
            }

            $surveyOrder = $survey->order;
            $sentCount = 0;

            foreach ($members as $member) {

                // ===== Stage and Survey Order Check =====
                if ($surveyOrder === 1) {
                    if ($member->stage !== 'New'){
                        Log::info("$member->name has done the survey $survey->title skipping him");
                        continue;
                    }
                    
                } else {
                    $previousSurvey = Survey::where('order', $surveyOrder - 1)->first();
                    if (!$previousSurvey) continue;

                    $expectedStage = str_replace(' ', '', ucfirst($previousSurvey->title)) . 'Completed';
                    if ($member->stage !== $expectedStage){
                        Log::info("$member->name is in another stage skipping him");
                        continue;
                    } 
                }

                // ===== Check existing progress =====
                $progress = SurveyProgress::where('member_id', $member->id)
                    ->where('survey_id', $survey->id)
                    ->whereNull('completed_at')
                    ->first();

                // Skip if uniqueness is on and member has already done the survey
                if ($progress && $survey->participant_uniqueness) {
                    Log::info("{$survey->title} has uniqueness on. Skipping {$member->phone}");
                    continue;
                }

                // Cancel previous incomplete progress if exists
                if ($progress) {
                    $progress->update(['status' => 'CANCELLED']);
                }

                // ===== Create new survey progress =====
                $newProgress = SurveyProgress::create([
                    'survey_id' => $survey->id,
                    'member_id' => $member->id,
                    'current_question_id' => $firstQuestion->id,
                    'last_dispatched_at' => now(),
                    'has_responded' => false,
                    'source' => 'manual',
                    'channel' => $assignment->channel,
                ]);

                // ===== Send SMS =====
                $message = formartQuestion($firstQuestion, $member, $survey);
                try {
                    SMSInbox::create([
                        'message' => $message,
                        'phone_number' => $member->phone,
                        'member_id' => $member->id,
                        'channel' => $assignment->channel,
                    ]);
                } catch (\Exception $e) {
                    Log::error("Failed to send SMS to {$member->name}: {$e->getMessage()}");
                }

                // ===== Update Member Stage =====
                $member->update([
                    'stage' => str_replace(' ', '', ucfirst($survey->title)) . 'InProgress'
                ]);

                $sentCount++;
            }

            Log::info("First question of survey '{$survey->title}' dispatched to {$sentCount} members.");

            // Mark the survey assignment as dispatched
            $assignment->update(['was_dispatched' => true]);
        }

        $this->info("Dispatched jobs for {$dueAssignments->count()} survey assignments.");
    }
}
