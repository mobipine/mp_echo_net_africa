<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\SurveyProgress;
use App\Models\SMSInbox;

class RecalculateSurveyReminders extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'survey:recalculate-reminders';

    /**
     * The console command description.
     */
    protected $description = 'Recalculate number_of_reminders for all survey progress records based on historical SMS reminders';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->info("Starting recalculation of survey reminders...");

        $progressList = SurveyProgress::all();

    foreach ($progressList as $progress) {
    // Count all reminder messages sent to this member
    $count = SMSInbox::where('member_id', $progress->member_id)
        ->where('is_reminder', true)
        ->where('status', 'SENT')
        ->count();

    // Update survey_progress record
    $progress->update([
        'number_of_reminders' => $count,
    ]);

    $this->info("Updated Member ID {$progress->member_id}, Survey ID {$progress->survey_id}: $count reminders.");
}



        $this->info("Recalculation completed.");

        return 0;
    }
}
