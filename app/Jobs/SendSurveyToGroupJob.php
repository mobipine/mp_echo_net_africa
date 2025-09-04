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
                // Check if member already has a progress record for this survey
                $progress = SurveyProgress::firstOrCreate(
                    ['survey_id' => $this->survey->id, 'member_id' => $member->id],
                    [
                        'current_question_id' => $firstQuestion->id,
                        'last_dispatched_at' => now(),
                        'has_responded' => false
                    ]
                );

                // Only send if this is a new survey assignment
               if ($progress->wasRecentlyCreated) {
                    try {
                        SMSInbox::create([
                            'message'      => $message,
                            'status'       => 'pending',
                            'phone_number' => $member->phone,
                            'member_id'    => $member->id,
                        ]);

                        Log::info('Record created in SMS Inbox');
                    } catch (\Exception $e) {
                        Log::error("Failed to send initial SMS to {$member->name}: " . $e->getMessage());
                    }
                } else {
                    Log::info("Member {$member->phone} already has a progress record for this survey. Skipping initial dispatch.");
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