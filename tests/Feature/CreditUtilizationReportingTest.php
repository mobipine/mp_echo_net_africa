<?php

namespace Tests\Feature;

use App\Filament\Pages\CreditReports;
use App\Jobs\GenerateCreditUtilizationReportJob;
use App\Models\County;
use App\Models\CreditReportExport;
use App\Models\CreditTransaction;
use App\Models\Group;
use App\Models\Member;
use App\Models\SMSInbox;
use App\Models\Survey;
use App\Models\SurveyProgress;
use App\Models\SurveyQuestion;
use App\Models\SurveyResponse;
use App\Models\User;
use App\Services\CreditUtilizationReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

class CreditUtilizationReportingTest extends TestCase
{
    use RefreshDatabase;

    public function test_report_engine_applies_attribution_and_activity_filters_consistently(): void
    {
        $data = $this->createReportingScenario();
        $service = app(CreditUtilizationReportService::class);

        $surveyFilters = ['survey_ids' => [$data['survey']->id]];
        $summary = $service->summary($surveyFilters);

        $this->assertSame(2, $service->query($surveyFilters)->count());
        $this->assertSame(3, $summary['credits_used']);
        $this->assertSame(2, $summary['credits_sent']);
        $this->assertSame(1, $summary['credits_received']);
        $this->assertSame(3, $summary['survey_attributed']);
        $this->assertSame(0, $summary['non_survey_usage']);

        $this->assertSame(3, $service->query(['group_ids' => [$data['group']->id]])->count());
        $this->assertSame(3, $service->query(['county_ids' => [$data['county']->id]])->count());
        $this->assertSame(1, $service->query([
            ...$surveyFilters,
            'transaction_types' => ['sms_received'],
        ])->count());
        $this->assertSame(1, $service->query([
            ...$surveyFilters,
            'date_from' => '2026-08-02',
            'date_to' => '2026-08-02',
        ])->count());

        $daily = $service->dailyBreakdown($surveyFilters);
        $this->assertCount(2, $daily);
        $this->assertSame(2, $daily->first()['credits_used']);
        $this->assertSame(1, $daily->last()['credits_used']);

        $surveyBreakdown = $service->surveyBreakdown([])->keyBy('survey_title');
        $this->assertSame(3, $surveyBreakdown->get($data['survey']->title)['credits_used']);
        $this->assertTrue($surveyBreakdown->contains('survey_title', 'Non-survey / unattributed'));
    }

    public function test_queued_job_generates_a_private_multi_sheet_workbook(): void
    {
        Storage::fake('local');
        $data = $this->createReportingScenario();
        $report = CreditReportExport::query()->create([
            'uuid' => fake()->uuid(),
            'user_id' => $data['user']->id,
            'status' => CreditReportExport::STATUS_QUEUED,
            'filters' => ['survey_ids' => [$data['survey']->id]],
            'disk' => 'local',
            'file_path' => 'private/credit-reports/test/report.xlsx',
            'file_name' => 'credit-utilization-test.xlsx',
        ]);

        (new GenerateCreditUtilizationReportJob($report->id))
            ->handle(app(CreditUtilizationReportService::class));

        $report->refresh();
        Storage::disk('local')->assertExists($report->file_path);
        $this->assertSame(CreditReportExport::STATUS_COMPLETED, $report->status);
        $this->assertSame(2, $report->row_count);
        $this->assertGreaterThan(0, $report->file_size);

        $workbook = IOFactory::load(Storage::disk('local')->path($report->file_path));
        $this->assertSame([
            'Executive Summary',
            'Daily Trend',
            'Survey Utilization',
            'Transaction Ledger',
            'Definitions',
        ], $workbook->getSheetNames());
        $this->assertSame('ECHO NET AFRICA | CREDIT UTILIZATION REPORT', $workbook->getSheet(0)->getCell('A1')->getValue());
        $this->assertSame('Timestamp', $workbook->getSheetByName('Transaction Ledger')->getCell('A1')->getValue());
    }

    public function test_only_the_requesting_user_can_download_a_completed_export(): void
    {
        Storage::fake('local');
        $owner = User::factory()->create();
        $otherUser = User::factory()->create();
        $path = 'private/credit-reports/test/authorized.xlsx';
        Storage::disk('local')->put($path, 'excel-content');
        $report = CreditReportExport::query()->create([
            'uuid' => fake()->uuid(),
            'user_id' => $owner->id,
            'status' => CreditReportExport::STATUS_COMPLETED,
            'filters' => [],
            'disk' => 'local',
            'file_path' => $path,
            'file_name' => 'authorized.xlsx',
            'file_size' => 13,
            'completed_at' => now(),
        ]);

        $this->get(route('credit-reports.download', $report))
            ->assertRedirect(route('filament.admin.auth.login'));

        $this->actingAs($otherUser)
            ->get(route('credit-reports.download', $report))
            ->assertForbidden();

        $this->actingAs($owner)
            ->get(route('credit-reports.download', $report))
            ->assertOk()
            ->assertDownload('authorized.xlsx');
    }

    public function test_credit_reports_page_renders_the_reporting_workspace(): void
    {
        $data = $this->createReportingScenario();

        Livewire::actingAs($data['user'])
            ->test(CreditReports::class)
            ->assertSuccessful()
            ->assertSee('Recent Excel workbooks')
            ->assertSee('Credit transactions');
    }

    private function createReportingScenario(): array
    {
        $user = User::factory()->create();
        $county = County::query()->create(['name' => 'Nairobi']);
        $group = Group::withoutEvents(fn () => Group::query()->create(['name' => 'Umoja Group']));
        $member = Member::query()->create([
            'group_id' => $group->id,
            'name' => 'Jane Member',
            'phone' => '254700000001',
            'county_id' => $county->id,
        ]);
        $member->groups()->attach($group);
        $survey = Survey::query()->create([
            'title' => 'Household Finance Survey',
            'trigger_word' => 'START',
            'status' => 'Active',
        ]);
        $question = SurveyQuestion::query()->create([
            'question' => 'Did you save this month?',
            'answer_data_type' => 'Alphanumeric',
        ]);
        $progress = SurveyProgress::query()->create([
            'survey_id' => $survey->id,
            'member_id' => $member->id,
            'current_question_id' => $question->id,
            'status' => 'ACTIVE',
            'channel' => 'sms',
        ]);
        $surveyInbox = SMSInbox::query()->create([
            'member_id' => $member->id,
            'survey_progress_id' => $progress->id,
            'phone_number' => $member->phone,
            'message' => 'Did you save this month?',
            'status' => 'sent',
            'channel' => 'sms',
            'is_reminder' => false,
        ]);
        $response = SurveyResponse::query()->create([
            'survey_id' => $survey->id,
            'msisdn' => $member->phone,
            'question_id' => $question->id,
            'survey_response' => 'Yes',
            'inbox_id' => $surveyInbox->id,
            'session_id' => $progress->id,
        ]);

        $this->createTransaction([
            'type' => 'subtract',
            'amount' => 2,
            'balance_before' => 100,
            'balance_after' => 98,
            'transaction_type' => 'sms_sent',
            'description' => 'Survey question sent',
            'sms_inbox_id' => $surveyInbox->id,
        ], '2026-08-01 09:00:00');
        $this->createTransaction([
            'type' => 'subtract',
            'amount' => 1,
            'balance_before' => 98,
            'balance_after' => 97,
            'transaction_type' => 'sms_received',
            'description' => 'Survey response received',
            'survey_response_id' => $response->id,
        ], '2026-08-02 10:00:00');

        $genericInbox = SMSInbox::query()->create([
            'member_id' => $member->id,
            'phone_number' => $member->phone,
            'message' => 'General notification',
            'status' => 'sent',
            'channel' => 'sms',
            'is_reminder' => false,
        ]);
        $this->createTransaction([
            'type' => 'subtract',
            'amount' => 3,
            'balance_before' => 97,
            'balance_after' => 94,
            'transaction_type' => 'sms_sent',
            'description' => 'General message sent',
            'sms_inbox_id' => $genericInbox->id,
        ], '2026-08-03 11:00:00');
        $this->createTransaction([
            'type' => 'add',
            'amount' => 50,
            'balance_before' => 94,
            'balance_after' => 144,
            'transaction_type' => 'load',
            'description' => 'Credits loaded',
            'user_id' => $user->id,
        ], '2026-08-04 12:00:00');

        return compact('user', 'county', 'group', 'member', 'survey');
    }

    private function createTransaction(array $attributes, string $createdAt): CreditTransaction
    {
        $transaction = CreditTransaction::query()->create($attributes);
        $transaction->forceFill(['created_at' => $createdAt, 'updated_at' => $createdAt])->saveQuietly();

        return $transaction;
    }
}
