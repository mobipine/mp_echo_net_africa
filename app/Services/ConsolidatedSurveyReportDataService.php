<?php

namespace App\Services;

use App\Models\Group;
use App\Models\GroupSurvey;
use App\Models\Member;
use App\Models\SurveyProgress;
use App\Models\SurveyResponse;
use App\Support\SurveyProgressState;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ConsolidatedSurveyReportDataService
{
    public function __construct(private readonly CreditUtilizationReportService $creditReports) {}

    public function stream(
        Collection $surveys,
        Collection $questions,
        array $creditFilters,
        callable $writeParticipantSurvey,
        callable $writeParticipantResponse,
    ): array {
        $surveyById = $surveys->keyBy(fn ($survey): int => (int) $survey->id);
        $surveyIds = $surveyById->keys()->all();
        $questionBySurveyAndId = [];
        $questionDetailsBySurveyAndId = [];
        $questionStats = [];

        foreach ($questions as $question) {
            $surveyId = (int) $question['survey_id'];
            $questionId = (int) $question['id'];
            $key = $surveyId.':'.$questionId;
            $questionStats[$key] = [
                'survey_id' => $surveyId,
                'survey_title' => $question['survey_title'],
                'position' => (int) $question['position'],
                'question' => $question['question'],
                'response_count' => 0,
                'respondent_ids' => [],
                'answer_counts' => [],
                'first_response_at' => null,
                'last_response_at' => null,
            ];

            foreach ($question['answer_ids'] as $answerId) {
                $questionBySurveyAndId[$surveyId][(int) $answerId] = $questionId;
                $questionDetailsBySurveyAndId[$surveyId][(int) $answerId] = $question;
            }
        }

        $pairs = $this->configuredGroupSurveyPairs($surveyIds);
        $pairStats = [];
        $batchToPair = [];
        $batchCandidates = [];
        foreach ($pairs as $key => $pair) {
            $pairStats[$key] = [
                'group_id' => $pair['group_id'],
                'group_name' => $pair['group_name'],
                'survey_id' => $pair['survey_id'],
                'survey_title' => $pair['survey_title'],
                'survey_status' => $pair['survey_status'],
                'queued_recipients' => $pair['queued_recipients'],
                'skipped_recipients' => $pair['skipped_recipients'],
                'was_dispatched' => $pair['was_dispatched'],
                'dispatched_at' => $pair['dispatched_at'],
                'dispatch_batch_uuids' => $pair['dispatch_batch_uuids'],
                'progress_count' => 0,
                'response_count' => 0,
                'respondent_ids' => [],
                'completed_ids' => [],
                'in_progress_ids' => [],
                'dropped_ids' => [],
                'no_response_ids' => [],
                'attribution' => $pair['dispatch_batch_uuids'] === []
                    ? 'No reliable dispatch batch recorded'
                    : 'Verified by dispatch batch',
                'attributed_credits' => 0,
            ];

            foreach ($pair['dispatch_batch_uuids'] as $batchUuid) {
                $batchCandidates[$batchUuid][$key] = true;
            }
        }
        $ambiguousBatchUuids = [];
        foreach ($batchCandidates as $batchUuid => $candidatePairs) {
            if (count($candidatePairs) === 1) {
                $batchToPair[$batchUuid] = array_key_first($candidatePairs);
            } else {
                $ambiguousBatchUuids[$batchUuid] = true;
            }
        }

        $surveyStats = [];
        foreach ($surveyById as $surveyId => $survey) {
            $surveyStats[$surveyId] = [
                'survey_id' => (int) $surveyId,
                'survey_title' => $survey->title,
                'survey_status' => $survey->status,
                'configured_group_ids' => [],
                'dispatched_group_ids' => [],
                'participant_ids' => [],
                'observed_participant_ids' => [],
                'respondent_ids' => [],
                'completed_ids' => [],
                'in_progress_ids' => [],
                'dropped_ids' => [],
                'no_response_ids' => [],
                'unattributed_participant_surveys' => 0,
                'unattributed_response_count' => 0,
                'response_count' => 0,
                'attributed_group_credits' => 0,
                'credit_summary' => [],
            ];
        }

        foreach ($pairStats as $pair) {
            $surveyStats[$pair['survey_id']]['configured_group_ids'][$pair['group_id']] = true;
            if ($pair['was_dispatched'] || $pair['queued_recipients'] > 0) {
                $surveyStats[$pair['survey_id']]['dispatched_group_ids'][$pair['group_id']] = true;
            }
        }

        $responseCountsByProgress = [];
        $respondedProgressIds = [];
        $globalParticipantIds = [];
        $pairStatsProgressIds = [];
        $unattributedResponses = 0;

        SurveyResponse::query()
            ->whereIn('survey_id', $surveyIds)
            ->orderBy('id')
            ->chunkById(500, function (Collection $responses) use (
                $surveyById,
                $questionBySurveyAndId,
                $questionDetailsBySurveyAndId,
                &$questionStats,
                &$surveyStats,
                &$pairStats,
                $batchToPair,
                $ambiguousBatchUuids,
                &$responseCountsByProgress,
                &$respondedProgressIds,
                &$globalParticipantIds,
                &$unattributedResponses,
                $writeParticipantResponse,
            ): void {
                $progressById = SurveyProgress::query()
                    ->whereIn('id', $responses->pluck('session_id')->filter()->unique())
                    ->with('member.county')
                    ->get()
                    ->keyBy('id');
                $legacyMembersByPhone = $this->membersByNormalizedPhone($responses);

                foreach ($responses as $response) {
                    $surveyId = (int) $response->survey_id;
                    $survey = $surveyById->get($surveyId);
                    if (! $survey) {
                        continue;
                    }

                    $progress = $progressById->get($response->session_id);
                    $member = $progress?->member;
                    $phoneMatches = $member
                        ? collect([$member])
                        : $legacyMembersByPhone->get(normalizePhoneNumber((string) $response->msisdn), collect());
                    if (! $member && $phoneMatches->count() === 1) {
                        $member = $phoneMatches->first();
                    }
                    $memberId = $member?->id ? (int) $member->id : null;
                    $batchUuid = $progress?->dispatch_batch_uuid;
                    $pairKey = $batchUuid ? ($batchToPair[$batchUuid] ?? null) : null;
                    $groupName = $pairKey ? $pairStats[$pairKey]['group_name'] : null;
                    $attribution = $pairKey
                        ? 'Verified by dispatch batch'
                        : (isset($ambiguousBatchUuids[$batchUuid])
                            ? 'Unattributed: batch matches multiple group assignments'
                            : ($progress ? 'Unattributed: no matching group dispatch batch' : 'Unattributed: no linked survey progress'));
                    $questionId = $questionBySurveyAndId[$surveyId][(int) $response->question_id] ?? null;
                    $question = $questionDetailsBySurveyAndId[$surveyId][(int) $response->question_id] ?? null;

                    if ($progress) {
                        $responseCountsByProgress[$progress->id] = ($responseCountsByProgress[$progress->id] ?? 0) + 1;
                        $respondedProgressIds[$progress->id] = true;
                    }

                    if ($memberId !== null) {
                        $globalParticipantIds[$memberId] = true;
                        $surveyStats[$surveyId]['observed_participant_ids'][$memberId] = true;
                        $surveyStats[$surveyId]['respondent_ids'][$memberId] = true;
                    }

                    $surveyStats[$surveyId]['response_count']++;
                    if ($pairKey && $memberId !== null) {
                        $pairStats[$pairKey]['respondent_ids'][$memberId] = true;
                    } elseif (! $pairKey) {
                        $unattributedResponses++;
                        $surveyStats[$surveyId]['unattributed_response_count']++;
                    }
                    if ($pairKey) {
                        $pairStats[$pairKey]['response_count']++;
                    }

                    if ($questionId !== null) {
                        $statsKey = $surveyId.':'.$questionId;
                        $questionStats[$statsKey]['response_count']++;
                        if ($memberId !== null) {
                            $questionStats[$statsKey]['respondent_ids'][$memberId] = true;
                        }

                        $answer = filled($response->survey_response)
                            ? trim((string) $response->survey_response)
                            : '(Blank)';
                        $questionStats[$statsKey]['answer_counts'][$answer] =
                            ($questionStats[$statsKey]['answer_counts'][$answer] ?? 0) + 1;
                        $createdAt = $response->created_at?->toDateTimeString();
                        if ($createdAt) {
                            $questionStats[$statsKey]['first_response_at'] = min(
                                $questionStats[$statsKey]['first_response_at'] ?? $createdAt,
                                $createdAt,
                            );
                            $questionStats[$statsKey]['last_response_at'] = max(
                                $questionStats[$statsKey]['last_response_at'] ?? $createdAt,
                                $createdAt,
                            );
                        }
                    }

                    $writeParticipantResponse([
                        $survey->title,
                        $surveyId,
                        $survey->status,
                        $groupName ?? 'Unattributed / legacy',
                        $pairKey ? $pairStats[$pairKey]['group_id'] : null,
                        $attribution,
                        $memberId,
                        $member?->name ?? ($phoneMatches->count() > 1 ? 'Ambiguous phone match' : 'Unmatched participant'),
                        $member?->phone ?? $response->msisdn,
                        $question['position'] ?? null,
                        $question['question'] ?? 'Question unavailable',
                        $response->survey_response ?? '',
                        $response->created_at?->toDateTimeString(),
                        $progress?->status ?? 'No linked progress',
                        $batchUuid,
                    ]);
                }
            });

        $latestProgressIds = SurveyProgress::query()
            ->selectRaw('MAX(id)')
            ->whereIn('survey_id', $surveyIds)
            ->groupBy('survey_id', 'member_id');

        SurveyProgress::query()
            ->whereIn('id', $latestProgressIds)
            ->with('member.county')
            ->orderBy('id')
            ->chunkById(500, function (Collection $progresses) use (
                $surveyById,
                &$surveyStats,
                &$pairStats,
                $batchToPair,
                $ambiguousBatchUuids,
                $responseCountsByProgress,
                $respondedProgressIds,
                &$globalParticipantIds,
                &$pairStatsProgressIds,
                $writeParticipantSurvey,
            ): void {
                foreach ($progresses as $progress) {
                    $surveyId = (int) $progress->survey_id;
                    $survey = $surveyById->get($surveyId);
                    if (! $survey) {
                        continue;
                    }

                    $member = $progress->member;
                    $memberId = $member?->id ? (int) $member->id : null;
                    $participantKey = $memberId ?? 'progress-'.$progress->id;
                    $batchUuid = $progress->dispatch_batch_uuid;
                    $pairKey = $batchUuid ? ($batchToPair[$batchUuid] ?? null) : null;
                    $responseCount = (int) ($responseCountsByProgress[$progress->id] ?? 0);
                    $responded = isset($respondedProgressIds[$progress->id]) || (bool) $progress->has_responded;
                    $completed = (bool) $progress->completed_at || strtoupper((string) $progress->status) === 'COMPLETED';
                    $open = SurveyProgressState::isOpen($progress->status, $progress->completed_at);
                    $dropped = ! $completed && ! $open;

                    $surveyStats[$surveyId]['participant_ids'][$participantKey] = true;
                    $surveyStats[$surveyId]['observed_participant_ids'][$participantKey] = true;
                    if ($memberId !== null) {
                        $globalParticipantIds[$memberId] = true;
                    }
                    if (! $pairKey) {
                        $surveyStats[$surveyId]['unattributed_participant_surveys']++;
                    } else {
                        $pairStats[$pairKey]['progress_count']++;
                        $pairStatsProgressIds[$pairKey][$participantKey] = true;
                    }

                    if ($responded) {
                        $surveyStats[$surveyId]['respondent_ids'][$participantKey] = true;
                        if ($pairKey) {
                            $pairStats[$pairKey]['respondent_ids'][$participantKey] = true;
                        }
                    }

                    if ($dropped) {
                        $surveyStats[$surveyId]['dropped_ids'][$participantKey] = true;
                        if ($pairKey) {
                            $pairStats[$pairKey]['dropped_ids'][$participantKey] = true;
                        }
                    }
                    if ($completed) {
                        $surveyStats[$surveyId]['completed_ids'][$participantKey] = true;
                        if ($pairKey) {
                            $pairStats[$pairKey]['completed_ids'][$participantKey] = true;
                        }
                    } elseif ($open && $responded) {
                        $surveyStats[$surveyId]['in_progress_ids'][$participantKey] = true;
                        if ($pairKey) {
                            $pairStats[$pairKey]['in_progress_ids'][$participantKey] = true;
                        }
                    }
                    if (! $responded) {
                        $surveyStats[$surveyId]['no_response_ids'][$participantKey] = true;
                        if ($pairKey) {
                            $pairStats[$pairKey]['no_response_ids'][$participantKey] = true;
                        }
                    }

                    $writeParticipantSurvey([
                        $survey->title,
                        $surveyId,
                        $survey->status,
                        $pairKey ? $pairStats[$pairKey]['group_name'] : 'Unattributed / legacy',
                        $pairKey ? $pairStats[$pairKey]['group_id'] : null,
                        $pairKey
                            ? 'Verified by dispatch batch'
                            : (isset($ambiguousBatchUuids[$batchUuid])
                                ? 'Unattributed: batch matches multiple group assignments'
                                : 'Unattributed: no matching group dispatch batch'),
                        $memberId,
                        $member?->name ?? 'Unmatched participant',
                        $member?->phone ?? '',
                        (int) $progress->id,
                        $progress->status ?? 'Unknown',
                        $responded ? 'Yes' : 'No',
                        $responseCount,
                        $this->formatTimestamp($progress->completed_at),
                        $progress->dispatch_batch_uuid,
                    ]);
                }
            });

        $creditsByBatch = $this->attributedCreditsByBatch(array_keys($batchToPair));
        foreach ($creditsByBatch as $batchUuid => $credits) {
            $pairKey = $batchToPair[$batchUuid] ?? null;
            if ($pairKey) {
                $pairStats[$pairKey]['attributed_credits'] += $credits;
            }
        }

        foreach ($surveyStats as $surveyId => &$stats) {
            $stats['configured_group_count'] = count($stats['configured_group_ids']);
            $stats['dispatched_group_count'] = count($stats['dispatched_group_ids']);
            $stats['participant_survey_count'] = count($stats['participant_ids']);
            $stats['unique_participants'] = count($stats['observed_participant_ids']);
            $stats['unique_respondents'] = count($stats['respondent_ids']);
            $stats['completed_count'] = count($stats['completed_ids']);
            $stats['in_progress_count'] = count($stats['in_progress_ids']);
            $stats['dropped_count'] = count($stats['dropped_ids']);
            $stats['no_response_count'] = count($stats['no_response_ids']);
            $filters = [...$creditFilters, 'survey_ids' => [(int) $surveyId]];
            $stats['credit_summary'] = $this->creditReports->summary($filters);
            $stats['attributed_group_credits'] = array_sum(array_map(
                fn (array $pair): int => $pair['survey_id'] === (int) $surveyId ? $pair['attributed_credits'] : 0,
                $pairStats,
            ));
            $stats['unattributed_credits'] = max(
                0,
                (int) $stats['credit_summary']['survey_attributed'] - $stats['attributed_group_credits'],
            );
        }
        unset($stats);

        foreach ($pairStats as &$stats) {
            $stats['unique_respondents'] = count($stats['respondent_ids']);
            $stats['completed_count'] = count($stats['completed_ids']);
            $stats['in_progress_count'] = count($stats['in_progress_ids']);
            $stats['dropped_count'] = count($stats['dropped_ids']);
            $stats['no_response_count'] = count($stats['no_response_ids']);
            $stats['response_rate'] = $stats['progress_count'] > 0
                ? $stats['unique_respondents'] / $stats['progress_count']
                : null;
            $stats['completion_rate'] = $stats['progress_count'] > 0
                ? $stats['completed_count'] / $stats['progress_count']
                : null;
            unset($stats['respondent_ids'], $stats['completed_ids'], $stats['in_progress_ids'], $stats['dropped_ids'], $stats['no_response_ids']);
        }
        unset($stats);

        $groupRows = $this->groupCoverage($pairs, $pairStats);
        $questionRows = collect($questionStats)
            ->map(function (array $stats): array {
                $stats['unique_respondents'] = count($stats['respondent_ids']);
                $stats['most_common_answer'] = collect($stats['answer_counts'])->sortDesc()->keys()->first();
                $stats['most_common_answer_count'] = $stats['answer_counts'][$stats['most_common_answer']] ?? 0;
                $stats['answer_distribution'] = collect($stats['answer_counts'])
                    ->sortDesc()
                    ->take(10)
                    ->map(fn (int $count, string $answer): string => $answer.' ('.$count.')')
                    ->implode(' | ');
                if (count($stats['answer_counts']) > 10) {
                    $stats['answer_distribution'] .= ' | See Participant Responses for all answers';
                }
                unset($stats['respondent_ids'], $stats['answer_counts']);

                return $stats;
            })
            ->sortBy([['survey_title', 'asc'], ['position', 'asc']])
            ->values();

        $surveyRows = collect($surveyStats)->map(function (array $stats): array {
            unset($stats['configured_group_ids'], $stats['dispatched_group_ids'], $stats['participant_ids'], $stats['observed_participant_ids'], $stats['respondent_ids'], $stats['completed_ids'], $stats['in_progress_ids'], $stats['dropped_ids'], $stats['no_response_ids']);
            $stats['response_rate'] = $stats['unique_participants'] > 0
                ? $stats['unique_respondents'] / $stats['unique_participants']
                : null;
            $stats['completion_rate'] = $stats['unique_participants'] > 0
                ? $stats['completed_count'] / $stats['unique_participants']
                : null;

            return $stats;
        })->values();

        $pairRows = collect($pairStats)->values();
        foreach ($surveyRows as $surveyRow) {
            if ($surveyRow['unattributed_participant_surveys'] > 0 || $surveyRow['unattributed_response_count'] > 0) {
                $pairRows->push([
                    'group_id' => null,
                    'group_name' => 'Unattributed / legacy',
                    'survey_id' => $surveyRow['survey_id'],
                    'survey_title' => $surveyRow['survey_title'],
                    'survey_status' => $surveyRow['survey_status'],
                    'queued_recipients' => 0,
                    'skipped_recipients' => 0,
                    'was_dispatched' => false,
                    'dispatched_at' => null,
                    'dispatch_batch_uuids' => [],
                    'progress_count' => $surveyRow['unattributed_participant_surveys'],
                    'response_count' => $surveyRow['unattributed_response_count'],
                    'unique_respondents' => null,
                    'completed_count' => null,
                    'in_progress_count' => null,
                    'dropped_count' => null,
                    'no_response_count' => null,
                    'response_rate' => null,
                    'completion_rate' => null,
                    'attribution' => 'Legacy progress without a reliable group batch',
                    'attributed_credits' => 0,
                ]);
            }
        }

        return [
            'survey_rows' => $surveyRows,
            'pair_rows' => $pairRows,
            'group_rows' => collect($groupRows),
            'question_rows' => $questionRows,
            'unique_participants' => count($globalParticipantIds),
            'participant_surveys' => $surveyRows->sum('participant_survey_count'),
            'respondents' => $surveyRows->sum('unique_respondents'),
            'completed' => $surveyRows->sum('completed_count'),
            'responses' => $surveyRows->sum('response_count'),
            'unattributed_participant_surveys' => $surveyRows->sum('unattributed_participant_surveys'),
            'unattributed_responses' => $unattributedResponses,
            'configured_group_survey_pairs' => count($pairs),
            'groups_with_dispatch_recorded' => collect($pairStats)->filter(
                fn (array $pair): bool => $pair['was_dispatched'] || $pair['queued_recipients'] > 0,
            )->count(),
            'queued_recipients' => collect($pairStats)->sum('queued_recipients'),
        ];
    }

    private function configuredGroupSurveyPairs(array $surveyIds): array
    {
        $pairRows = [];

        GroupSurvey::query()
            ->with(['group:id,name', 'survey:id,title,status'])
            ->whereIn('survey_id', $surveyIds)
            ->orderBy('id')
            ->get()
            ->each(function (GroupSurvey $assignment) use (&$pairRows): void {
                $key = $assignment->group_id.':'.$assignment->survey_id;
                if (! isset($pairRows[$key])) {
                    $pairRows[$key] = [
                        'group_id' => (int) $assignment->group_id,
                        'group_name' => $assignment->group?->name ?? 'Group unavailable',
                        'survey_id' => (int) $assignment->survey_id,
                        'survey_title' => $assignment->survey?->title ?? 'Survey unavailable',
                        'survey_status' => $assignment->survey?->status ?? 'Unknown',
                        'queued_recipients' => 0,
                        'skipped_recipients' => 0,
                        'was_dispatched' => false,
                        'dispatched_at' => null,
                        'dispatch_batch_uuids' => [],
                    ];
                }

                $pairRows[$key]['queued_recipients'] += (int) ($assignment->queued_count ?? 0);
                $pairRows[$key]['skipped_recipients'] += (int) ($assignment->skipped_count ?? 0);
                $pairRows[$key]['was_dispatched'] = $pairRows[$key]['was_dispatched'] || (bool) $assignment->was_dispatched;
                if ($assignment->dispatched_at && (! $pairRows[$key]['dispatched_at'] || $assignment->dispatched_at->gt($pairRows[$key]['dispatched_at']))) {
                    $pairRows[$key]['dispatched_at'] = $assignment->dispatched_at;
                }
                if ($assignment->dispatch_batch_uuid) {
                    $pairRows[$key]['dispatch_batch_uuids'][] = $assignment->dispatch_batch_uuid;
                }
            });

        foreach ($pairRows as &$pair) {
            $pair['dispatch_batch_uuids'] = array_values(array_unique($pair['dispatch_batch_uuids']));
        }
        unset($pair);

        return $pairRows;
    }

    private function attributedCreditsByBatch(array $batchUuids): array
    {
        if ($batchUuids === []) {
            return [];
        }

        return DB::table('credit_transactions as ct')
            ->leftJoin('survey_responses as sr', 'sr.id', '=', 'ct.survey_response_id')
            ->leftJoin('survey_progress as response_progress', 'response_progress.id', '=', 'sr.session_id')
            ->leftJoin('sms_inboxes as si', 'si.id', '=', 'ct.sms_inbox_id')
            ->leftJoin('survey_progress as inbox_progress', 'inbox_progress.id', '=', 'si.survey_progress_id')
            ->where('ct.type', 'subtract')
            ->where(function ($query) use ($batchUuids): void {
                $query
                    ->whereIn('response_progress.dispatch_batch_uuid', $batchUuids)
                    ->orWhereIn('inbox_progress.dispatch_batch_uuid', $batchUuids);
            })
            ->selectRaw('COALESCE(response_progress.dispatch_batch_uuid, inbox_progress.dispatch_batch_uuid) as batch_uuid')
            ->selectRaw('SUM(ct.amount) as credits_used')
            ->groupBy('batch_uuid')
            ->pluck('credits_used', 'batch_uuid')
            ->map(fn ($credits): int => (int) $credits)
            ->all();
    }

    private function groupCoverage(array $pairs, array $pairStats): array
    {
        $pairCounts = [];
        foreach ($pairs as $pair) {
            $groupId = $pair['group_id'];
            $pairCounts[$groupId]['configured_surveys'] = ($pairCounts[$groupId]['configured_surveys'] ?? 0) + 1;
            if ($pair['was_dispatched'] || $pair['queued_recipients'] > 0) {
                $pairCounts[$groupId]['dispatched_surveys'] = ($pairCounts[$groupId]['dispatched_surveys'] ?? 0) + 1;
            }
            $pairCounts[$groupId]['participant_surveys'] = ($pairCounts[$groupId]['participant_surveys'] ?? 0)
                + ($pairStats[$groupId.':'.$pair['survey_id']]['progress_count'] ?? 0);
        }

        $memberships = DB::table('members')
            ->select('group_id', DB::raw('id as member_id'))
            ->whereNotNull('group_id')
            ->union(DB::table('group_member')->select('group_id', 'member_id'));
        $memberCounts = DB::query()
            ->fromSub($memberships, 'group_memberships')
            ->select('group_id')
            ->selectRaw('COUNT(DISTINCT member_id) as member_count')
            ->groupBy('group_id')
            ->pluck('member_count', 'group_id');

        $coverage = [];
        Group::query()
            ->orderBy('name')
            ->get(['id', 'name'])
            ->each(function (Group $group) use (&$coverage, $pairCounts, $memberCounts): void {
                $counts = $pairCounts[$group->id] ?? [];
                $coverage[] = [
                    'group_id' => (int) $group->id,
                    'group_name' => $group->name,
                    'member_count' => (int) $memberCounts->get($group->id, 0),
                    'configured_surveys' => (int) ($counts['configured_surveys'] ?? 0),
                    'dispatched_surveys' => (int) ($counts['dispatched_surveys'] ?? 0),
                    'participant_surveys' => (int) ($counts['participant_surveys'] ?? 0),
                    'coverage_status' => ! ($counts['configured_surveys'] ?? 0)
                        ? 'No active survey configured'
                        : (($counts['participant_surveys'] ?? 0) > 0 ? 'Survey activity recorded' : 'Configured; no linked progress'),
                ];
            });

        return $coverage;
    }

    private function formatTimestamp(mixed $value): ?string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d H:i:s');
        }

        return filled($value) ? (string) $value : null;
    }

    private function membersByNormalizedPhone(Collection $responses): Collection
    {
        $phoneVariants = [];
        foreach ($responses->pluck('msisdn')->filter()->unique() as $phone) {
            $phoneVariants = [...$phoneVariants, ...$this->phoneVariants((string) $phone)];
        }
        $phoneVariants = array_values(array_unique($phoneVariants));

        if ($phoneVariants === []) {
            return collect();
        }

        return Member::query()
            ->whereIn('phone', $phoneVariants)
            ->get(['id', 'name', 'phone'])
            ->groupBy(fn (Member $member): string => normalizePhoneNumber((string) $member->phone));
    }

    private function phoneVariants(string $phone): array
    {
        $normalized = normalizePhoneNumber($phone);
        $international = str_starts_with($normalized, '0') ? '254'.substr($normalized, 1) : $normalized;

        return array_values(array_unique([$phone, $normalized, $international, '+'.$international]));
    }
}
