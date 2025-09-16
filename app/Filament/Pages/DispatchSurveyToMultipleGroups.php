<?php

namespace App\Filament\Pages;

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
                        ->options(Group::all()->pluck('name', 'id'))
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

                ]),
                Forms\Components\Group::make()->schema([
                    Forms\Components\DateTimePicker::make('starts_at')
                        ->label('Start Date')
                        ->native(false)
                        ->required(false)
                        ->hidden(fn ($get) => !$get('automated')),

                    Forms\Components\DateTimePicker::make('ends_at')
                        ->label('End Date')
                        ->native(false)
                        ->required(false)
                        ->hidden(fn ($get) => !$get('automated')),
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

        $survey = Survey::find($validated['survey_id']);
        
        if ($validated['automated']) {
            // Handle scheduling
            
            foreach ($validated['group_ids'] as $groupId) {
                
                GroupSurvey::create([
                    'group_id' => $groupId,
                    'survey_id' => $validated['survey_id'],
                    'automated' => true,
                    'starts_at' => $validated['starts_at'],
                    'ends_at' => $validated['ends_at'],
                ]);
            }
            Log::info("{$survey->title} to be sent to multiple groups has been scheduled in the Group Survey Table");

            Notification::make()
                ->title('Success!')
                ->body('Your survey has been scheduled.')
                ->success()
                ->send();

        } else {
            // Handle immediate sending
            Log::info("{$survey->title} is being dispatched manually to multiple groups");
            foreach ($validated['group_ids'] as $groupId) {
                GroupSurvey::create([
                    'group_id' => $groupId,
                    'survey_id' => $validated['survey_id'],
                    'automated' => false,
                    'was_dispatched' => true,
                    
                ]);
            }
            Log::info("The assigned survey to groups have been saved to the Group Survey Table");
            // Dispatch the refactored job with the array of group IDs
            SendSurveyToGroupJob::dispatch($validated['group_ids'], $survey);
            Log::info("{$survey->title} is being sent in the background to active members of the selected groups");

            Notification::make()
                ->title('Success!')
                ->body('Your survey is being sent now.')
                ->success()
                ->send();
        }

        $this->form->fill();
    }
}