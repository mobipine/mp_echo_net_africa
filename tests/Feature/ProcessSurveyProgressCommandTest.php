<?php

namespace Tests\Feature;

use App\Console\Commands\ProcessSurveyProgressCommand;
use App\Models\Group;
use App\Models\Member;
use App\Models\Survey;
use App\Models\SurveyProgress;
use App\Models\SurveyQuestion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProcessSurveyProgressCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'cache.default' => 'array',
            'survey_settings.messages_enabled' => true,
        ]);

        Member::setEventDispatcher(app('events'));
    }

    public function test_process_survey_progress_skips_active_rows_for_soft_deleted_members(): void
    {
        [$survey, $question] = $this->createSurveyWithFirstQuestion();
        $group = Group::withoutEvents(fn () => Group::create(['name' => 'Test Group']));
        $member = Member::create([
            'group_id' => $group->id,
            'name' => 'Test Member',
            'phone' => '0700000999',
            'is_active' => true,
            'stage' => 'New',
        ]);

        $orphanedBySoftDelete = SurveyProgress::create([
            'survey_id' => $survey->id,
            'member_id' => $member->id,
            'current_question_id' => $question->id,
            'last_dispatched_at' => now()->subHour(),
            'has_responded' => false,
            'status' => 'ACTIVE',
            'source' => 'manual',
            'channel' => 'sms',
        ]);

        $member->deleteQuietly();

        $this->artisan(ProcessSurveyProgressCommand::class)->assertExitCode(0);

        $this->assertDatabaseHas('survey_progress', ['id' => $orphanedBySoftDelete->id, 'status' => 'ACTIVE']);
    }

    public function test_member_soft_delete_cancels_open_survey_progress(): void
    {
        [$survey, $question] = $this->createSurveyWithFirstQuestion();
        $group = Group::withoutEvents(fn () => Group::create(['name' => 'Test Group']));
        $member = Member::create([
            'group_id' => $group->id,
            'name' => 'Test Member',
            'phone' => '0700000998',
            'is_active' => true,
            'stage' => 'New',
        ]);

        $progress = SurveyProgress::create([
            'survey_id' => $survey->id,
            'member_id' => $member->id,
            'current_question_id' => $question->id,
            'last_dispatched_at' => now()->subHour(),
            'has_responded' => false,
            'status' => 'ACTIVE',
            'source' => 'manual',
            'channel' => 'sms',
        ]);

        $member->delete();

        $this->assertDatabaseHas('survey_progress', [
            'id' => $progress->id,
            'status' => 'CANCELLED',
            'open_progress_guard' => null,
        ]);
    }

    private function createSurveyWithFirstQuestion(): array
    {
        $survey = Survey::create([
            'title' => 'Finance Survey',
            'description' => 'Test flow',
            'trigger_word' => 'finance',
            'final_response' => 'Thanks',
            'status' => 'Active',
            'continue_confirmation_interval' => 1,
            'continue_confirmation_interval_unit' => 'minutes',
            'order' => 1,
        ]);

        $question = SurveyQuestion::create([
            'question' => 'Did you receive a loan?',
            'purpose' => 'regular',
            'answer_data_type' => 'Alphanumeric',
            'answer_strictness' => 'Multiple Choice',
            'possible_answers' => [
                ['answer' => 'Yes'],
                ['answer' => 'No'],
            ],
        ]);

        $survey->update([
            'flow_data' => [
                'elements' => [
                    ['id' => 'start', 'label' => 'Start', 'type' => 'input', 'data' => []],
                    [
                        'id' => 'q1-node',
                        'label' => $question->question,
                        'type' => 'default',
                        'data' => [
                            'questionId' => $question->id,
                            'answerStrictness' => 'Multiple Choice',
                            'possibleAnswers' => [
                                ['answer' => 'Yes', 'linkedFlow' => 'q1-node-end-node'],
                                ['answer' => 'No', 'linkedFlow' => 'q1-node-end-node'],
                            ],
                        ],
                    ],
                    ['id' => 'end-node', 'label' => 'End', 'type' => 'output', 'data' => []],
                ],
                'edges' => [
                    ['id' => 'start-q1-node', 'source' => 'start', 'target' => 'q1-node'],
                    ['id' => 'q1-node-end-node', 'source' => 'q1-node', 'target' => 'end-node'],
                ],
            ],
        ]);

        return [$survey, $question];
    }
}
