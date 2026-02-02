<?php

namespace App\Console\Commands;

use App\Models\Group;
use App\Models\SMSInbox;
use App\Models\Survey;
use App\Models\SurveyProgress;
use Illuminate\Console\Command;

class CalculateSurveyDispatchCredits extends Command
{
    protected $signature = 'survey:calculate-dispatch-credits
                            {--group= : Group ID}
                            {--survey= : Survey ID}';

    protected $description = 'Calculate total SMS credits used for a survey dispatch to a group (messages sent for survey progress records)';

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

        $progressIds = SurveyProgress::where('survey_id', $surveyId)
            ->whereHas('member', function ($q) use ($groupId) {
                $q->where('group_id', $groupId)
                    ->orWhereHas('groups', fn ($gq) => $gq->where('groups.id', $groupId));
            })
            ->pluck('id');

        if ($progressIds->isEmpty()) {
            $this->warn("No survey progress records found for survey '{$survey->title}' and group '{$group->name}'.");
            $this->info('Total messages: 0');
            $this->info('Total credits: 0');
            return Command::SUCCESS;
        }

        $totalMessages = SMSInbox::whereIn('survey_progress_id', $progressIds)->count();

        $totalCredits = (int) SMSInbox::whereIn('survey_progress_id', $progressIds)
            ->selectRaw('SUM(COALESCE(NULLIF(credits_count, 0), CEIL(CHAR_LENGTH(COALESCE(message, "")) / 160))) as total')
            ->value('total') ?? 0;

        $this->info("Survey: {$survey->title} (ID: {$surveyId})");
        $this->info("Group: {$group->name} (ID: {$groupId})");
        $this->newLine();
        $this->info("Survey progress records: " . number_format($progressIds->count()));
        $this->info("Total messages sent: " . number_format($totalMessages));
        $this->info("Total credits used: " . number_format($totalCredits));

        if ($totalMessages > 0) {
            $avgCreditsPerMessage = round($totalCredits / $totalMessages, 2);
            $this->info("Average credits per message: {$avgCreditsPerMessage}");
        }

        return Command::SUCCESS;
    }
}
