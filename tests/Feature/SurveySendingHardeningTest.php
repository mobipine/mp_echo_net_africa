<?php

namespace Tests\Feature;

use App\Console\Commands\DispatchDueSurveysCommand;
use App\Jobs\SendIncompleteRemindersJob;
use App\Jobs\SendSurveyToGroupJob;
use App\Models\Group;
use App\Models\GroupSurvey;
use App\Models\Member;
use App\Models\SMSInbox;
use App\Models\SmsCredit;
use App\Models\SmsTransportLog;
use App\Models\Survey;
use App\Models\SurveyProgress;
use App\Models\SurveyQuestion;
use App\Services\SurveyDispatchService;
use App\Services\SurveyReminderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SurveySendingHardeningTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'cache.default' => 'array',
            'sms.driver' => 'fake',
            'sms.allow_real_delivery' => false,
            'survey_settings.messages_enabled' => true,
        ]);

        Group::unsetEventDispatcher();
        Member::unsetEventDispatcher();
    }

    public function test_send_survey_to_group_job_uses_unique_members_and_respects_limit(): void
    {
        [$survey] = $this->createSurveyWithFirstQuestion();
        [$groupA, $groupB] = $this->createGroups(2);

        $sharedMember = $this->createMember('Shared Member', '0700000001', $groupA, [$groupA, $groupB]);
        $memberA = $this->createMember('Member A', '0700000002', $groupA, [$groupA]);
        $memberB = $this->createMember('Member B', '0700000003', $groupB, [$groupB]);

        $job = new SendSurveyToGroupJob([$groupA->id, $groupB->id], $survey, 'sms', false, null, null, 2);
        $job->handle(app(SurveyDispatchService::class));

        $this->assertSame(2, SurveyProgress::count());
        $this->assertSame(2, SMSInbox::count());
        $this->assertSame(2, SurveyProgress::distinct('member_id')->count('member_id'));
        $this->assertSame(1, SurveyProgress::where('member_id', $sharedMember->id)->count());
        $this->assertSame(1, GroupSurvey::where('group_id', $groupA->id)->where('survey_id', $survey->id)->count());
        $this->assertSame(1, GroupSurvey::where('group_id', $groupB->id)->where('survey_id', $survey->id)->count());

        $queuedMemberIds = SMSInbox::pluck('member_id')->all();
        $this->assertContains($sharedMember->id, $queuedMemberIds);
        $this->assertContains($memberA->id, $queuedMemberIds);
        $this->assertNotContains($memberB->id, $queuedMemberIds);
    }

    public function test_send_incomplete_reminders_job_respects_limit_and_updates_progress(): void
    {
        [$survey, $question] = $this->createSurveyWithFirstQuestion();
        [$group] = $this->createGroups(1);

        $memberA = $this->createMember('Reminder A', '0700000011', $group, [$group]);
        $memberB = $this->createMember('Reminder B', '0700000012', $group, [$group]);

        $progressA = SurveyProgress::create([
            'survey_id' => $survey->id,
            'member_id' => $memberA->id,
            'current_question_id' => $question->id,
            'last_dispatched_at' => now()->subHour(),
            'has_responded' => false,
            'status' => 'ACTIVE',
            'source' => 'manual',
            'channel' => 'sms',
        ]);

        $progressB = SurveyProgress::create([
            'survey_id' => $survey->id,
            'member_id' => $memberB->id,
            'current_question_id' => $question->id,
            'last_dispatched_at' => now()->subHour(),
            'has_responded' => false,
            'status' => 'ACTIVE',
            'source' => 'manual',
            'channel' => 'sms',
        ]);

        $job = new SendIncompleteRemindersJob($group->id, $survey->id, 3, 1);
        $job->handle(app(SurveyReminderService::class));

        $this->assertSame(1, SMSInbox::where('is_reminder', true)->count());
        $this->assertSame(1, $progressA->fresh()->number_of_reminders);
        $this->assertSame(0, $progressB->fresh()->number_of_reminders);

        $reminder = SMSInbox::where('is_reminder', true)->first();
        $this->assertSame($progressA->id, $reminder->survey_progress_id);
        $this->assertSame("survey-reminder:{$progressA->id}:1", $reminder->dedupe_key);
    }

    public function test_dispatch_due_surveys_dedupes_overlapping_group_members(): void
    {
        [$survey] = $this->createSurveyWithFirstQuestion(order: 1);
        [$groupA, $groupB] = $this->createGroups(2);

        $sharedMember = $this->createMember('Auto Shared', '0700000021', $groupA, [$groupA, $groupB], 'New');
        $memberA = $this->createMember('Auto A', '0700000022', $groupA, [$groupA], 'New');
        $memberB = $this->createMember('Auto B', '0700000023', $groupB, [$groupB], 'New');

        GroupSurvey::create([
            'group_id' => $groupA->id,
            'survey_id' => $survey->id,
            'automated' => true,
            'starts_at' => now()->subMinute(),
            'was_dispatched' => false,
            'channel' => 'sms',
        ]);

        GroupSurvey::create([
            'group_id' => $groupB->id,
            'survey_id' => $survey->id,
            'automated' => true,
            'starts_at' => now()->subMinute(),
            'was_dispatched' => false,
            'channel' => 'sms',
        ]);

        $this->artisan(DispatchDueSurveysCommand::class)->assertExitCode(0);

        $this->assertSame(2, GroupSurvey::where('was_dispatched', true)->count());
        $this->assertSame(3, SurveyProgress::count());
        $this->assertSame(3, SMSInbox::count());
        $this->assertSame(1, SurveyProgress::where('member_id', $sharedMember->id)->count());
        $this->assertSame(3, SurveyProgress::distinct('member_id')->count('member_id'));
        $this->assertEqualsCanonicalizing(
            [$sharedMember->id, $memberA->id, $memberB->id],
            SMSInbox::orderBy('member_id')->pluck('member_id')->all()
        );
    }

    public function test_dispatch_sms_uses_fake_transport_and_marks_rows_sent(): void
    {
        [$group] = $this->createGroups(1);
        $member = $this->createMember('Dispatch Member', '0700000031', $group, [$group]);

        SmsCredit::query()->update(['balance' => 10]);

        $sms = SMSInbox::create([
            'phone_number' => $member->phone,
            'message' => 'Test dispatch message',
            'channel' => 'sms',
            'member_id' => $member->id,
        ]);

        $this->artisan('dispatch:sms')->assertExitCode(0);

        $sms->refresh();
        $this->assertSame('sent', $sms->status);
        $this->assertStringStartsWith('fake-', (string) $sms->unique_id);
        $this->assertSame(9, SmsCredit::getBalance());
        $this->assertSame(1, SmsTransportLog::count());

        $log = SmsTransportLog::first();
        $this->assertSame('fake', $log->transport);
        $this->assertSame('outbound', $log->direction);
        $this->assertSame($member->phone, $log->phone_number);
    }

    private function createSurveyWithFirstQuestion(bool $participantUniqueness = false, int $order = 1): array
    {
        $survey = Survey::create([
            'title' => 'Finance Survey',
            'description' => 'Test flow',
            'trigger_word' => 'finance',
            'final_response' => 'Thanks',
            'status' => 'Active',
            'participant_uniqueness' => $participantUniqueness,
            'continue_confirmation_interval' => 1,
            'continue_confirmation_interval_unit' => 'minutes',
            'order' => $order,
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

    private function createGroups(int $count): array
    {
        $groups = [];

        for ($i = 1; $i <= $count; $i++) {
            $groups[] = Group::create([
                'name' => "Group {$i}",
            ]);
        }

        return $groups;
    }

    private function createMember(string $name, string $phone, Group $primaryGroup, array $groups, string $stage = 'New'): Member
    {
        $member = Member::create([
            'group_id' => $primaryGroup->id,
            'name' => $name,
            'phone' => $phone,
            'is_active' => true,
            'stage' => $stage,
        ]);

        $member->groups()->sync(collect($groups)->pluck('id')->all());

        return $member;
    }
}
