<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\MemberRecurrentQuestion;
use App\Models\SurveyQuestion;
use App\Models\SMSInbox;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class DispatchRecurrentQuestions extends Command
{
    protected $signature = 'surveys:dispatch-recurrent';
    protected $description = 'Check for recurrent questions that are due and dispatch them to members.';

    public function handle(): void
    {
        $this->info("Checking for recurrent questions due for dispatch...");

        $now = now();

        $dueQuestions = MemberRecurrentQuestion::where('next_dispatch_at', '<=', $now)->get();

        if ($dueQuestions->isEmpty()) {
            $this->info('No recurrent questions are due at this time.');
            return;
        }

        foreach ($dueQuestions as $recurrent) {
            $member = $recurrent->member;
            $question = $recurrent->question;

            if (!$member || !$question) {
                Log::warning("Member or Question not found for MemberRecurrentQuestion ID: {$recurrent->id}");
                continue;
            }

            $message = formartQuestion($question, $member, $survey = null); // survey is optional for recurrent
            try {
                SMSInbox::create([
                    'message' => $message,
                    'phone_number' => $member->phone,
                    'member_id' => $member->id,
                    'channel' => 'sms', // adjust if you track channels
                ]);

                Log::info("Dispatched recurrent question ID {$question->id} to member {$member->phone}");
            } catch (\Exception $e) {
                Log::error("Failed to dispatch recurrent question ID {$question->id} to member {$member->phone}: " . $e->getMessage());
            }

            // Increment sent_count
            $recurrent->sent_count += 1;

            // Calculate next dispatch if recur_interval is set
            if ($question->recur_interval && $question->recur_unit) {
                $nextDispatch = Carbon::now();
                switch ($question->recur_unit) {
                    case 'seconds': $nextDispatch->addSeconds($question->recur_interval); break;
                    case 'minutes': $nextDispatch->addMinutes($question->recur_interval); break;
                    case 'hours': $nextDispatch->addHours($question->recur_interval); break;
                    case 'days': $nextDispatch->addDays($question->recur_interval); break;
                    case 'weeks': $nextDispatch->addWeeks($question->recur_interval); break;
                    case 'months': $nextDispatch->addMonths($question->recur_interval); break;
                }
                $recurrent->next_dispatch_at = $nextDispatch;
            } else {
                // If no interval set, don't reschedule
                $recurrent->next_dispatch_at = null;
            }

            $recurrent->save();
        }

        $this->info("Finished dispatching recurrent questions.");
    }
}
