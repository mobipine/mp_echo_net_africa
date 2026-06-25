<?php

namespace App\Console\Commands;

use App\Models\Member;
use App\Models\Survey;
use App\Models\GroupSurvey;
use App\Services\SurveyDispatchService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class DispatchDueSurveysCommand extends Command
{
    /**
     * AUTOMATED SURVEY DISPATCH COMMAND - OVERVIEW
     *
     * 1. Runs every 5 seconds, finds scheduled surveys due to start now
     * 2. Queries group_survey WHERE automated=true, was_dispatched=false, starts_at<=now
     * 3. Marks was_dispatched=true immediately to prevent duplicates
     * 4. Validates member eligibility: stage (New or PreviousSurveyCompleted) + uniqueness
     * 5. Creates survey_progress, updates member stage, queues first question SMS
     * 6. Works with SendSurveyToGroupJob (this=automated, job=manual UI)
     */

    protected $signature = 'surveys:due-dispatch';
    protected $description = 'Dispatch automated surveys to eligible members based on order and stage';

    public function handle(SurveyDispatchService $dispatchService)
    {
        // Check if survey messages are enabled
        if (!config('survey_settings.messages_enabled', true)) {
            Log::info('Survey messages are disabled via config. Skipping automated survey dispatch.');
            return;
        }

        // Acquire lock to prevent concurrent executions
        $lock = \Illuminate\Support\Facades\Cache::lock('surveys-due-dispatch-command', 60);

        if (!$lock->get()) {
            Log::info('DispatchDueSurveysCommand already running. Skipping...');
            return;
        }

        try {
            $dueAssignments = GroupSurvey::with(['survey', 'group'])
                ->where('automated', true)
                ->where('was_dispatched', false)
                ->where('starts_at', '<=', now())
                ->get();

            if ($dueAssignments->isEmpty()) {
                Log::info('No automated survey assignments due.');
                return;
            }

            $totalSent = 0;

            $assignmentBatches = $dueAssignments->groupBy(function (GroupSurvey $assignment) {
                return implode(':', [
                    $assignment->survey_id,
                    $assignment->channel ?? 'sms',
                ]);
            });

            foreach ($assignmentBatches as $batchKey => $assignments) {
                $survey = $assignments->first()?->survey;
                if (!$survey) {
                    Log::warning("Survey missing for due-dispatch batch {$batchKey}");
                    continue;
                }

                $firstQuestion = getNextQuestion($survey->id, null, null);
                if (is_array($firstQuestion)) {
                    Log::error("Error getting first question for survey '{$survey->title}': " . ($firstQuestion['message'] ?? 'Unknown error'));
                    continue;
                }

                if (!$firstQuestion || !$firstQuestion instanceof \App\Models\SurveyQuestion) {
                    Log::warning("Survey '{$survey->title}' has no questions. Skipping.");
                    continue;
                }

                $assignmentIds = $assignments->pluck('id');
                GroupSurvey::whereIn('id', $assignmentIds)->update(['was_dispatched' => true]);

                $groupIds = $assignments->pluck('group_id')->unique()->values()->all();
                $memberIds = $dispatchService->eligibleMembersQuery($groupIds)->pluck('members.id')->all();
                $members = Member::whereIn('id', $memberIds)->orderBy('id')->get()->keyBy('id');
                $previousSurvey = $survey->order > 1
                    ? Survey::where('order', $survey->order - 1)->first()
                    : null;
                $dispatchBatchUuid = Str::uuid()->toString();
                $sentCount = 0;

                foreach ($memberIds as $memberId) {
                    $member = $members->get($memberId);
                    if (!$member) {
                        continue;
                    }

                    if (!$this->memberIsEligibleForAutomatedDispatch($member, $survey, $previousSurvey)) {
                        continue;
                    }

                    $result = $dispatchService->dispatchToMember(
                        $member,
                        $survey,
                        $firstQuestion,
                        $assignments->first()->channel ?? 'sms',
                        'automated',
                        $dispatchBatchUuid
                    );

                    if (($result['status'] ?? null) === 'queued') {
                        $sentCount++;
                    }
                }

                Log::info(
                    "Survey '{$survey->title}' dispatched to {$sentCount} unique members across "
                    . count($groupIds) . ' due group(s)',
                    ['assignment_ids' => $assignmentIds->all(), 'group_ids' => $groupIds]
                );
                $totalSent += $sentCount;
            }

            Log::info("DispatchDueSurveysCommand complete: {$totalSent} messages queued");
        } finally {
            $lock->release();
        }
    }

    private function memberIsEligibleForAutomatedDispatch(Member $member, Survey $survey, ?Survey $previousSurvey): bool
    {
        if ($survey->order === 1) {
            if ($member->stage !== 'New') {
                Log::info("Skipping {$member->name}: not in 'New' stage for first survey");
                return false;
            }

            return true;
        }

        if (!$previousSurvey) {
            Log::warning("Previous survey (order " . ($survey->order - 1) . ") not found");
            return false;
        }

        $expectedStage = str_replace(' ', '', ucfirst($previousSurvey->title)) . 'Completed';
        if ($member->stage !== $expectedStage) {
            Log::info("Skipping {$member->name}: stage '{$member->stage}' != '{$expectedStage}'");
            return false;
        }

        return true;
    }
}
