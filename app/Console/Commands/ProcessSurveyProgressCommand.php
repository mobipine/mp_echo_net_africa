<?php

namespace App\Console\Commands;

use App\Models\SurveyProgress;
use App\Models\SurveyQuestion;
use App\Models\SurveyResponse;
use App\Services\UjumbeSMS;
use Illuminate\Console\Command;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ProcessSurveyProgressCommand extends Command
{
    protected $signature = 'surveys:process-progress';
    protected $description = 'Sends next survey questions or reminders based on participant progress.';

    public function handle(UjumbeSMS $smsService): void
    {
        $progressRecords = SurveyProgress::with(['survey', 'currentQuestion', 'member'])
            ->whereNull('completed_at')
            ->get();

        foreach ($progressRecords as $progress) {
            $member = $progress->member;
            $survey = $progress->survey;
            $currentQuestion = $progress->currentQuestion;

            if (!$currentQuestion) {
                Log::warning("No current question found for survey progress ID: {$progress->id}.");
                continue;
            }

            // Get the pivot data for the group-survey relationship
            $groupSurvey = DB::table('group_survey')
                ->where('survey_id', $survey->id)
                ->first();

            // If no pivot data exists, skip this record to prevent errors
            if (!$groupSurvey) {
                Log::warning("Group-survey relationship not found for survey ID: {$survey->id}.");
                continue;
            }

            $interval = $groupSurvey->question_interval ?? 3; // Use the pivot value, or default to 3 days
            $unit = $groupSurvey->question_interval_unit ?? 'days'; // Use the pivot value, or default to 'days'

            // Check if the time since the last dispatch has exceeded the defined interval
            $lastDispatched = Carbon::parse($progress->last_dispatched_at);
            Log::info("Last Dispatched $lastDispatched");

            $nextDue=$lastDispatched->add($interval, $unit);
            Log::info("Next Due Date $nextDue");

            $isDue = $nextDue->lessThanOrEqualTo(now()); 

            // Check if the user has responded since the last dispatch
            $hasResponded = SurveyProgress::where('member_id', $member->id)
                ->where('survey_id', $survey->id)
                ->where('has_responded', true)
                ->exists();
                
            
            $endDate=DB::table('group_survey')
                        ->where('group_id',$member->group_id)
                        ->where('survey_id',$survey->id)
                        ->value('ends_at');
            Log::info("The survey ends on $endDate");

            if (!$isDue) {
                Log::info("Survey progress ID: {$progress->id} is not yet due for processing.");
                continue;
            }

            
            if ($hasResponded &&  ($endDate === null || now()->lessThanOrEqualTo(Carbon::parse($endDate)))) {
                // User has responded, send the next question
                $nextQuestion = $currentQuestion->getNextQuestion($survey->id);

                if ($nextQuestion) {
                    $message = $this->formatQuestionMessage($nextQuestion);
                    try {
                        $smsService->send($member->phone, $message);
                        $progress->update([
                            'current_question_id' => $nextQuestion->id,
                            'last_dispatched_at' => now(),
                            'has_responded' => false,
                        ]);
                        Log::info("Next question sent to {$member->phone} for survey {$survey->title}.");
                    } catch (\Exception $e) {
                        Log::error("Failed to send next question to {$member->phone}: " . $e->getMessage());
                    }
                } else {
                    // All questions answered, mark as complete
                    $progress->update(['completed_at' => now()]);
                    Log::info("Survey {$survey->title} completed by {$member->phone}.");
                }
            } else {
                // No response, send a reminder
                $message = $this->formatQuestionMessage($currentQuestion, true);
                try {
                    $smsService->send($member->phone, $message);
                    $progress->update(['last_dispatched_at' => now()]);
                    Log::info("Reminder sent to {$member->phone} for survey {$survey->title}.");
                } catch (\Exception $e) {
                    Log::error("Failed to send reminder to {$member->phone}: " . $e->getMessage());
                }
            }
        }
    }

    protected function formatQuestionMessage(SurveyQuestion $question, bool $isReminder = false): string
    {
        $prefix = $isReminder ? "Reminder: " : "";
        return "{$prefix}Question: {$question->question}\nPlease reply with your answer.";
    }
}