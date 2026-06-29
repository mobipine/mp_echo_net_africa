<?php

namespace App\Services;

use App\Models\SurveyProgress;
use App\Models\SurveyResponse;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Builds the "dispatch funnel" for a survey (optionally scoped to a group):
 *
 *   1st day of sending   -> total sent / total responses
 *   1st Reminder         -> total reminders / total responses
 *   2nd Reminder         -> ...
 *
 * "Total responses" for a stage = the number of DISTINCT members (scoped to the
 * group when given) who sent any survey response in the window that runs from
 * that stage's dispatch time up to the next stage's dispatch time (or "now" for
 * the latest stage). A member active in more than one window is counted in each.
 */
class SurveyDispatchFunnelService
{
    /**
     * @return array<int, array{label:string,type:string,seq:?int,sent:int,responses:int,dispatched_at:?string}>
     */
    public function build(int $surveyId, ?int $groupId = null): array
    {
        $progressIds = $this->scopedProgressIds($surveyId, $groupId);

        if ($progressIds->isEmpty()) {
            return [];
        }

        // Normalised phones of the scoped members, for matching against responses.
        $scopedPhones = [];
        DB::table('survey_progress')
            ->join('members', 'members.id', '=', 'survey_progress.member_id')
            ->whereIn('survey_progress.id', $progressIds)
            ->pluck('members.phone')
            ->each(function ($phone) use (&$scopedPhones) {
                if ($phone) {
                    $scopedPhones[normalizePhoneNumber($phone)] = true;
                }
            });

        // --- Stage 0: initial send (is_reminder = 0) ---
        $initialQuery = DB::table('sms_inboxes')
            ->whereIn('survey_progress_id', $progressIds)
            ->where('is_reminder', 0);

        $stages = [];
        $stages[] = [
            'label' => '1st day of sending',
            'type' => 'initial',
            'seq' => null,
            'sent' => (clone $initialQuery)->count(),
            'dispatched_at' => (clone $initialQuery)->min('created_at'),
        ];

        // --- Reminder stages: rank each progress's reminders chronologically.
        // The Nth reminder (by time) for a progress belongs to reminder round N.
        $rounds = [];
        $perProgressSeq = [];
        DB::table('sms_inboxes')
            ->whereIn('survey_progress_id', $progressIds)
            ->where('is_reminder', 1)
            ->orderBy('survey_progress_id')
            ->orderBy('created_at')
            ->orderBy('id')
            ->select('survey_progress_id', 'created_at')
            ->each(function ($row) use (&$rounds, &$perProgressSeq) {
                $pid = $row->survey_progress_id;
                $seq = ($perProgressSeq[$pid] ?? 0) + 1;
                $perProgressSeq[$pid] = $seq;

                if (!isset($rounds[$seq])) {
                    $rounds[$seq] = ['sent' => 0, 'dispatched_at' => $row->created_at];
                }
                $rounds[$seq]['sent']++;
                if ($row->created_at < $rounds[$seq]['dispatched_at']) {
                    $rounds[$seq]['dispatched_at'] = $row->created_at;
                }
            });

        ksort($rounds);
        foreach ($rounds as $seq => $info) {
            $stages[] = [
                'label' => $this->ordinal($seq) . ' Reminder',
                'type' => 'reminder',
                'seq' => $seq,
                'sent' => $info['sent'],
                'dispatched_at' => $info['dispatched_at'],
            ];
        }

        $this->attachResponseCounts($stages, $surveyId, $scopedPhones);

        return $stages;
    }

    /**
     * Count distinct scoped responders within each stage's [start, nextStart) window.
     */
    protected function attachResponseCounts(array &$stages, int $surveyId, array $scopedPhones): void
    {
        $firstStart = $stages[0]['dispatched_at'] ?? null;

        // No dispatch time means nothing was sent yet.
        if (!$firstStart || empty($scopedPhones)) {
            foreach ($stages as &$stage) {
                $stage['responses'] = 0;
            }
            return;
        }

        // One distinct-phone set per stage window.
        $sets = array_fill(0, count($stages), []);
        $starts = array_map(
            fn ($s) => $s['dispatched_at'] ? Carbon::parse($s['dispatched_at']) : null,
            $stages
        );

        SurveyResponse::where('survey_id', $surveyId)
            ->where('created_at', '>=', $firstStart)
            ->select('id', 'msisdn', 'created_at')
            ->chunkById(2000, function ($responses) use (&$sets, $starts, $scopedPhones) {
                foreach ($responses as $response) {
                    $phone = normalizePhoneNumber($response->msisdn);
                    if (!isset($scopedPhones[$phone])) {
                        continue;
                    }

                    $when = $response->created_at;
                    // Find the latest stage whose dispatch time is <= response time.
                    for ($i = count($starts) - 1; $i >= 0; $i--) {
                        if ($starts[$i] !== null && $when->gte($starts[$i])) {
                            $sets[$i][$phone] = true;
                            break;
                        }
                    }
                }
            });

        foreach ($stages as $i => &$stage) {
            $stage['responses'] = count($sets[$i]);
        }
    }

    /**
     * Survey progress IDs for the survey, optionally limited to a group's members
     * (via the group_member pivot OR the legacy members.group_id column).
     */
    protected function scopedProgressIds(int $surveyId, ?int $groupId)
    {
        return SurveyProgress::where('survey_id', $surveyId)
            ->when($groupId, function ($query) use ($groupId) {
                $query->whereHas('member', function ($memberQuery) use ($groupId) {
                    $memberQuery->where('group_id', $groupId)
                        ->orWhereHas('groups', function ($groupQuery) use ($groupId) {
                            $groupQuery->where('groups.id', $groupId);
                        });
                });
            })
            ->pluck('id');
    }

    protected function ordinal(int $n): string
    {
        $suffix = 'th';
        if (!in_array($n % 100, [11, 12, 13], true)) {
            $suffix = match ($n % 10) {
                1 => 'st',
                2 => 'nd',
                3 => 'rd',
                default => 'th',
            };
        }
        return $n . $suffix;
    }
}
