<?php

namespace App\Jobs;

use App\Models\Group;
use App\Models\SMSInbox;
use App\Models\Survey;
use App\Models\SurveyProgress;
use App\Models\SurveyQuestion;
use App\Services\UjumbeSMS; // Your SMS service
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SendSurveyToGroupJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public Group $group, public Survey $survey) {}

    public function handle(): void
    {
        $members = $this->group->members()->where('is_active', true)->get();
        $firstQuestion = $this->survey->questions()->orderBy('pivot_position')->first();

        if (!$firstQuestion) {
            Log::info("Survey '{$this->survey->title}' has no questions. No SMS sent.");
            return;
        }

        $message = "New Survey: {$this->survey->title}\n\n" . $this->formatQuestionMessage($firstQuestion);
        $sentCount = 0;

        foreach ($members as $member) {
            if (!empty($member->phone)) {
                //find a record with the survey id and member id that is not completed
                $progress = SurveyProgress::where('survey_id', $this->survey->id)
                    ->where('member_id', $member->id)
                    ->whereNull('completed_at')
                    ->first();

                if ($progress) {
                    //check if survey has member uniquesness
                    $survey = $this->survey;
                    $p_unique = $survey->participant_uniqueness;
                    if ($p_unique) {
                        return;
                        //'Survey already started.'
                    } else {
                        //update all previous progress records with thesame survey_id and member_id status to CANCELLED
                        SurveyProgress::where('survey_id', $survey->id)
                            ->where('member_id', $member->id)
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

                        //send the first question
                        $message = "New Survey: {$survey->title}\n\nQuestion 1: {$firstQuestion->question}\nPlease reply with your answer.";
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
                } else {
                    //create a new progress record
                    $newProgress = SurveyProgress::create([
                        'survey_id' => $this->survey->id,
                        'member_id' => $member->id,
                        'current_question_id' => $firstQuestion->id,
                        'last_dispatched_at' => now(),
                        'has_responded' => false,
                        'source' => 'manual'
                    ]);

                    //send the first question
                    $message = "New Survey: {$this->survey->title}\n\nQuestion 1: {$firstQuestion->question}\nPlease reply with your answer.";
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

        Log::info("First question of survey '{$this->survey->title}' dispatched to {$sentCount} members in group '{$this->group->name}'.");
    }

    protected function formatQuestionMessage(SurveyQuestion $question): string
    {
        return "Question 1: {$question->question}\nPlease reply with your answer.";
    }
}
