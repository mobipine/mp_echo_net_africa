<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\SMSInbox;
use App\Models\SurveyResponse;

class UpdateCreditsUsed extends Command
{
    protected $signature = 'credits:update';
    protected $description = 'Update credits_used column based on message length';

    public function handle()
    {
        $this->updateSmsInboxes();
        $this->updateSurveyResponses();

        $this->info('Credits updated successfully.');
    }

    private function updateSmsInboxes()
    {
        $this->info("Updating sms_inboxes...");

        SMSInbox::chunk(500, function ($records) {
            foreach ($records as $record) {
                $message = $record->message ?? '';
                $length = mb_strlen($message);

                $credits = $length > 0 ? (int) ceil($length / 160) : 0;

                $record->credits_used = $credits;
                $record->save();
            }
        });

        $this->info("sms_inboxes updated.");
    }

    private function updateSurveyResponses()
    {
        $this->info("Updating survey_responses...");

        SurveyResponse::chunk(500, function ($records) {
            foreach ($records as $record) {
                $text = $record->survey_response ?? '';
                $length = mb_strlen($text);

                $credits = $length > 0 ? (int) ceil($length / 160) : 0;

                $record->credits_used = $credits;
                $record->save();
            }
        });

        $this->info("survey_responses updated.");
    }
}
