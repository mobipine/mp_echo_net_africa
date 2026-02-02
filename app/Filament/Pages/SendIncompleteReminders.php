<?php

namespace App\Filament\Pages;

use App\Jobs\SendIncompleteRemindersJob;
use App\Models\Group;
use App\Models\GroupSurvey;
use App\Models\Survey;
use App\Models\SurveyProgress;
use Carbon\Carbon;
use Filament\Actions\Action;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Components\Grid;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

class SendIncompleteReminders extends Page implements Forms\Contracts\HasForms
{
    use Forms\Concerns\InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-bell-alert';
    protected static string $view = 'filament.pages.send-incomplete-reminders';
    protected static ?string $navigationGroup = 'Surveys';
    protected static ?string $title = 'Send Survey Reminders';

    public ?array $data = [];
    public ?array $previewData = null;

    public static function shouldRegisterNavigation(): bool
    {
        return auth()->user()?->can('page_SendSMS') ?? false;
    }

    public function mount(): void
    {
        abort_unless(static::shouldRegisterNavigation(), 403);
        $this->form->fill([
            'max_reminders' => 1,
        ]);
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Grid::make(2)->schema([
                    Select::make('group_id')
                        ->label('Group')
                        ->options(Group::orderBy('name')->pluck('name', 'id'))
                        ->required()
                        ->searchable()
                        ->live()
                        ->afterStateUpdated(fn ($set) => $set('survey_id', null)),

                    Select::make('survey_id')
                        ->label('Survey')
                        ->options(function (Forms\Get $get) {
                            $groupId = $get('group_id');
                            if (!$groupId) {
                                return [];
                            }
                            $surveyIds = GroupSurvey::where('group_id', $groupId)->pluck('survey_id')->unique();
                            return Survey::whereIn('id', $surveyIds)->pluck('title', 'id');
                        })
                        ->required()
                        ->searchable()
                        ->disabled(fn (Forms\Get $get) => !$get('group_id')),

                    Select::make('max_reminders')
                        ->label('Send to members who have received fewer than')
                        ->options([
                            1 => '0 reminders (none yet)',
                            2 => '0–1 reminders',
                            3 => '0–2 reminders',
                            4 => '0–3 reminders',
                            5 => '0–4 reminders',
                            10 => '0–9 reminders',
                            999 => 'All incomplete (no filter)',
                        ])
                        ->default(1)
                        ->required(),

                    TextInput::make('limit')
                        ->label('Limit (optional)')
                        ->numeric()
                        ->minValue(1)
                        ->integer()
                        ->placeholder('Leave empty for all')
                        ->helperText('Max number of reminders to send. Leave empty for no limit.'),
                ]),
            ])
            ->statePath('data');
    }

    /** Max records to load for preview - keeps response fast, credits are extrapolated */
    private const PREVIEW_SAMPLE_SIZE = 150;

    public function preview(): void
    {
        set_time_limit(120);

        $this->form->validate();

        $validated = $this->form->getState();
        $groupId = (int) $validated['group_id'];
        $surveyId = (int) $validated['survey_id'];
        $maxReminders = (int) $validated['max_reminders'];
        $limit = !empty($validated['limit']) ? (int) $validated['limit'] : null;

        $baseQuery = SurveyProgress::with(['survey', 'member', 'currentQuestion'])
            ->whereNull('completed_at')
            ->whereIn('status', ['ACTIVE', 'UPDATING_DETAILS'])
            ->whereNotNull('current_question_id')
            ->where('survey_id', $surveyId)
            ->whereHas('member', function ($q) use ($groupId) {
                $q->where('group_id', $groupId)
                    ->orWhereHas('groups', fn ($gq) => $gq->where('groups.id', $groupId));
            })
            ->orderBy('created_at', 'asc');

        $totalIncomplete = (clone $baseQuery)->count();

        $baseQuery->where(function ($q) use ($maxReminders) {
            $q->whereNull('number_of_reminders')
                ->orWhere('number_of_reminders', '<', $maxReminders);
        });

        $matchingCount = $baseQuery->count();

        if ($matchingCount === 0) {
            Notification::make()
                ->warning()
                ->title('No reminders to send')
                ->body('No incomplete surveys found matching your criteria.')
                ->send();
            $this->previewData = null;
            return;
        }

        $toSend = $limit ? min($limit, $matchingCount) : $matchingCount;

        // Reminders breakdown via lightweight aggregate (no formartQuestion)
        $remindersBreakdown = (clone $baseQuery)
            ->reorder()
            ->selectRaw('COALESCE(number_of_reminders, 0) as nr, COUNT(*) as cnt')
            ->groupByRaw('COALESCE(number_of_reminders, 0)')
            ->orderByRaw('COALESCE(number_of_reminders, 0)')
            ->get()
            ->mapWithKeys(fn ($row) => [
                $row->nr == 0 ? '0 (none yet)' : (string) $row->nr => $row->cnt,
            ])
            ->toArray();

        // Load only a sample for credit calculation (formartQuestion is expensive)
        $sampleSize = min(self::PREVIEW_SAMPLE_SIZE, $toSend);
        $progresses = (clone $baseQuery)->limit($sampleSize)->get();

        $totalCredits = 0;
        $totalMessages = 0;
        $creditBreakdown = [];
        $sampleRows = [];

        foreach ($progresses as $progress) {
            if ($progress->member && $progress->survey && $progress->currentQuestion) {
                $message = formartQuestion($progress->currentQuestion, $progress->member, $progress->survey, true);
                $messageLength = strlen($message);
                $credits = (int) ceil($messageLength / 160);
                $totalCredits += $credits;
                $totalMessages++;

                if (!isset($creditBreakdown[$credits])) {
                    $creditBreakdown[$credits] = 0;
                }
                $creditBreakdown[$credits]++;
            }
        }

        // Extrapolate credits and distribution to full set
        $avgCreditsPerMessage = $totalMessages > 0 ? $totalCredits / $totalMessages : 1;
        $totalCredits = (int) ceil($avgCreditsPerMessage * $toSend);
        $scaleFactor = $totalMessages > 0 ? $toSend / $totalMessages : 1;
        $creditBreakdown = array_map(fn ($c) => (int) round($c * $scaleFactor), $creditBreakdown);

        $sampleProgresses = $progresses->take(20);
        foreach ($sampleProgresses as $progress) {
            $daysOld = Carbon::parse($progress->created_at)->diffInDays(now());
            $sampleRows[] = [
                'id' => $progress->id,
                'member' => $progress->member?->name ?? 'N/A',
                'phone' => $progress->member?->phone ?? 'N/A',
                'survey' => $progress->survey?->title ?? 'N/A',
                'reminders' => $progress->number_of_reminders ?? 0,
                'created' => Carbon::parse($progress->created_at)->format('Y-m-d H:i'),
                'days_old' => $daysOld,
            ];
        }

        $sampleMessage = null;
        $sampleProgress = $progresses->first();
        if ($sampleProgress && $sampleProgress->member && $sampleProgress->survey && $sampleProgress->currentQuestion) {
            $sampleMessage = formartQuestion(
                $sampleProgress->currentQuestion,
                $sampleProgress->member,
                $sampleProgress->survey,
                true
            );
        }

        $group = Group::find($groupId);
        $survey = Survey::find($surveyId);

        $this->previewData = [
            'group_name' => $group?->name ?? 'N/A',
            'survey_title' => $survey?->title ?? 'N/A',
            'total_incomplete' => $totalIncomplete,
            'matching_count' => $matchingCount,
            'to_send' => $toSend,
            'unique_members' => $toSend,
            'total_credits' => $totalCredits,
            'total_messages' => $toSend,
            'avg_credits' => round($avgCreditsPerMessage, 2),
            'credit_breakdown' => $creditBreakdown,
            'sample_rows' => $sampleRows,
            'sample_message' => $sampleMessage,
            'sample_message_length' => $sampleMessage ? strlen($sampleMessage) : 0,
            'reminders_breakdown' => $remindersBreakdown,
            'more_count' => max(0, $toSend - 20),
        ];

        Notification::make()
            ->success()
            ->title('Preview ready')
            ->body("Found {$this->previewData['to_send']} reminders to send.")
            ->send();
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('preview')
                ->label('Preview')
                ->icon('heroicon-o-eye')
                ->color('gray')
                ->action('preview'),

            Action::make('send')
                ->label('Send Reminders')
                ->icon('heroicon-o-paper-airplane')
                ->color('success')
                ->requiresConfirmation()
                ->modalHeading('Confirm Send Reminders')
                ->modalDescription(fn () => $this->getSendModalDescription())
                ->modalSubmitActionLabel('Yes, send reminders')
                ->disabled(fn () => $this->previewData === null)
                ->action('sendReminders'),
        ];
    }

    protected function getSendModalDescription(): string
    {
        if (!$this->previewData) {
            return 'Please run Preview first to see who will receive reminders.';
        }

        return "You are about to send {$this->previewData['to_send']} reminder(s) to members in '{$this->previewData['group_name']}' for survey '{$this->previewData['survey_title']}'. This will use approximately {$this->previewData['total_credits']} SMS credits. Messages will be queued and sent by the dispatch:sms command. Continue?";
    }

    public function sendReminders(): void
    {
        if (!$this->previewData) {
            Notification::make()
                ->danger()
                ->title('Preview required')
                ->body('Please run Preview first before sending.')
                ->send();
            return;
        }

        $this->form->validate();
        $validated = $this->form->getState();
        $groupId = (int) $validated['group_id'];
        $surveyId = (int) $validated['survey_id'];
        $maxReminders = (int) $validated['max_reminders'];
        $limit = !empty($validated['limit']) ? (int) $validated['limit'] : null;

        if (!config('survey_settings.messages_enabled', true)) {
            Notification::make()
                ->danger()
                ->title('Messages disabled')
                ->body('Survey messages are disabled in config. Cannot send reminders.')
                ->send();
            return;
        }

        SendIncompleteRemindersJob::dispatch($groupId, $surveyId, $maxReminders, $limit);

        Notification::make()
            ->success()
            ->title('Reminders queued')
            ->body('Reminders have been queued and will be sent shortly. Messages will be processed by the dispatch:sms command.')
            ->send();

        $this->previewData = null;
    }
}
