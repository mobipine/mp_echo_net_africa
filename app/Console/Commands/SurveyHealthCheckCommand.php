<?php

namespace App\Console\Commands;

use App\Models\Group;
use App\Models\SMSInbox;
use App\Models\Survey;
use App\Models\SurveyProgress;
use App\Models\SurveyResponse;
use Carbon\Carbon;
use Illuminate\Console\Command;

/**
 * Read-only health report for a dispatched survey.
 *
 * Answers: how many progress records exist (and when they were created), are there
 * duplicates / participant-uniqueness violations, what is the SMS pipeline doing,
 * how many members have responded, and why were records cancelled. Nothing is modified.
 */
class SurveyHealthCheckCommand extends Command
{
    protected $signature = 'survey:health-check
                            {survey : Survey ID}
                            {--group= : Optional group ID to scope to that group\'s members}
                            {--date= : Window start date (YYYY-MM-DD); "today\'s" activity is on/after this. Default: today}';

    protected $description = 'Read-only health check of a dispatched survey: progress counts, duplicates, SMS pipeline, responses and cancellations';

    public function handle(): int
    {
        $survey = Survey::find($this->argument('survey'));
        if (!$survey) {
            $this->error('Survey not found.');
            return self::FAILURE;
        }

        $windowStart = $this->option('date')
            ? Carbon::parse($this->option('date'))->startOfDay()
            : Carbon::today();

        $memberIds = null;
        $scopeLabel = 'ALL members';
        if ($this->option('group')) {
            $group = Group::find($this->option('group'));
            if (!$group) {
                $this->error('Group not found.');
                return self::FAILURE;
            }
            $memberIds = $group->members()->pluck('members.id');
            $scopeLabel = "group '{$group->name}' (ID {$group->id}, {$memberIds->count()} members)";
        }

        $this->info("🩺 Survey Health Check");
        $this->line("Survey:  {$survey->title} (ID {$survey->id}) — participant_uniqueness=" . ($survey->participant_uniqueness ? 'ON' : 'OFF'));
        $this->line("Scope:   {$scopeLabel}");
        $this->line("Window:  'today' = created on/after {$windowStart->toDateTimeString()}");
        $this->newLine();

        $progress = fn () => SurveyProgress::where('survey_id', $survey->id)
            ->when($memberIds !== null, fn ($q) => $q->whereIn('member_id', $memberIds));

        // ---------------------------------------------------------------
        // A. Progress status × when-created matrix  (explains inflated totals)
        // ---------------------------------------------------------------
        $this->info('A. Survey progress — status × when created');
        $statuses = ['ACTIVE', 'COMPLETED', 'CANCELLED'];
        $rows = [];
        $grandTotal = 0; $grandToday = 0; $grandBefore = 0;
        foreach ($statuses as $st) {
            $total = (clone $progress())->where('status', $st)->count();
            $today = (clone $progress())->where('status', $st)->where('created_at', '>=', $windowStart)->count();
            $before = $total - $today;
            $rows[] = [$st, number_format($before), number_format($today), number_format($total)];
            $grandTotal += $total; $grandToday += $today; $grandBefore += $before;
        }
        $rows[] = ['—— TOTAL ——', number_format($grandBefore), number_format($grandToday), number_format($grandTotal)];
        $this->table(['Status', 'Created before today', 'Created today', 'Total'], $rows);
        $this->line("  Distinct members with any progress: " . number_format((clone $progress())->distinct('member_id')->count('member_id')));
        $this->line("  Distinct members with progress created today: " . number_format((clone $progress())->where('created_at', '>=', $windowStart)->distinct('member_id')->count('member_id')));
        $this->newLine();

        // ---------------------------------------------------------------
        // B. Source breakdown of today's progress
        // ---------------------------------------------------------------
        $this->info("B. Today's progress by source");
        $bySource = (clone $progress())->where('created_at', '>=', $windowStart)
            ->selectRaw('source, COUNT(*) c')->groupBy('source')->pluck('c', 'source');
        $srcRows = [];
        foreach ($bySource as $src => $c) { $srcRows[] = [$src ?? 'NULL', number_format($c)]; }
        $this->table(['Source', 'Created today'], $srcRows ?: [['(none)', '0']]);
        $this->newLine();

        // ---------------------------------------------------------------
        // C. Duplicates / participant-uniqueness violations
        // ---------------------------------------------------------------
        $this->info('C. Duplicates & uniqueness');
        $dupAny = (clone $progress())->selectRaw('member_id, COUNT(*) c')->groupBy('member_id')->havingRaw('COUNT(*) > 1')->get();
        $dupActive = (clone $progress())->whereNull('completed_at')->where('status', 'ACTIVE')
            ->selectRaw('member_id, COUNT(*) c')->groupBy('member_id')->havingRaw('COUNT(*) > 1')->get();
        $dupToday = (clone $progress())->where('created_at', '>=', $windowStart)
            ->selectRaw('member_id, COUNT(*) c')->groupBy('member_id')->havingRaw('COUNT(*) > 1')->get();
        $this->table(['Check', 'Members', 'Extra rows'], [
            ['Members with >1 progress (any status)', number_format($dupAny->count()), number_format($dupAny->sum('c') - $dupAny->count())],
            ['Members with >1 ACTIVE progress (UNIQUENESS VIOLATION)', number_format($dupActive->count()), number_format($dupActive->sum('c') - $dupActive->count())],
            ['Members with >1 progress created today (double-dispatch)', number_format($dupToday->count()), number_format($dupToday->sum('c') - $dupToday->count())],
        ]);
        if ($dupActive->isNotEmpty()) {
            $sample = $dupActive->take(5)->pluck('member_id');
            $this->warn("  ⚠ Sample members with multiple ACTIVE progress: " . $sample->implode(', '));
        }
        $this->newLine();

        // ---------------------------------------------------------------
        // D. SMS pipeline (messages tied to this survey's progress)
        // ---------------------------------------------------------------
        $this->info('D. SMS pipeline (linked to this survey\'s progress)');
        $progressIds = (clone $progress())->pluck('id');
        $sms = fn () => SMSInbox::whereIn('survey_progress_id', $progressIds);
        if ($progressIds->isEmpty()) {
            $this->line('  No progress rows -> no SMS.');
        } else {
            $smsByStatus = (clone $sms())->selectRaw('status, COUNT(*) c')->groupBy('status')->pluck('c', 'status');
            $smsRows = [];
            foreach (['sent', 'pending', 'processing', 'failed', 'cancelled'] as $st) {
                $smsRows[] = [$st, number_format($smsByStatus[$st] ?? 0)];
            }
            $smsRows[] = ['—— TOTAL ——', number_format((clone $sms())->count())];
            $this->table(['SMS status', 'Count'], $smsRows);
            $this->line("  Question sends (is_reminder=0, any question): " . number_format((clone $sms())->where('is_reminder', false)->count()));
            $this->line("  Reminder sends (is_reminder=1):               " . number_format((clone $sms())->where('is_reminder', true)->count()));
            $this->line("  Created today: " . number_format((clone $sms())->where('created_at', '>=', $windowStart)->count()));
            $this->line("  Credits used (sum credits_count):             " . number_format((int) (clone $sms())->sum('credits_count')));
            // Real double-send signal: same member messaged more than once within today's window.
            $dupSmsMembers = (clone $sms())->where('created_at', '>=', $windowStart)
                ->selectRaw('member_id, COUNT(*) c')->groupBy('member_id')->havingRaw('COUNT(*) > 1')->get();
            $this->line("  Members who received >1 SMS today (possible double-send): " . number_format($dupSmsMembers->count()));
        }
        $this->newLine();

        // ---------------------------------------------------------------
        // E. Engagement / responses
        // ---------------------------------------------------------------
        $this->info('E. Engagement');
        $respondedProgress = (clone $progress())->where('has_responded', true)->count();
        $responses = $progressIds->isEmpty() ? collect()
            : SurveyResponse::whereIn('session_id', $progressIds);
        $this->table(['Metric', 'Count'], [
            ['Progress with has_responded = true', number_format($respondedProgress)],
            ['Total survey responses received', number_format($progressIds->isEmpty() ? 0 : (clone $responses)->count())],
            ['Distinct responders (by progress)', number_format($progressIds->isEmpty() ? 0 : (clone $responses)->distinct('session_id')->count('session_id'))],
            ['Distinct responders (by phone)', number_format($progressIds->isEmpty() ? 0 : (clone $responses)->distinct('msisdn')->count('msisdn'))],
        ]);
        $this->newLine();

        // ---------------------------------------------------------------
        // F. Cancellations & anomalies
        // ---------------------------------------------------------------
        $this->info('F. Cancellations & anomalies');
        $cancelled = (clone $progress())->where('status', 'CANCELLED');
        $cancelledTotal = (clone $cancelled)->count();
        $cancelledUpdatedToday = (clone $cancelled)->where('updated_at', '>=', $windowStart)->count();
        $cancelledCreatedToday = (clone $cancelled)->where('created_at', '>=', $windowStart)->count();
        // cancelled members who DO have another active progress (any survey) -> explained by overlap/dedupe
        $cancelledMemberIds = (clone $cancelled)->pluck('member_id')->unique();
        $explained = $cancelledMemberIds->isEmpty() ? 0 : SurveyProgress::whereIn('member_id', $cancelledMemberIds)
            ->whereNull('completed_at')->where('status', 'ACTIVE')->distinct('member_id')->count('member_id');
        $this->table(['Cancelled progress', 'Count'], [
            ['Total cancelled', number_format($cancelledTotal)],
            ['Cancelled rows updated today (cancelled recently)', number_format($cancelledUpdatedToday)],
            ['Cancelled rows created today', number_format($cancelledCreatedToday)],
            ['Cancelled members who now have another ACTIVE survey (expected: overlap cleanup)', number_format($explained)],
            ['Cancelled members with NO active survey (investigate)', number_format(max(0, $cancelledMemberIds->count() - $explained))],
        ]);
        // progress created today with no SMS at all
        if (!$progressIds->isEmpty()) {
            $todayIds = (clone $progress())->where('created_at', '>=', $windowStart)->pluck('id');
            $withSms = SMSInbox::whereIn('survey_progress_id', $todayIds)->distinct('survey_progress_id')->pluck('survey_progress_id');
            $noSms = $todayIds->diff($withSms)->count();
            $this->line("  Progress created today with NO linked SMS: " . number_format($noSms));
        }
        // members in this survey who also have an active OTHER survey (reply-routing conflicts)
        $activeMembers = (clone $progress())->whereNull('completed_at')->where('status', 'ACTIVE')->pluck('member_id')->unique();
        $overlap = $activeMembers->isEmpty() ? 0 : SurveyProgress::whereIn('member_id', $activeMembers)
            ->where('survey_id', '!=', $survey->id)->whereNull('completed_at')->where('status', 'ACTIVE')
            ->distinct('member_id')->count('member_id');
        $this->line("  Members ACTIVE on this survey who are also ACTIVE on ANOTHER survey (reply conflict): " . number_format($overlap));
        $this->newLine();

        // ---------------------------------------------------------------
        // G. Hourly timeline of today's progress + SMS
        // ---------------------------------------------------------------
        $this->info("G. Today's timeline (per hour)");
        $progByHour = (clone $progress())->where('created_at', '>=', $windowStart)
            ->selectRaw("DATE_FORMAT(created_at, '%Y-%m-%d %H:00') h, COUNT(*) c")->groupBy('h')->orderBy('h')->pluck('c', 'h');
        $smsByHour = $progressIds->isEmpty() ? collect() : SMSInbox::whereIn('survey_progress_id', $progressIds)
            ->where('created_at', '>=', $windowStart)
            ->selectRaw("DATE_FORMAT(created_at, '%Y-%m-%d %H:00') h, COUNT(*) c")->groupBy('h')->orderBy('h')->pluck('c', 'h');
        $hours = $progByHour->keys()->merge($smsByHour->keys())->unique()->sort()->values();
        if ($hours->isEmpty()) {
            $this->line('  No activity in window.');
        } else {
            $tlRows = [];
            foreach ($hours as $h) { $tlRows[] = [$h, number_format($progByHour[$h] ?? 0), number_format($smsByHour[$h] ?? 0)]; }
            $this->table(['Hour', 'Progress created', 'SMS created'], $tlRows);
        }

        $this->newLine();
        $this->comment('Read-only report — nothing was modified.');
        return self::SUCCESS;
    }
}
