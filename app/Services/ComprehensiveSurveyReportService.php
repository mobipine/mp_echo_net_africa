<?php

namespace App\Services;

use App\Models\Group;
use App\Models\Member;
use App\Models\Survey;
use App\Models\SurveyProgress;
use App\Models\SurveyResponse;
use App\Support\SurveyProgressState;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use RuntimeException;

class ComprehensiveSurveyReportService
{
    private const MEMBER_CHUNK_SIZE = 500;

    public function __construct(private readonly CreditUtilizationReportService $creditReports) {}

    public function scope(array $filters): array
    {
        $surveyId = (int) collect($filters['survey_ids'] ?? [])->first();
        $groupId = (int) collect($filters['group_ids'] ?? [])->first();

        if ($surveyId < 1 || $groupId < 1) {
            throw new RuntimeException('A survey and group are required for a comprehensive survey report.');
        }

        $survey = Survey::query()->findOrFail($surveyId);
        $group = Group::query()->findOrFail($groupId);

        return [
            'survey' => $survey,
            'group' => $group,
            'questions' => $this->canonicalQuestions($survey),
            'credit_filters' => $this->creditReports->normalizeFilters([
                'survey_ids' => [$survey->id],
                'group_ids' => [$group->id],
                'directions' => ['subtract'],
                'transaction_types' => ['sms_sent', 'sms_received'],
                'channels' => ['sms'],
                'message_scope' => 'all',
            ]),
        ];
    }

    public function memberQuery(int $groupId): Builder
    {
        return Member::query()
            ->where(function (Builder $query) use ($groupId) {
                $query
                    ->where('group_id', $groupId)
                    ->orWhereHas('groups', fn (Builder $query) => $query->where('groups.id', $groupId));
            });
    }

    public function streamMemberResponses(
        int $surveyId,
        int $groupId,
        Collection $questions,
        callable $writeRow
    ): array {
        $questionByResponseId = [];
        $questionStats = [];

        foreach ($questions as $question) {
            foreach ($question['answer_ids'] as $answerId) {
                $questionByResponseId[$answerId] = $question['id'];
            }

            $questionStats[$question['id']] = [
                'position' => $question['position'],
                'question' => $question['question'],
                'respondents' => 0,
                'active_at_question' => 0,
                'drop_offs' => 0,
                'answer_counts' => [],
                'first_response_at' => null,
                'last_response_at' => null,
            ];
        }

        $stats = [
            'group_members' => 0,
            'dispatched' => 0,
            'responded' => 0,
            'completed' => 0,
            'in_progress' => 0,
            'dropped' => 0,
            'no_response' => 0,
            'not_dispatched' => 0,
            'total_answers' => 0,
        ];

        $this->memberQuery($groupId)
            ->with('county:id,name')
            ->orderBy('members.id')
            ->chunkById(self::MEMBER_CHUNK_SIZE, function (Collection $members) use (
                $surveyId,
                $questions,
                $questionByResponseId,
                &$questionStats,
                &$stats,
                $writeRow
            ) {
                $memberIds = $members->pluck('id');
                $progresses = SurveyProgress::query()
                    ->where('survey_id', $surveyId)
                    ->whereIn('member_id', $memberIds)
                    ->orderByDesc('id')
                    ->get()
                    ->unique('member_id')
                    ->keyBy('member_id');

                $phoneVariants = $members
                    ->pluck('phone')
                    ->filter()
                    ->flatMap(fn (string $phone): array => $this->phoneVariants($phone))
                    ->unique()
                    ->values();

                $responsesByPhone = SurveyResponse::query()
                    ->where('survey_id', $surveyId)
                    ->whereIn('msisdn', $phoneVariants)
                    ->whereIn('question_id', array_keys($questionByResponseId))
                    ->orderBy('id')
                    ->get(['id', 'msisdn', 'question_id', 'survey_response', 'created_at'])
                    ->groupBy(fn (SurveyResponse $response): string => normalizePhoneNumber($response->msisdn));

                foreach ($members as $member) {
                    $stats['group_members']++;
                    $progress = $progresses->get($member->id);
                    $memberResponses = $responsesByPhone->get(
                        normalizePhoneNumber((string) $member->phone),
                        collect()
                    );

                    $answers = [];
                    foreach ($memberResponses as $response) {
                        $questionId = $questionByResponseId[$response->question_id] ?? null;
                        if ($questionId) {
                            $answers[$questionId] = [
                                'value' => $response->survey_response,
                                'created_at' => $response->created_at,
                            ];
                        }
                    }

                    $answeredCount = count($answers);
                    $responded = $answeredCount > 0 || (bool) $progress?->has_responded;
                    $completed = $progress && ($progress->completed_at || strtoupper((string) $progress->status) === 'COMPLETED');
                    $open = $progress && SurveyProgressState::isOpen($progress->status, $progress->completed_at);
                    $dropped = $progress && ! $completed && ! $open;

                    if ($progress) {
                        $stats['dispatched']++;
                    } else {
                        $stats['not_dispatched']++;
                    }

                    if ($responded) {
                        $stats['responded']++;
                    }

                    if ($completed) {
                        $stats['completed']++;
                    } elseif ($dropped) {
                        $stats['dropped']++;
                    } elseif ($progress && $responded) {
                        $stats['in_progress']++;
                    } elseif ($progress) {
                        $stats['no_response']++;
                    }

                    $stats['total_answers'] += $answeredCount;

                    foreach ($answers as $questionId => $answer) {
                        $questionStats[$questionId]['respondents']++;
                        $answerLabel = filled($answer['value']) ? trim((string) $answer['value']) : '(Blank)';
                        $questionStats[$questionId]['answer_counts'][$answerLabel] =
                            ($questionStats[$questionId]['answer_counts'][$answerLabel] ?? 0) + 1;
                        $this->trackResponseRange($questionStats[$questionId], $answer['created_at']);
                    }

                    $currentQuestionId = $progress?->current_question_id
                        ? ($questionByResponseId[$progress->current_question_id] ?? null)
                        : null;
                    if ($open && $responded && $currentQuestionId) {
                        $questionStats[$currentQuestionId]['active_at_question']++;
                    } elseif ($dropped && $currentQuestionId) {
                        $questionStats[$currentQuestionId]['drop_offs']++;
                    }

                    $responseDates = collect($answers)->pluck('created_at')->filter()->sort();
                    $completionRate = $questions->isNotEmpty()
                        ? ($completed ? 1 : min(1, $answeredCount / $questions->count()))
                        : 0;

                    $writeRow([
                        'member' => $member,
                        'progress' => $progress,
                        'status' => $this->statusLabel($progress, $completed, $dropped, $responded),
                        'completion_rate' => $completionRate,
                        'answered_count' => $answeredCount,
                        'current_question' => $this->currentQuestionLabel(
                            $questions,
                            $currentQuestionId,
                            $progress,
                            $completed
                        ),
                        'first_response_at' => $responseDates->first(),
                        'last_response_at' => $responseDates->last(),
                        'answers' => $answers,
                    ]);
                }
            }, 'members.id', 'id');

        return [
            'stats' => $stats,
            'questions' => collect($questionStats)->sortBy('position')->values(),
        ];
    }

    private function canonicalQuestions(Survey $survey): Collection
    {
        $questions = $survey->questions()->get();
        $alternateIds = $questions
            ->filter(fn ($question) => $question->swahili_question_id && $question->swahili_question_id !== $question->id)
            ->pluck('swahili_question_id')
            ->map(fn ($id): int => (int) $id)
            ->unique();

        return $questions
            ->reject(fn ($question) => $alternateIds->contains((int) $question->id))
            ->values()
            ->map(function ($question, int $index): array {
                $alternateId = $question->swahili_question_id && $question->swahili_question_id !== $question->id
                    ? (int) $question->swahili_question_id
                    : null;

                return [
                    'id' => (int) $question->id,
                    'position' => (int) ($question->pivot?->position ?? $index + 1),
                    'question' => $question->question,
                    'answer_ids' => array_values(array_filter([(int) $question->id, $alternateId])),
                ];
            })
            ->sortBy('position')
            ->values();
    }

    private function phoneVariants(string $phone): array
    {
        $normalized = normalizePhoneNumber($phone);
        $international = str_starts_with($normalized, '0') ? '254'.substr($normalized, 1) : $normalized;

        return array_values(array_unique([$phone, $normalized, $international, '+'.$international]));
    }

    private function trackResponseRange(array &$stats, $createdAt): void
    {
        if (! $createdAt) {
            return;
        }

        $value = $createdAt->toDateTimeString();
        $stats['first_response_at'] = ! $stats['first_response_at'] || $value < $stats['first_response_at']
            ? $value
            : $stats['first_response_at'];
        $stats['last_response_at'] = ! $stats['last_response_at'] || $value > $stats['last_response_at']
            ? $value
            : $stats['last_response_at'];
    }

    private function statusLabel(?SurveyProgress $progress, bool $completed, bool $dropped, bool $responded): string
    {
        return match (true) {
            ! $progress => 'Not dispatched',
            $completed => 'Completed',
            $dropped => 'Dropped / cancelled',
            $responded => 'In progress',
            default => 'Dispatched, no response',
        };
    }

    private function currentQuestionLabel(
        Collection $questions,
        ?int $questionId,
        ?SurveyProgress $progress,
        bool $completed
    ): string {
        if ($completed) {
            return 'Completed';
        }

        if (! $progress) {
            return 'Not dispatched';
        }

        $question = $questions->firstWhere('id', $questionId);

        return $question
            ? 'Q'.$question['position'].': '.$question['question']
            : 'Current question unavailable';
    }
}
