<?php

namespace App\Jobs;

use App\Models\Group;
use App\Models\Survey;
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

    public function handle(UjumbeSMS $smsService): void
    {
        
        $members = $this->group->members()->where('is_active', true)->get();

        
        $message = $this->formatSurveyMessage($this->survey);

        foreach ($members as $member) {
            if (!empty($member->phone)) {
               
                try {
                    $smsService->send($member->phone, $message);
                    
                    Log::debug("SMS sent to {$member->phone} for survey '{$this->survey->title}'.");
                } catch (\Exception $e) {
                    
                    Log::error("Failed to send SMS to {$member->name}: " . $e->getMessage());
                    
                }
            }
        }

        Log::info("Survey '{$this->survey->title}' sent to group '{$this->group->name}'.");
    }

    protected function formatSurveyMessage(Survey $survey): string
    {
        
        $message = "New Survey: {$survey->title}\n\n";

        foreach ($survey->questions as $index => $question) {
            $message .= ($index + 1) . ". {$question->question}\n";
        }

        $message .= "\nPlease reply with your answers.";
       
        return $message;
    }
}