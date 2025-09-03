<?php

namespace App\Console\Commands;

use App\Models\SurveyProgress;
use App\Models\SurveyQuestion;
use App\Models\SurveyResponse; // You'll need this to link responses to progress
use App\Services\UjumbeSMS;
use Illuminate\Console\Command;
use Carbon\Carbon;
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
                 // Should not happen, but a safeguard
                Log::warning("No current question found for survey progress ID: {$progress->id}.");
                continue;
            }

            // Check if the user has responded since the last dispatch
            $hasResponded = SurveyResponse::where('msisdn', $member->phone)
                                        ->where('survey_id', $survey->id)
                                        ->where('question_id', $currentQuestion->id)
                                        ->exists();

            if ($hasResponded) {
                // User has responded, send the next question
                $nextQuestion = $currentQuestion->getNextQuestion($survey->id);
                if ($nextQuestion) {
                    $message = $this->formatQuestionMessage($nextQuestion);
                    try {
                        $smsService->send($member->phone, $message);
                        $progress->update([
                            'current_question_id' => $nextQuestion->id,
                            'last_dispatched_at' => now(),
                            'has_responded' => false, // Reset for the new question
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
            } elseif (Carbon::parse($progress->last_dispatched_at)->diffInDays(now()) >= 3) {
                // No response and it's been more than 3 days, resend the last question
                $message = $this->formatQuestionMessage($currentQuestion, true); // Add a reminder prefix
                try {
                    $smsService->send($member->phone, $message);
                    $progress->update(['last_dispatched_at' => now()]); // Update timestamp for next check
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