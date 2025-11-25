<?php

namespace App\Filament\Pages;

use App\Enums\ChannelType;
use App\Jobs\SendSurveyToGroupJob;
use App\Models\Group;
use App\Models\SMSInbox;
use App\Models\Survey;
use App\Models\GroupSurvey;
use Filament\Forms;
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


    public $group_ids = [];
    public $survey_id = null;
    public $automated = false;
    public $starts_at = null;
    public $ends_at = null;
    public $channel = null;


    public ?array $data = [];

    public static function shouldRegisterNavigation(): bool
    {
        return auth()->user()?->can('page_SendSMS') ?? false;
    }

    public function mount(): void
    {
        abort_unless(static::shouldRegisterNavigation(), 403);
        $this->form->fill();
    }

    protected function getFormSchema(): array
    {
        return [
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
            ])
        ];
    }

    protected function getFormModel(): string
    {
        return SMSInbox::class;
    }

    // The previous getForm() method is removed
    // The state path is handled implicitly by the trait and the public $data property

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
                $validated['ends_at'] ?? null
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
            SendSurveyToGroupJob::dispatch($selectedGroups, $survey, $channel);

            Log::info("{$survey->title} dispatch job queued for selected groups.");

            Notification::make()
                ->title('Success!')
                ->body('Your survey is being sent now.')
                ->success()
                ->send();
        }

        $this->form->fill();
    }
}
