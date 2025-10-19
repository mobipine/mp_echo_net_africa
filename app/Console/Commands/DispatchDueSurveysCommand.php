<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use App\Jobs\SendSurveyToGroupJob;
use App\Models\Group;
use App\Models\GroupSurvey;
use App\Models\SMSInbox;
use App\Models\Survey;
use App\Models\SurveyProgress;
use App\Models\SurveyQuestion;
use Illuminate\Support\Facades\Log;

class DispatchDueSurveysCommand extends Command
{
    protected $signature = 'surveys:dispatch-due';
    protected $description = 'Check for automated surveys that are due and dispatch them to groups.';

    public function handle(): void
    {

        $dueAssignments = GroupSurvey::where('automated', true) // Check the 'automated' flag on the pivot
            ->where('was_dispatched', false) // Ensure it hasn't been sent yet
            ->where('starts_at', '<=', now()) // Start time has passed
            ->get();

        if ($dueAssignments->isEmpty()) {
            $this->info('No automated survey assignments due for dispatch.');
            Log::info("No Due Assignments, exiting the handle function of dispatch due survey command");
            return;
        }

        foreach ($dueAssignments as $assignment) {
            $group = Group::find($assignment->group_id);
            $survey = Survey::find($assignment->survey_id);

            if ($group && $survey) {
                $this->info("Dispatching survey '{$survey->title}' to group '{$group->name}'");
                // Dispatch the job for this specific group-survey combo
                // SendSurveyToGroupJob::dispatch($group, $survey);

                $members = $group->members()->where('is_active', true)->get();
                $firstQuestion = getNextQuestion($survey->id, $response = null, $current_question_id = null);

                if (!$firstQuestion) {
                    Log::info("Survey '{$survey->title}' has no questions. Exiting the  handle function of dispatch due survey command. No SMS sent.");
                    return;
                }

                Log::info("The first Question of {$survey->title} is {$firstQuestion} from the flow");

                //Formatting the question if multiple choice              

                $sentCount = 0;

                foreach ($members as $member) {

                    //formart the quiz

                    $message=formartQuestion($firstQuestion,$member);
                    Log::info("This is the message ".$message);

                    if (!empty($member->phone)) {
                        //find a record with the survey id and member id that is not completed
                        $progress = SurveyProgress::where('member_id', $member->id)
                            ->whereNull('completed_at')
                            ->first();

                        if ($progress) {
                            //check if survey has member uniquesness
                            $survey = $survey;
                            $p_unique = $survey->participant_uniqueness;
                            if ($p_unique) {
                                Log::info("$survey->title has participant uniqueness turned on and $member->phone already has done the survey. Proceding to the next member");
                                continue;
                                //'Survey already started.'
                            } else {
                                //update all previous progress records with the same survey_id and member_id status to CANCELLED
                                SurveyProgress::where('member_id', $member->id)
                                    ->whereNull('completed_at')
                                    ->update(['status' => 'CANCELLED']);


                                //create a new progress record
                                $newProgress = SurveyProgress::create([
                                    'survey_id' => $survey->id,
                                    'member_id' => $member->id,
                                    'current_question_id' => $firstQuestion->id,
                                    'last_dispatched_at' => now(),
                                    'has_responded' => false,
                                    'source' => 'manual'
                                ]);

                                try {
                                    SMSInbox::create([
                                        'message'      => $message,
                                        'phone_number' => $member->phone,
                                        'member_id'    => $member->id,
                                    ]);

                                    Log::info('Record created in SMS Inbox');
                                } catch (\Exception $e) {
                                    Log::error("Failed to send initial SMS to {$member->name}: " . $e->getMessage());
                                }

                                $sentCount++;
                            }

                        } else {

                            $sentCount++;
                            //create a new progress record
                            $newProgress = SurveyProgress::create([
                                'survey_id' => $survey->id,
                                'member_id' => $member->id,
                                'current_question_id' => $firstQuestion->id,
                                'last_dispatched_at' => now(),
                                'has_responded' => false,
                                'source' => 'manual'
                            ]);

                            try {
                                SMSInbox::create([
                                    'message'      => $message,
                                    'phone_number' => $member->phone,
                                    'member_id'    => $member->id,
                                ]);

                                Log::info('Record created in SMS Inbox');
                            } catch (\Exception $e) {
                                Log::error("Failed to send initial SMS to {$member->name}: " . $e->getMessage());
                            }
                        }
                    }
                }

                Log::info("First question of survey '{$survey->title}' dispatched to {$sentCount} members in group '{$group->name}'.");

                // Mark as dispatched to prevent duplicate sends
                // DB::table('group_survey')
                //   ->where('group_id', $assignment->group_id)
                //   ->where('survey_id', $assignment->survey_id)
                //   ->update(['was_dispatched' => true]);

                GroupSurvey::where('group_id', $assignment->group_id)
                    ->where('survey_id', $assignment->survey_id)
                    ->update(['was_dispatched' => true]);
            }
        }

        $this->info("Dispatched jobs for {$dueAssignments->count()} survey assignments.");
    }

}
