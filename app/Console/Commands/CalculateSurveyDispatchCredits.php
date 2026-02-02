<?php

namespace App\Console\Commands;

use App\Models\Group;
use App\Models\Member;
use App\Models\SMSInbox;
use App\Models\Survey;
use App\Models\SurveyProgress;
use App\Models\SurveyResponse;
use Illuminate\Console\Command;

class CalculateSurveyDispatchCredits extends Command
{
    protected $signature = 'survey:calculate-dispatch-credits
                            {--group= : Group ID}
                            {--survey= : Survey ID}';

    protected $description = 'Calculate total SMS credits used for a survey dispatch to a group (messages sent and received)';

    public function handle(): int
    {
        $groupId = $this->option('group');
        $surveyId = $this->option('survey');

        if (!$groupId || !$surveyId) {
            $this->error('Both --group and --survey are required.');
            $this->line('Example: php artisan survey:calculate-dispatch-credits --group=3487 --survey=7');
            return Command::FAILURE;
        }

        $group = Group::find($groupId);
        $survey = Survey::find($surveyId);

        if (!$group) {
            $this->error("Group with ID {$groupId} not found.");
            return Command::FAILURE;
        }

        if (!$survey) {
            $this->error("Survey with ID {$surveyId} not found.");
            return Command::FAILURE;
        }

        $memberIds = $group->members()
            ->pluck('members.id')
            ->merge(Member::where('group_id', $groupId)->pluck('id'))
            ->unique()
            ->values();

        $progressIds = SurveyProgress::where('survey_id', $surveyId)
            ->whereIn('member_id', $memberIds)
            ->pluck('id');

        $memberPhones = Member::whereIn('id', $memberIds)->pluck('phone')->filter()->values()->toArray();

        $this->info("Survey: {$survey->title} (ID: {$surveyId})");
        $this->info("Group: {$group->name} (ID: {$groupId})");
        $this->newLine();

        // --- SENT (SMSInbox with survey_progress_id) ---
        $sentQuery = SMSInbox::whereIn('survey_progress_id', $progressIds);
        $messagesSent = $progressIds->isEmpty() ? 0 : $sentQuery->count();
        $creditsSent = $progressIds->isEmpty()
            ? 0
            : (int) (SMSInbox::whereIn('survey_progress_id', $progressIds)
                ->selectRaw('SUM(COALESCE(NULLIF(credits_count, 0), CEIL(CHAR_LENGTH(COALESCE(message, "")) / 160))) as total')
                ->value('total') ?? 0);

        // --- REMINDERS (SMSInbox with is_reminder = true) ---
        $remindersSent = $progressIds->isEmpty()
            ? 0
            : SMSInbox::whereIn('survey_progress_id', $progressIds)->where('is_reminder', true)->count();
        $creditsReminders = $progressIds->isEmpty()
            ? 0
            : (int) (SMSInbox::whereIn('survey_progress_id', $progressIds)
                ->where('is_reminder', true)
                ->selectRaw('SUM(COALESCE(NULLIF(credits_count, 0), CEIL(CHAR_LENGTH(COALESCE(message, "")) / 160))) as total')
                ->value('total') ?? 0);

        // --- RECEIVED (SurveyResponse from members in group) ---
        $messagesReceived = empty($memberPhones)
            ? 0
            : SurveyResponse::where('survey_id', $surveyId)->whereIn('msisdn', $memberPhones)->count();
        $creditsReceived = empty($memberPhones)
            ? 0
            : (int) (SurveyResponse::where('survey_id', $surveyId)
                ->whereIn('msisdn', $memberPhones)
                ->selectRaw('SUM(CEIL(CHAR_LENGTH(COALESCE(survey_response, "")) / 160)) as total')
                ->value('total') ?? 0);

        $totalMessages = $messagesSent + $messagesReceived;
        $totalCredits = $creditsSent + $creditsReceived;

        $this->table(
            ['Metric', 'Count'],
            [
                ['Survey progress records', number_format($progressIds->count())],
                ['Messages sent', number_format($messagesSent)],
                ['Reminders sent', number_format($remindersSent)],
                ['Messages received', number_format($messagesReceived)],
                ['Total messages', number_format($totalMessages)],
                ['Credits used (sending)', number_format($creditsSent)],
                ['Credits used (reminders)', number_format($creditsReminders)],
                ['Credits used (receiving)', number_format($creditsReceived)],
                ['Total credits used', number_format($totalCredits)],
            ]
        );

        if ($messagesSent > 0) {
            $this->info("Average credits per sent message: " . round($creditsSent / $messagesSent, 2));
        }
        if ($remindersSent > 0) {
            $this->info("Average credits per reminder: " . round($creditsReminders / $remindersSent, 2));
        }
        if ($messagesReceived > 0) {
            $this->info("Average credits per received message: " . round($creditsReceived / $messagesReceived, 2));
        }

        return Command::SUCCESS;
    }
}
