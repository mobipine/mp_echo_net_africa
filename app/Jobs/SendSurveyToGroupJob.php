<?php

namespace App\Jobs;

use App\Models\Group;
use App\Models\SMSInbox;
use App\Models\Survey;
use App\Models\SurveyProgress;
use App\Models\SurveyQuestion;
use App\Services\UjumbeSMS;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SendSurveyToGroupJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public array $groupIds, public Survey $survey) {}

    public function handle(): void
    {
        Log::info("In the Send Survey To Multiple Groups Job");
        // Find the first question once, outside the group loop, as it's the same for all groups.
        $firstQuestion = getNextQuestion($this->survey->id, $response = null, $current_question_id = null);

        if (!$firstQuestion) {
            Log::info("Survey '{$this->survey->title}' has no questions. No SMS sent.");
            return;
        }
         $firstQuestion = getNextQuestion($this->survey->id, $response = null, $current_question_id = null);

             if (!$firstQuestion) {
                 Log::info("Survey '{$this->survey->title}' has no questions. Exiting the  handle function of dispatch due survey command. No SMS sent.");
                 return;
             }
             Log::info("The first Question of {$this->survey->title} is {$firstQuestion} from the flow");
             //Formatting the question if multiple choice
             Log::info("the question is $firstQuestion->answer_strictness");

             

        Log::info("The first Question of {$this->survey->title} is {$firstQuestion->question} from the flow");

        foreach ($this->groupIds as $groupId) {
            $group = Group::find($groupId);

            if (!$group) {
                Log::warning("Group with ID {$groupId} not found. Skipping.");
                continue;
            }

           
            $members = $group->members()->where('is_active', true)->get();
            $sentCount = 0;

            foreach ($members as $member) {
                if ($firstQuestion->answer_strictness == "Multiple Choice") {
                    $message = "{$firstQuestion->question}\n\n"; 

                    $letters = [];
                    foreach ($firstQuestion->possible_answers as $answer) {
                        $message .= "{$answer['letter']}. {$answer['answer']}\n";
                        $letters[] = $answer['letter'];
                    }
                    
                    // Dynamically build the letter options string
                    if (count($letters) === 1) {
                        $letterText = $letters[0];
                    } elseif (count($letters) === 2) {
                        $letterText = $letters[0] . " or " . $letters[1];
                    } else {
                        $lastLetter = array_pop($letters);
                        $letterText = implode(', ', $letters) . " or " . $lastLetter;
                    }
                    
                    $message .= "\nPlease reply with the letter {$letterText}.";
                    Log::info("The message to be sent is {$message}");
                } else {
                    $message = $firstQuestion->question;
                    if ($firstQuestion->answer_data_type === 'Strictly Number') {
                        $message .= "\n💡 *Note: Your answer should be a number.*";
                    } elseif ($firstQuestion->answer_data_type === 'Alphanumeric') {
                        $message .= "\n💡 *Note: Your answer should contain only letters and numbers.*";
                    }
                }
                
                $placeholders = [
                        '{member}' => $member->name,
                        '{group}' => $member->group->name,
                        '{id}' => $member->national_id,
                        '{gender}'=>$member->gender,
                        '{dob}'=> \Carbon\Carbon::parse($member->dob)->format('Y'),
                        '{LIP}' => $member?->group?->localImplementingPartner?->name,
                        '{month}' => \Carbon\Carbon::now()->monthName,
                    ];
                    $message = str_replace(
                        array_keys($placeholders),
                        array_values($placeholders),
                        $message
                    );
                Log::info("The message to be sent is {$message}");
                    
                if (empty($member->phone)) {
                    continue;
                }

                $progress = SurveyProgress::where('survey_id', $this->survey->id)
                    ->where('member_id', $member->id)
                    ->whereNull('completed_at')
                    ->first();

                // Simplify logic to handle both cases efficiently
                if ($progress) {
                    if ($this->survey->participant_uniqueness) {
                        Log::info("{$this->survey->title} has participant uniqueness turned on, and {$member->phone} has already started. Skipping him.");
                        continue;
                    }
                    Log::info("{$member->name} has a record in the survey progress table but participant uniqueness for {$this->survey->title} is off. Cancelling previous records...");

                    // If not unique, cancel all previous incomplete progress records
                    SurveyProgress::where('survey_id', $this->survey->id)
                        ->where('member_id', $member->id)
                        ->whereNull('completed_at')
                        ->update(['status' => 'CANCELLED']);
                }
                Log::info("Creating a new record for {$member->name} in the survey progress table");

                // Create a new progress record for the member
                $newProgress = SurveyProgress::create([
                    'survey_id' => $this->survey->id,
                    'member_id' => $member->id,
                    'current_question_id' => $firstQuestion->id,
                    'last_dispatched_at' => now(),
                    'has_responded' => false,
                    'source' => 'manual',
                ]);

                Log::info("Saving the first question for {$this->survey->title} in the SMSInbox table and preparing it to be sent.");
                // Prepare and log the SMS for sending
                
                
                try {
                    SMSInbox::create([
                        'message'      => $message,
                        'phone_number' => $member->phone,
                        'member_id'    => $member->id,
                        'survey_progress_id' => $newProgress->id,
                    ]);

                    // Here you would call your actual SMS service, e.g., UjumbeSMS::send($member->phone, $message);
                    
                    Log::info("SMS Inbox record created for {$member->phone}.\n\n");
                    $sentCount++;
                } catch (\Exception $e) {
                    Log::error("Failed to create SMS Inbox record for {$member->name}: " . $e->getMessage());
                }
            }

            Log::info("First question of survey '{$this->survey->title}' dispatched to {$sentCount} members in group '{$group->name}'.\n\n");
        }
    }
}