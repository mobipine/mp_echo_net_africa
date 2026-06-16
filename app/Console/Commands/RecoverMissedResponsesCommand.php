<?php

namespace App\Console\Commands;

use App\Models\CreditTransaction;
use App\Models\Member;
use App\Models\SurveyProgress;
use App\Models\SurveyResponse;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Recovers survey replies that were dropped during the phone-format outage.
 *
 * While member phones were stored as +254XXX, the inbound webhook (which matches on the
 * local 0XXX format) could not find the member, logged "No active survey or trigger word
 * found", deducted a credit, and never created a SurveyResponse. Those messages were not
 * stored anywhere except the credit ledger, where each appears as:
 *   transaction_type = 'sms_received', description = "SMS received from 0XXX: <message>".
 *
 * For each member that still has an active, not-yet-responded progress on a regular
 * question, this records their recovered answer for the current question and marks the
 * progress responded, so the normal scheduler (process:surveys-progress) advances them.
 * It does NOT re-charge credits (already deducted) and does NOT call processSurveyResponse
 * (which would null-crash on the stale +254 SMS lookup).
 *
 * PREREQUISITE: run members:normalize-phones first so members are matchable by 0XXX phone.
 */
class RecoverMissedResponsesCommand extends Command
{
    protected $signature = 'survey:recover-missed-responses
                            {survey : Survey ID (e.g. 7 = Finance)}
                            {--since= : Window start datetime (default: start of today)}
                            {--until= : Window end datetime (default: now)}
                            {--dry-run : Report what would be recovered without writing anything}';

    protected $description = 'Replay survey replies dropped during the +254 phone outage: record the answer for each stuck member so the scheduler advances them';

    public function handle(): int
    {
        $surveyId = (int) $this->argument('survey');
        $since = $this->option('since') ? Carbon::parse($this->option('since')) : Carbon::today();
        $until = $this->option('until') ? Carbon::parse($this->option('until')) : now();
        $dryRun = (bool) $this->option('dry-run');

        $this->info(($dryRun ? '🔍 DRY RUN — ' : '') . "Recovering dropped replies for survey {$surveyId}");
        $this->line("Window: {$since->toDateTimeString()}  ->  {$until->toDateTimeString()}");
        $this->newLine();

        // Pull the dropped-inbound credit-ledger entries in the window.
        $txns = CreditTransaction::where('transaction_type', 'sms_received')
            ->where('description', 'like', 'SMS received from %')
            ->whereBetween('created_at', [$since, $until])
            ->orderBy('created_at')
            ->get(['description', 'created_at']);

        $this->line("Candidate 'sms_received' ledger entries in window: " . number_format($txns->count()));

        // Parse "SMS received from <msisdn>: <message>" and group messages per member (chronological).
        $byMember = [];
        $unparsable = 0;
        foreach ($txns as $t) {
            if (!preg_match('/^SMS received from (\S+):\s?(.*)$/s', $t->description, $m)) {
                $unparsable++;
                continue;
            }
            $byMember[$m[1]][] = trim($m[2]);
        }
        $this->line("Distinct phone numbers with dropped messages: " . number_format(count($byMember)));
        if ($unparsable) {
            $this->line("Unparsable ledger descriptions skipped: " . number_format($unparsable));
        }
        $this->newLine();

        $stats = [
            'recovered' => 0,
            'no_member' => 0,
            'no_active_progress' => 0,
            'non_regular_question' => 0,
            'invalid_answer' => 0,
        ];
        $samples = [];

        foreach ($byMember as $msisdn => $messages) {
            $member = Member::where('phone', $msisdn)->first();
            if (!$member) {
                $stats['no_member']++;
                continue;
            }

            // Stuck = active progress on this survey, not yet responded, with a current question.
            $progress = SurveyProgress::with('currentQuestion')
                ->where('member_id', $member->id)
                ->where('survey_id', $surveyId)
                ->whereNull('completed_at')
                ->whereIn('status', ['ACTIVE', 'UPDATING_DETAILS'])
                ->where('has_responded', false)
                ->whereNotNull('current_question_id')
                ->latest('last_dispatched_at')
                ->first();

            if (!$progress || !$progress->currentQuestion) {
                $stats['no_active_progress']++;
                continue;
            }

            $question = $progress->currentQuestion;
            if (($question->purpose ?? 'regular') !== 'regular') {
                // Special-purpose questions (loan amount/date) have side effects we won't replay blindly.
                $stats['non_regular_question']++;
                continue;
            }

            // Use the first message that resolves to a valid answer for this question.
            $actualAnswer = null;
            foreach ($messages as $msg) {
                $candidate = getActualAnswer($question, $msg, $msisdn);
                if ($candidate !== null && $candidate !== '') {
                    $actualAnswer = $candidate;
                    break;
                }
            }
            if ($actualAnswer === null) {
                $stats['invalid_answer']++;
                continue;
            }

            $stats['recovered']++;
            if (count($samples) < 15) {
                $samples[] = [$member->id, $msisdn, $question->id, mb_substr((string) $actualAnswer, 0, 24)];
            }

            if (!$dryRun) {
                DB::transaction(function () use ($surveyId, $msisdn, $question, $actualAnswer, $progress) {
                    SurveyResponse::create([
                        'survey_id' => $surveyId,
                        'msisdn' => $msisdn,
                        'question_id' => $question->id,
                        'survey_response' => $actualAnswer,
                        'inbox_id' => null,
                        'session_id' => $progress->id,
                    ]);
                    // Mark responded so process:surveys-progress sends the next question on its cycle.
                    $progress->update(['has_responded' => true]);
                });
            }
        }

        $this->table(['Outcome', 'Members'], [
            ['Recovered (answer recorded)', number_format($stats['recovered'])],
            ['Skipped — no member for phone', number_format($stats['no_member'])],
            ['Skipped — no active/unanswered progress (already moved on?)', number_format($stats['no_active_progress'])],
            ['Skipped — current question is not regular (manual review)', number_format($stats['non_regular_question'])],
            ['Skipped — no valid answer in their messages', number_format($stats['invalid_answer'])],
        ]);

        if (!empty($samples)) {
            $this->newLine();
            $this->info('Sample recovered (first ' . count($samples) . '):');
            $this->table(['Member', 'Phone', 'Question', 'Stored answer'], $samples);
        }

        $this->newLine();
        if ($dryRun) {
            $this->comment('DRY RUN — nothing written. Re-run without --dry-run to apply.');
        } else {
            $this->info("✅ Recorded {$stats['recovered']} recovered response(s). The scheduler will advance these members to their next question.");
        }

        return self::SUCCESS;
    }
}
