<?php

namespace App\Services;

use App\Models\Group;
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
 *
 * Campaign anchoring: a survey can be dispatched to the same members more than
 * once (e.g. a January wave and a fresh wave for a new group). Because a member
 * keeps multiple survey_progress rows and dispatches aren't tagged to a group,
 * scoping by membership alone would mix the waves. So when a group is given we
 * anchor the funnel at the group's created_at (its campaign start) and ignore
 * everything dispatched/answered before it. An explicit $since overrides this.
 */
class SurveyDispatchFunnelService
{
    /**
     * @return array<int, array{label:string,type:string,seq:?int,sent:int,responses:int,dispatched_at:?string}>
     */
    public function build(int $surveyId, ?int $groupId = null, ?string $since = null): array
    {
        $progressIds = $this->scopedProgressQuery($surveyId, $groupId)->pluck('id');

        if ($progressIds->isEmpty()) {
            return [];
        }

        // Anchor at the group's campaign start unless an explicit cutoff is given.
        $since = $this->resolveSince($groupId, $since);

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
            ->where('is_reminder', 0)
            ->when($since, fn ($q) => $q->where('created_at', '>=', $since));

        $stages = [];
        $stages[] = [
            'label' => '1st day of sending',
            'type' => 'initial',
            'seq' => null,
            // Distinct recipients, so accidental double-sends don't inflate the count.
            'sent' => (clone $initialQuery)->distinct()->count('survey_progress_id'),
            'dispatched_at' => (clone $initialQuery)->min('created_at'),
        ];

        // --- Reminder stages: one round per calendar day reminders went out.
        // Reminders are dispatched roughly once a day; grouping by day collapses
        // same-round double-sends (and the partially-populated dedupe_key) into a
        // single round, and counts distinct recipients rather than raw messages.
        $reminderDays = DB::table('sms_inboxes')
            ->whereIn('survey_progress_id', $progressIds)
            ->where('is_reminder', 1)
            ->when($since, fn ($q) => $q->where('created_at', '>=', $since))
            ->select(
                DB::raw('DATE(created_at) as day'),
                DB::raw('COUNT(DISTINCT survey_progress_id) as recipients'),
                DB::raw('MIN(created_at) as first_at')
            )
            ->groupBy(DB::raw('DATE(created_at)'))
            ->orderBy('day')
            ->get();

        $seq = 0;
        foreach ($reminderDays as $day) {
            $seq++;
            $stages[] = [
                'label' => $this->ordinal($seq) . ' Reminder',
                'type' => 'reminder',
                'seq' => $seq,
                'sent' => (int) $day->recipients,
                'dispatched_at' => $day->first_at,
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
     * Participation summary for the campaign: how many members were involved,
     * how many completed, how many stalled, and where the stalled ones stopped
     * (per question, in survey order). Bilingual question pairs are consolidated
     * onto the English question, mirroring the response data sheet.
     *
     * @return array{involved:int,completed:int,stalled:int,byQuestion:array<int,array{position:int,question:string,count:int}>}
     */
    public function participation(int $surveyId, ?int $groupId = null, ?string $since = null): array
    {
        $since = $this->resolveSince($groupId, $since);

        // Only the campaign's own progress rows (created on/after the anchor), so
        // earlier waves to the same members are excluded.
        $base = $this->scopedProgressQuery($surveyId, $groupId)
            ->when($since, fn ($q) => $q->where('created_at', '>=', $since));

        $involved = (clone $base)->count();
        $completed = (clone $base)->whereNotNull('completed_at')->count();
        $stalled = $involved - $completed;

        // Where the non-completed members are currently stuck.
        $stallCounts = (clone $base)
            ->whereNull('completed_at')
            ->select('current_question_id', DB::raw('count(*) as c'))
            ->groupBy('current_question_id')
            ->pluck('c', 'current_question_id');

        $byQuestion = [];
        $attributed = 0;
        $position = 0;

        $survey = \App\Models\Survey::find($surveyId);
        if ($survey) {
            $englishQuestions = $survey->questions()
                ->whereNotNull('swahili_question_id')
                ->orderBy('pivot_position')
                ->get();

            foreach ($englishQuestions as $question) {
                $position++;
                $count = (int) ($stallCounts[$question->id] ?? 0);

                // Fold the Swahili variant's stalls into the English row.
                if ($question->swahili_question_id && $question->swahili_question_id != $question->id) {
                    $count += (int) ($stallCounts[$question->swahili_question_id] ?? 0);
                }

                $attributed += $count;
                $byQuestion[] = [
                    'position' => $position,
                    'question' => $question->question,
                    'count' => $count,
                ];
            }
        }

        // Anything not attributable to a listed question (e.g. null current
        // question) is surfaced so the breakdown reconciles with "stalled".
        $remainder = $stalled - $attributed;
        if ($remainder > 0) {
            $byQuestion[] = [
                'position' => $position + 1,
                'question' => 'Not started / other',
                'count' => $remainder,
            ];
        }

        return [
            'involved' => $involved,
            'completed' => $completed,
            'stalled' => $stalled,
            'byQuestion' => $byQuestion,
        ];
    }

    /**
     * The group's campaign start (its created_at) unless an explicit cutoff is given.
     */
    protected function resolveSince(?int $groupId, ?string $since): ?string
    {
        if ($since === null && $groupId) {
            return optional(Group::find($groupId))->created_at?->toDateTimeString();
        }

        return $since;
    }

    /**
     * Survey progress query for the survey, optionally limited to a group's members
     * (via the group_member pivot OR the legacy members.group_id column).
     */
    protected function scopedProgressQuery(int $surveyId, ?int $groupId)
    {
        return SurveyProgress::where('survey_id', $surveyId)
            ->when($groupId, function ($query) use ($groupId) {
                $query->whereHas('member', function ($memberQuery) use ($groupId) {
                    $memberQuery->where('group_id', $groupId)
                        ->orWhereHas('groups', function ($groupQuery) use ($groupId) {
                            $groupQuery->where('groups.id', $groupId);
                        });
                });
            });
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
