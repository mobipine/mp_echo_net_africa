<?php

namespace App\Services;

use App\Models\CreditTransaction;
use App\Models\SmsCredit;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;

class CreditUtilizationReportService
{
    private const DIRECTIONS = ['add', 'subtract'];

    private const TRANSACTION_TYPES = ['load', 'sms_sent', 'sms_received'];

    private const MESSAGE_SCOPES = ['all', 'reminders', 'standard'];

    public function query(array $filters = []): Builder
    {
        return $this->applyFilters(
            CreditTransaction::query()->with($this->ledgerRelationships()),
            $filters
        );
    }

    public function applyFilters(Builder $query, array $filters = []): Builder
    {
        $filters = $this->normalizeFilters($filters);

        $query
            ->when($filters['date_from'], fn (Builder $query, string $date) => $query
                ->where('credit_transactions.created_at', '>=', CarbonImmutable::parse($date)->startOfDay()))
            ->when($filters['date_to'], fn (Builder $query, string $date) => $query
                ->where('credit_transactions.created_at', '<=', CarbonImmutable::parse($date)->endOfDay()))
            ->when($filters['directions'], fn (Builder $query, array $directions) => $query
                ->whereIn('credit_transactions.type', $directions))
            ->when($filters['transaction_types'], fn (Builder $query, array $types) => $query
                ->whereIn('credit_transactions.transaction_type', $types));

        if ($filters['survey_ids']) {
            $query->where(function (Builder $query) use ($filters) {
                $query
                    ->whereHas('surveyResponse', fn (Builder $query) => $query
                        ->whereIn('survey_id', $filters['survey_ids']))
                    ->orWhereHas('smsInbox.surveyProgress', fn (Builder $query) => $query
                        ->whereIn('survey_id', $filters['survey_ids']));
            });
        }

        if ($filters['group_ids']) {
            $query->where(function (Builder $query) use ($filters) {
                $query
                    ->whereHas('smsInbox.member', fn (Builder $query) => $query
                        ->where(function (Builder $query) use ($filters) {
                            $query
                                ->whereIn('group_id', $filters['group_ids'])
                                ->orWhereHas('groups', fn (Builder $query) => $query
                                    ->whereIn('groups.id', $filters['group_ids']));
                        }))
                    ->orWhereHas('surveyResponse.member', fn (Builder $query) => $query
                        ->where(function (Builder $query) use ($filters) {
                            $query
                                ->whereIn('group_id', $filters['group_ids'])
                                ->orWhereHas('groups', fn (Builder $query) => $query
                                    ->whereIn('groups.id', $filters['group_ids']));
                        }));
            });
        }

        if ($filters['county_ids']) {
            $query->where(function (Builder $query) use ($filters) {
                $query
                    ->whereHas('smsInbox.member', fn (Builder $query) => $query
                        ->whereIn('county_id', $filters['county_ids']))
                    ->orWhereHas('surveyResponse.member', fn (Builder $query) => $query
                        ->whereIn('county_id', $filters['county_ids']));
            });
        }

        if ($filters['channels']) {
            $query->where(function (Builder $query) use ($filters) {
                $query
                    ->whereHas('smsInbox', fn (Builder $query) => $query
                        ->whereIn(DB::raw('LOWER(channel)'), $filters['channels']))
                    ->orWhereHas('surveyResponse.inbox', fn (Builder $query) => $query
                        ->whereIn(DB::raw('LOWER(channel)'), $filters['channels']));
            });
        }

        if ($filters['message_scope'] !== 'all') {
            $isReminder = $filters['message_scope'] === 'reminders';
            $query->whereHas('smsInbox', fn (Builder $query) => $query
                ->where('is_reminder', $isReminder));
        }

        return $query;
    }

    public function normalizeFilters(array $filters): array
    {
        $dateFrom = $this->normalizeDate($filters['date_from'] ?? null);
        $dateTo = $this->normalizeDate($filters['date_to'] ?? null);
        $messageScope = $filters['message_scope'] ?? 'all';

        if ($dateFrom && $dateTo && $dateFrom > $dateTo) {
            [$dateFrom, $dateTo] = [$dateTo, $dateFrom];
        }

        return [
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
            'survey_ids' => $this->normalizeIds($filters['survey_ids'] ?? []),
            'group_ids' => $this->normalizeIds($filters['group_ids'] ?? []),
            'county_ids' => $this->normalizeIds($filters['county_ids'] ?? []),
            'directions' => $this->normalizeOptions($filters['directions'] ?? [], self::DIRECTIONS),
            'transaction_types' => $this->normalizeOptions(
                $filters['transaction_types'] ?? [],
                self::TRANSACTION_TYPES
            ),
            'channels' => $this->normalizeStrings($filters['channels'] ?? []),
            'message_scope' => in_array($messageScope, self::MESSAGE_SCOPES, true)
                ? $messageScope
                : 'all',
        ];
    }

    public function summary(array $filters = []): array
    {
        return $this->summaryFromQuery($this->query($filters));
    }

    public function summaryFromQuery(Builder $query): array
    {
        $aggregate = (clone $query)
            ->reorder()
            ->selectRaw('COUNT(*) as transaction_count')
            ->selectRaw("COALESCE(SUM(CASE WHEN credit_transactions.type = 'add' THEN credit_transactions.amount ELSE 0 END), 0) as credits_loaded")
            ->selectRaw("COALESCE(SUM(CASE WHEN credit_transactions.type = 'subtract' THEN credit_transactions.amount ELSE 0 END), 0) as credits_used")
            ->selectRaw("COALESCE(SUM(CASE WHEN credit_transactions.transaction_type = 'sms_sent' THEN credit_transactions.amount ELSE 0 END), 0) as credits_sent")
            ->selectRaw("COALESCE(SUM(CASE WHEN credit_transactions.transaction_type = 'sms_received' THEN credit_transactions.amount ELSE 0 END), 0) as credits_received")
            ->selectRaw("COALESCE(SUM(CASE WHEN credit_transactions.type = 'add' THEN credit_transactions.amount ELSE -credit_transactions.amount END), 0) as net_movement")
            ->first();

        $first = (clone $query)->reorder('credit_transactions.created_at')->orderBy('credit_transactions.id')->first();
        $last = (clone $query)->reorder('credit_transactions.created_at', 'desc')->orderByDesc('credit_transactions.id')->first();
        $observedDays = (int) ((clone $query)
            ->reorder()
            ->selectRaw('COUNT(DISTINCT DATE(credit_transactions.created_at)) as aggregate')
            ->value('aggregate') ?? 0);
        $surveyAttributed = (int) ((clone $query)
            ->reorder()
            ->where('credit_transactions.type', 'subtract')
            ->where(function (Builder $query) {
                $query
                    ->whereHas('surveyResponse', fn (Builder $query) => $query->whereNotNull('survey_id'))
                    ->orWhereHas('smsInbox.surveyProgress', fn (Builder $query) => $query->whereNotNull('survey_id'));
            })
            ->sum('credit_transactions.amount'));

        $used = (int) ($aggregate?->credits_used ?? 0);
        $loaded = (int) ($aggregate?->credits_loaded ?? 0);

        return [
            'current_balance' => SmsCredit::getBalance(),
            'opening_balance' => $first?->balance_before,
            'closing_balance' => $last?->balance_after,
            'transaction_count' => (int) ($aggregate?->transaction_count ?? 0),
            'credits_loaded' => $loaded,
            'credits_used' => $used,
            'credits_sent' => (int) ($aggregate?->credits_sent ?? 0),
            'credits_received' => (int) ($aggregate?->credits_received ?? 0),
            'net_movement' => (int) ($aggregate?->net_movement ?? 0),
            'survey_attributed' => $surveyAttributed,
            'non_survey_usage' => max(0, $used - $surveyAttributed),
            'observed_days' => $observedDays,
            'average_daily_usage' => $observedDays > 0 ? round($used / $observedDays, 2) : 0,
            'utilization_to_load_ratio' => $loaded > 0 ? $used / $loaded : null,
        ];
    }

    public function dailyBreakdown(array $filters = []): Collection
    {
        return $this->dailyBreakdownFromQuery($this->query($filters));
    }

    public function dailyBreakdownFromQuery(Builder $query): Collection
    {
        $rows = (clone $query)
            ->reorder()
            ->selectRaw('DATE(credit_transactions.created_at) as usage_date')
            ->selectRaw('COUNT(*) as transaction_count')
            ->selectRaw('MIN(credit_transactions.id) as first_transaction_id')
            ->selectRaw('MAX(credit_transactions.id) as last_transaction_id')
            ->selectRaw("SUM(CASE WHEN credit_transactions.type = 'add' THEN credit_transactions.amount ELSE 0 END) as credits_loaded")
            ->selectRaw("SUM(CASE WHEN credit_transactions.type = 'subtract' THEN credit_transactions.amount ELSE 0 END) as credits_used")
            ->selectRaw("SUM(CASE WHEN credit_transactions.transaction_type = 'sms_sent' THEN credit_transactions.amount ELSE 0 END) as credits_sent")
            ->selectRaw("SUM(CASE WHEN credit_transactions.transaction_type = 'sms_received' THEN credit_transactions.amount ELSE 0 END) as credits_received")
            ->groupBy(DB::raw('DATE(credit_transactions.created_at)'))
            ->orderBy('usage_date')
            ->get();

        $balanceTransactions = CreditTransaction::query()
            ->whereIn('id', $rows->pluck('first_transaction_id')->merge($rows->pluck('last_transaction_id'))->filter()->unique())
            ->get(['id', 'balance_before', 'balance_after'])
            ->keyBy('id');

        return $rows->map(function ($row) use ($balanceTransactions) {
            $loaded = (int) $row->credits_loaded;
            $used = (int) $row->credits_used;

            return [
                'date' => $row->usage_date,
                'credits_loaded' => $loaded,
                'credits_used' => $used,
                'credits_sent' => (int) $row->credits_sent,
                'credits_received' => (int) $row->credits_received,
                'net_movement' => $loaded - $used,
                'transaction_count' => (int) $row->transaction_count,
                'opening_balance' => $balanceTransactions->get($row->first_transaction_id)?->balance_before,
                'closing_balance' => $balanceTransactions->get($row->last_transaction_id)?->balance_after,
            ];
        });
    }

    public function surveyBreakdown(array $filters = []): Collection
    {
        $query = $this->query($filters)
            ->withoutEagerLoads()
            ->leftJoin('survey_responses as report_responses', 'credit_transactions.survey_response_id', '=', 'report_responses.id')
            ->leftJoin('sms_inboxes as report_inboxes', 'credit_transactions.sms_inbox_id', '=', 'report_inboxes.id')
            ->leftJoin('survey_progress as report_progress', 'report_inboxes.survey_progress_id', '=', 'report_progress.id')
            ->leftJoin('surveys as report_surveys', function ($join) {
                $join->on('report_surveys.id', '=', DB::raw('COALESCE(report_responses.survey_id, report_progress.survey_id)'));
            })
            ->where('credit_transactions.type', 'subtract')
            ->reorder()
            ->selectRaw('report_surveys.id as survey_id, report_surveys.title as survey_title')
            ->selectRaw('COUNT(*) as transaction_count')
            ->selectRaw('SUM(credit_transactions.amount) as credits_used')
            ->selectRaw("SUM(CASE WHEN credit_transactions.transaction_type = 'sms_sent' THEN credit_transactions.amount ELSE 0 END) as credits_sent")
            ->selectRaw("SUM(CASE WHEN credit_transactions.transaction_type = 'sms_received' THEN credit_transactions.amount ELSE 0 END) as credits_received")
            ->selectRaw('AVG(credit_transactions.amount) as average_credits')
            ->selectRaw('MIN(credit_transactions.created_at) as first_activity_at')
            ->selectRaw('MAX(credit_transactions.created_at) as last_activity_at')
            ->groupBy('report_surveys.id', 'report_surveys.title')
            ->orderByDesc('credits_used')
            ->get();

        $totalUsed = (int) $query->sum('credits_used');

        return $query->map(fn ($row) => [
            'survey_id' => $row->survey_id ? (int) $row->survey_id : null,
            'survey_title' => $row->survey_title ?: 'Non-survey / unattributed',
            'credits_used' => (int) $row->credits_used,
            'credits_sent' => (int) $row->credits_sent,
            'credits_received' => (int) $row->credits_received,
            'transaction_count' => (int) $row->transaction_count,
            'average_credits' => round((float) $row->average_credits, 2),
            'share_of_usage' => $totalUsed > 0 ? ((int) $row->credits_used / $totalUsed) : 0,
            'first_activity_at' => $row->first_activity_at,
            'last_activity_at' => $row->last_activity_at,
        ]);
    }

    public function ledgerRelationships(): array
    {
        return [
            'user:id,name,email',
            'smsInbox:id,message,status,phone_number,member_id,channel,is_reminder,credits_count,survey_progress_id,delivery_status,delivery_status_desc',
            'smsInbox.member:id,name,phone,county_id,group_id',
            'smsInbox.member.county:id,name',
            'smsInbox.member.groups:id,name',
            'smsInbox.surveyProgress:id,survey_id,member_id',
            'smsInbox.surveyProgress.survey:id,title',
            'surveyResponse:id,survey_id,question_id,msisdn,inbox_id',
            'surveyResponse.survey:id,title',
            'surveyResponse.question:id,question',
            'surveyResponse.member:id,name,phone,county_id,group_id',
            'surveyResponse.member.county:id,name',
            'surveyResponse.member.groups:id,name',
            'surveyResponse.inbox:id,message,status,phone_number,member_id,channel,is_reminder,credits_count,survey_progress_id,delivery_status,delivery_status_desc',
        ];
    }

    private function normalizeDate(mixed $value): ?string
    {
        if (blank($value)) {
            return null;
        }

        try {
            return CarbonImmutable::parse($value)->toDateString();
        } catch (Throwable) {
            return null;
        }
    }

    private function normalizeIds(mixed $values): array
    {
        return collect(is_array($values) ? $values : [$values])
            ->filter(fn ($value) => is_numeric($value) && (int) $value > 0)
            ->map(fn ($value) => (int) $value)
            ->unique()
            ->values()
            ->all();
    }

    private function normalizeOptions(mixed $values, array $allowed): array
    {
        return array_values(array_intersect($this->normalizeStrings($values), $allowed));
    }

    private function normalizeStrings(mixed $values): array
    {
        return collect(is_array($values) ? $values : [$values])
            ->filter(fn ($value) => is_string($value) && filled($value))
            ->map(fn (string $value) => strtolower(trim($value)))
            ->unique()
            ->values()
            ->all();
    }
}
