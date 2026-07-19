<?php

namespace App\Jobs;

use App\Filament\Resources\SMSResource;
use App\Models\Member;
use App\Models\Survey;
use App\Models\User;
use App\Services\SurveyDispatchService;
use Carbon\Carbon;
use Filament\Notifications\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class SendSurveyToMembersJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $uniqueFor = 7200;

    public int $timeout = 3600;

    public function __construct(
        public array $memberIds,
        public Survey $survey,
        public string $channel,
        public ?int $limit = null,
        public ?string $dispatchBatchUuid = null,
        public ?int $userId = null,
        public bool $restartOpenProgress = false,
        public ?string $restartBefore = null,
    ) {
        $this->dispatchBatchUuid ??= Str::uuid()->toString();
    }

    public function uniqueId(): string
    {
        $memberIds = app(SurveyDispatchService::class)->normalizeMemberIds($this->memberIds);

        return 'send-survey-'.$this->survey->id
            .'-members-'.md5(implode('-', $memberIds))
            ."-{$this->channel}";
    }

    public function handle(SurveyDispatchService $dispatchService): void
    {
        Log::info("Starting SendSurveyToMembersJob for survey '{$this->survey->title}'", [
            'survey_id' => $this->survey->id,
            'selected_members' => count($this->memberIds),
            'limit' => $this->limit,
            'restart_open_progress' => $this->restartOpenProgress,
            'restart_before' => $this->restartBefore,
            'dispatch_batch_uuid' => $this->dispatchBatchUuid,
        ]);

        $summary = $this->processMemberIds($dispatchService);

        Log::info("SendSurveyToMembersJob completed for survey '{$this->survey->title}'", $summary);
        $this->notifyCompletion($summary);
    }

    private function processMemberIds(SurveyDispatchService $dispatchService): array
    {
        $firstQuestion = getNextQuestion($this->survey->id, null, null);

        if (is_array($firstQuestion)) {
            $reason = $firstQuestion['message'] ?? 'Unknown survey flow error';
            Log::error("Error getting first question for survey '{$this->survey->title}': {$reason}");

            return $this->summary(0, count($this->memberIds), ['Survey has no valid first question' => count($this->memberIds)], 0);
        }

        if (! $firstQuestion || ! $firstQuestion instanceof \App\Models\SurveyQuestion) {
            Log::info("Survey '{$this->survey->title}' has no questions. No SMS sent.");

            return $this->summary(0, count($this->memberIds), ['Survey has no questions' => count($this->memberIds)], 0);
        }

        $memberIds = $dispatchService->eligibleMemberIdsQuery($this->memberIds)->pluck('members.id')->all();
        $members = Member::whereIn('id', $memberIds)->orderBy('id')->get()->keyBy('id');
        $restartBefore = $this->restartBefore ? Carbon::parse($this->restartBefore) : null;

        $queued = 0;
        $skippedReasons = [];
        $cancelledProgress = 0;

        foreach ($memberIds as $memberId) {
            if ($this->limit !== null && $queued >= $this->limit) {
                $skippedReasons['Recipient limit reached'] = ($skippedReasons['Recipient limit reached'] ?? 0) + 1;

                continue;
            }

            $member = $members->get($memberId);
            if (! $member) {
                $skippedReasons['Member record not found'] = ($skippedReasons['Member record not found'] ?? 0) + 1;

                continue;
            }

            $result = $dispatchService->dispatchToMember(
                $member,
                $this->survey,
                $firstQuestion,
                $this->channel,
                'manual',
                $this->dispatchBatchUuid,
                $this->restartOpenProgress,
                $restartBefore
            );

            if (($result['status'] ?? null) === 'queued') {
                $queued++;
                $cancelledProgress += (int) ($result['cancelled_progress_count'] ?? 0);

                continue;
            }

            $reason = $result['reason'] ?? 'Skipped';
            $skippedReasons[$reason] = ($skippedReasons[$reason] ?? 0) + 1;
        }

        return $this->summary($queued, array_sum($skippedReasons), $skippedReasons, $cancelledProgress, count($memberIds));
    }

    private function summary(int $queued, int $skipped, array $skipReasons, int $cancelledProgress, ?int $eligibleMembers = null): array
    {
        return [
            'queued' => $queued,
            'skipped' => $skipped,
            'skip_reasons' => $skipReasons,
            'cancelled_stale_progress' => $cancelledProgress,
            'eligible_members' => $eligibleMembers ?? 0,
            'dispatch_batch_uuid' => $this->dispatchBatchUuid,
        ];
    }

    private function notifyCompletion(array $summary): void
    {
        if (! $this->userId || ! ($user = User::find($this->userId))) {
            return;
        }

        $body = "Queued {$summary['queued']} SMS message(s); skipped {$summary['skipped']} member(s).";
        if (($summary['cancelled_stale_progress'] ?? 0) > 0) {
            $body .= " Restarted {$summary['cancelled_stale_progress']} stale open progress record(s).";
        }

        Notification::make()
            ->title('Individual survey dispatch complete')
            ->body($body)
            ->success()
            ->actions([
                Action::make('view_sms')
                    ->label('View SMS records')
                    ->url(SMSResource::getUrl('index'), shouldOpenInNewTab: true)
                    ->button(),
            ])
            ->sendToDatabase($user);
    }
}
