<?php

namespace App\Filament\Pages;

use App\Enums\ChannelType;
use App\Jobs\SendSurveyToGroupJob;
use App\Models\Group;
use App\Models\SMSInbox;
use App\Models\Survey;
use App\Models\GroupSurvey;
use Filament\Actions\Action;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Pages\Page;
use Filament\Forms\Components\Grid;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Log;

class DispatchSurveyToMultipleGroups extends Page implements Forms\Contracts\HasForms
{
    use Forms\Concerns\InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-chat-bubble-left-ellipsis';
    protected static string $view = 'filament.pages.send-survey';
    protected static ?string $navigationGroup = 'Surveys';

    protected static ?string $title = 'Dispatch Surveys';

    public ?array $data = [];
    public ?array $previewData = null;

    private const PREVIEW_SAMPLE_SIZE = 100;

    public static function shouldRegisterNavigation(): bool
    {
        return auth()->user()?->can('page_SendSMS') ?? false;
    }

    public function mount(): void
    {
        abort_unless(static::shouldRegisterNavigation(), 403);
        $this->form->fill();
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Grid::make(2)->schema([
                    Forms\Components\Group::make()->schema([
                        Select::make('group_ids')
                            ->label('Select Groups')
                            ->options(['all' => 'ALL GROUPS'] + Group::all()->pluck('name', 'id')->toArray())
                            ->multiple()
                            ->required()
                            ->searchable()
                            ->native(false),

                        Select::make('survey_id')
                            ->label('Select Survey')
                            ->options(Survey::all()->pluck('title', 'id'))
                            ->required()
                            ->searchable()
                            ->native(false),
                    ]),

                    Forms\Components\Group::make()->schema([
                        Forms\Components\Toggle::make('automated')
                            ->label('Automated')
                            ->default(false)
                            ->helperText('Enable if you want to schedule the survey.')
                            ->reactive(),
                        Select::make('channel')
                            ->label('Channel')
                            ->options(ChannelType::options())
                            ->required()
                            ->default(ChannelType::SMS->value)
                            ->native(false),
                        Forms\Components\TextInput::make('limit')
                            ->label('Recipient limit (optional)')
                            ->numeric()
                            ->minValue(1)
                            ->integer()
                            ->placeholder('e.g. 2000')
                            ->helperText('Max number of people to send the survey to across all selected groups. Leave empty for no limit.')
                            ->nullable(),
                    ]),
                    Forms\Components\Group::make()->schema([
                        Forms\Components\DateTimePicker::make('starts_at')
                            ->label('Start Date')
                            ->native(false)
                            ->required(false)
                            ->hidden(fn($get) => !$get('automated')),

                        Forms\Components\DateTimePicker::make('ends_at')
                            ->label('End Date')
                            ->native(false)
                            ->required(false)
                            ->hidden(fn($get) => !$get('automated')),
                    ]),
                ]),
            ])
            ->statePath('data');
    }

    public function preview(): void
    {
        set_time_limit(120);
        $this->form->validate();

        $validated = $this->form->getState();
        $survey = Survey::findOrFail($validated['survey_id']);
        $selectedGroups = $validated['group_ids'];
        $isAutomated = $validated['automated'];
        $channel = $validated['channel'];
        $limit = !empty($validated['limit']) ? (int) $validated['limit'] : null;

        $firstQuestion = getNextQuestion($survey->id, null, null);
        if (is_array($firstQuestion) || !$firstQuestion instanceof \App\Models\SurveyQuestion) {
            Notification::make()
                ->danger()
                ->title('Survey has no questions')
                ->body('This survey has no valid questions or flow. Add questions before dispatching.')
                ->send();
            $this->previewData = null;
            return;
        }

        $groupIds = in_array('all', $selectedGroups)
            ? Group::pluck('id')->toArray()
            : array_map('intval', $selectedGroups);

        $groups = Group::whereIn('id', $groupIds)->orderBy('name')->get();
        $groupBreakdown = [];
        $totalEligible = 0;
        $totalActive = 0;
        $totalSkippedNoPhone = 0;
        $totalSkippedCompleted = 0;
        $totalSkippedIncomplete = 0;
        $sampleRows = [];
        $sampleForCredits = collect();
        $remainingLimit = $limit;

        foreach ($groups as $group) {
            $activeQuery = $group->members()->where('is_active', true);
            $activeCount = $activeQuery->count();
            $withPhoneCount = (clone $activeQuery)->whereNotNull('phone')->where('phone', '!=', '')->count();
            $noPhoneCount = $activeCount - $withPhoneCount;

            $completedCount = (clone $activeQuery)
                ->whereNotNull('phone')->where('phone', '!=', '')
                ->whereHas('surveyProgresses', fn ($q) => $q->where('survey_id', $survey->id)->whereNotNull('completed_at'))
                ->count();

            $incompleteCount = 0;
            if ($survey->participant_uniqueness) {
                $incompleteCount = (clone $activeQuery)
                    ->whereNotNull('phone')->where('phone', '!=', '')
                    ->whereDoesntHave('surveyProgresses', fn ($q) => $q->where('survey_id', $survey->id)->whereNotNull('completed_at'))
                    ->whereHas('surveyProgresses', fn ($q) => $q->where('survey_id', $survey->id)->whereNull('completed_at'))
                    ->count();
            }

            $eligible = $withPhoneCount - $completedCount - $incompleteCount;
            $toSend = $remainingLimit !== null ? min($eligible, $remainingLimit) : $eligible;
            if ($remainingLimit !== null) {
                $remainingLimit = max(0, $remainingLimit - $toSend);
            }

            $groupBreakdown[] = [
                'name' => $group->name,
                'active' => $activeCount,
                'with_phone' => $withPhoneCount,
                'no_phone' => $noPhoneCount,
                'completed' => $completedCount,
                'incomplete_skipped' => $incompleteCount,
                'eligible' => $eligible,
                'to_send' => $toSend,
            ];

            $totalActive += $activeCount;
            $totalSkippedNoPhone += $noPhoneCount;
            $totalSkippedCompleted += $completedCount;
            $totalSkippedIncomplete += $incompleteCount;
            $totalEligible += $eligible;

            if (count($sampleForCredits) < self::PREVIEW_SAMPLE_SIZE && $toSend > 0) {
                $members = $group->members()
                    ->where('is_active', true)
                    ->whereNotNull('phone')->where('phone', '!=', '')
                    ->whereDoesntHave('surveyProgresses', fn ($q) => $q->where('survey_id', $survey->id)->whereNotNull('completed_at'))
                    ->when($survey->participant_uniqueness, fn ($q) => $q->whereDoesntHave('surveyProgresses', fn ($sq) => $sq->where('survey_id', $survey->id)->whereNull('completed_at')))
                    ->limit(self::PREVIEW_SAMPLE_SIZE - count($sampleForCredits))
                    ->get();

                foreach ($members as $member) {
                    $sampleForCredits->push($member);
                    if (count($sampleRows) < 20) {
                        $sampleRows[] = [
                            'member' => $member->name,
                            'phone' => $member->phone,
                            'group' => $group->name,
                        ];
                    }
                }
            }
        }

        $toSendTotal = $limit ? min($totalEligible, $limit) : $totalEligible;

        if ($toSendTotal === 0) {
            Notification::make()
                ->warning()
                ->title('No eligible members')
                ->body('No members will receive this survey based on your selection. Check that groups have active members with phone numbers who have not completed this survey.')
                ->send();
            $this->previewData = null;
            return;
        }

        $totalCredits = 0;
        $sampleCount = 0;
        foreach ($sampleForCredits as $member) {
            $message = formartQuestion($firstQuestion, $member, $survey);
            $totalCredits += (int) ceil(strlen($message) / 160);
            $sampleCount++;
        }

        $avgCredits = $sampleCount > 0 ? $totalCredits / $sampleCount : 1;
        $estimatedCredits = (int) ceil($avgCredits * $toSendTotal);

        $sampleMessage = null;
        $sampleMember = $sampleForCredits->first();
        if ($sampleMember) {
            $sampleMessage = formartQuestion($firstQuestion, $sampleMember, $survey);
        }

        $this->previewData = [
            'survey_title' => $survey->title,
            'channel' => $channel,
            'is_automated' => $isAutomated,
            'starts_at' => $validated['starts_at'] ?? null,
            'ends_at' => $validated['ends_at'] ?? null,
            'limit' => $limit,
            'group_count' => count($groups),
            'total_active' => $totalActive,
            'total_skipped_no_phone' => $totalSkippedNoPhone,
            'total_skipped_completed' => $totalSkippedCompleted,
            'total_skipped_incomplete' => $totalSkippedIncomplete,
            'total_eligible' => $totalEligible,
            'to_send' => $toSendTotal,
            'estimated_credits' => $estimatedCredits,
            'participant_uniqueness' => $survey->participant_uniqueness,
            'group_breakdown' => $groupBreakdown,
            'sample_rows' => $sampleRows,
            'sample_message' => $sampleMessage,
            'more_count' => max(0, $toSendTotal - 20),
        ];

        Notification::make()
            ->success()
            ->title('Preview ready')
            ->body("Found {$toSendTotal} eligible members across " . count($groups) . " group(s).")
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

            Action::make('submit')
                ->label(fn () => ($this->data['automated'] ?? false) ? 'Schedule Survey' : 'Send Survey')
                ->icon(fn () => ($this->data['automated'] ?? false) ? 'heroicon-o-clock' : 'heroicon-o-paper-airplane')
                ->color('success')
                ->requiresConfirmation()
                ->modalHeading('Confirm Dispatch')
                ->modalDescription(fn () => $this->getSubmitModalDescription())
                ->modalSubmitActionLabel(fn () => ($this->data['automated'] ?? false) ? 'Yes, schedule' : 'Yes, send now')
                ->action('submit'),
        ];
    }

    protected function getSubmitModalDescription(): string
    {
        if (!$this->previewData) {
            return 'Run Preview first to see what will happen, then confirm to dispatch.';
        }

        $d = $this->previewData;
        $action = $d['is_automated'] ? 'schedule' : 'send';
        $msg = "You are about to {$action} survey '{$d['survey_title']}' to {$d['to_send']} members across {$d['group_count']} group(s). ";
        $msg .= "Estimated SMS credits: {$d['estimated_credits']}. ";
        $msg .= $d['is_automated'] ? "The survey will be dispatched at the scheduled time." : "Messages will be queued shortly.";
        return $msg;
    }

    public function submit(): void
    {
        $validated = $this->form->getState();
        $survey = Survey::findOrFail($validated['survey_id']);

        $selectedGroups = $validated['group_ids'];
        $isAutomated = $validated['automated'];
        $channel = $validated['channel'];

        // ---- HANDLE "ALL GROUPS" OPTION ----
        if (in_array('all', $selectedGroups)) {

            Log::info("{$survey->title} → ALL GROUPS selected. Dispatching consolidated job.");

            SendSurveyToGroupJob::dispatch(
                'all', // Special flag for ALL GROUPS
                $survey,
                $channel,
                $isAutomated,
                $validated['starts_at'] ?? null,
                $validated['ends_at'] ?? null,
                $validated['limit'] ?? null
            );

            Notification::make()
                ->title('Success!')
                ->body(
                    $isAutomated ?
                        "Survey scheduled for ALL groups." :
                        "Survey dispatch to ALL groups has started in the background."
                )
                ->success()
                ->send();

            $this->previewData = null;
            $this->form->fill();
            return;
        }

        // ---- HANDLE SPECIFIC GROUPS ----
        if ($isAutomated) {
            // Save scheduling - use firstOrCreate to prevent duplicates
            foreach ($selectedGroups as $groupId) {
                GroupSurvey::firstOrCreate(
                    [
                        'group_id'   => $groupId,
                        'survey_id'  => $survey->id,
                        'starts_at'  => $validated['starts_at'],
                    ],
                    [
                        'automated'  => true,
                        'ends_at'    => $validated['ends_at'],
                        'channel'    => $channel,
                    ]
                );
            }

            Log::info("{$survey->title} scheduled for specific groups.");

            Notification::make()
                ->title('Success!')
                ->body('Your survey has been scheduled.')
                ->success()
                ->send();

            $this->previewData = null;
        } else {
            // Manual dispatch - use firstOrCreate to prevent duplicates
            Log::info("{$survey->title} manual dispatch started for specific groups.");

            foreach ($selectedGroups as $groupId) {
                GroupSurvey::firstOrCreate(
                    [
                        'group_id'       => $groupId,
                        'survey_id'      => $survey->id,
                        'starts_at'      => now(), // Use current time as unique key for manual dispatch
                    ],
                    [
                        'automated'      => false,
                        'was_dispatched' => true,
                        'channel'        => $channel,
                    ]
                );
            }

            // Send to groups
            SendSurveyToGroupJob::dispatch(
                $selectedGroups,
                $survey,
                $channel,
                false,
                null,
                null,
                $validated['limit'] ?? null
            );

            Log::info("{$survey->title} dispatch job queued for selected groups.");

            Notification::make()
                ->title('Success!')
                ->body('Your survey is being sent now.')
                ->success()
                ->send();
        }

        $this->previewData = null;
        $this->form->fill();
    }
}
