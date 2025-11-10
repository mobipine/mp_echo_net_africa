<?php

namespace App\Filament\Pages;

use App\Models\Group;
use App\Models\NotificationTemplate;
use App\Models\SMSInbox;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Pages\Page;
use Filament\Notifications\Notification;

class SendSMS extends Page implements Forms\Contracts\HasForms
{
    use Forms\Concerns\InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-chat-bubble-left-ellipsis';
    protected static string $view = 'filament.pages.send-sms';
    protected static ?string $navigationGroup = 'Messaging';
    protected static ?string $title = 'Send Bulk SMS';

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

    // Define form() method explicitly — Filament relies on this for reactivity
    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Grid::make(1)->schema([
                    Forms\Components\Select::make('group_ids')
                        ->label('Select Groups')
                        ->options(Group::pluck('name', 'id'))
                        ->multiple()
                        ->required()
                        ->searchable()
                        ->native(false),

                    Forms\Components\Select::make('notification_template_id')
                        ->label('Select Notification Template (Optional)')
                        ->options(function () {
                            // Fetch only templates that exist
                            return \App\Models\NotificationTemplate::where('is_active', true)
                                ->get()
                                ->mapWithKeys(function ($template) {
                                    // Try to find a matching enum case
                                    $event = \App\Enums\NotificationEvent::tryFrom($template->slug);
                                    $label = $event?->getLabel() ?? ucfirst(str_replace('_', ' ', $template->slug));

                                    return [$template->id => $label];
                                });
                        })
                        ->reactive()
                        ->afterStateUpdated(function ($state, callable $set) {
                            if ($state) {
                                $template = \App\Models\NotificationTemplate::find($state);
                                $set('message', $template?->body_sms ?? '');
                            } else {
                                $set('message', '');
                            }
                        })
                        ->searchable()
                        ->placeholder('Select a notification template...'),

                    Forms\Components\Textarea::make('message')
                        ->label('Message')
                        ->placeholder('Write your message here...')
                        ->required()
                        ->maxLength(500)
                        ->reactive(),
                ]),
            ])
            ->statePath('data'); // important to bind form data properly
    }

    public function submit(): void
    {
        $validated = $this->form->getState();

        SMSInbox::create([
            'message' => $validated['message'],
            'group_ids' => $validated['group_ids'],
            "channel" => "sms"
        ]);

        Notification::make()
            ->title('Success!')
            ->body('Your SMS has been scheduled successfully.')
            ->success()
            ->send();

        $this->form->fill(); // reset form
    }
}
