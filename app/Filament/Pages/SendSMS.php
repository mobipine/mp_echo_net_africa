<?php

namespace App\Filament\Pages;

use App\Models\Group;
use App\Models\SMSInbox;
use Filament\Forms;
use Filament\Pages\Page;
use Filament\Forms\Components\Grid;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;

class SendSMS extends Page implements Forms\Contracts\HasForms
{
    use Forms\Concerns\InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-chat-bubble-left-ellipsis';
    protected static string $view = 'filament.pages.send-sms';
    protected static ?string $navigationGroup = 'Messaging';
    protected static ?string $title = 'Send Bulk SMS';

    public $group_ids = [];
    public $message = '';

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
            Grid::make(1)->schema([
                Select::make('group_ids')
                    ->label('Select Groups')
                    ->options(Group::all()->pluck('name', 'id'))
                    ->multiple()
                    ->required()
                    ->searchable()
                    ->native(false),

                Textarea::make('message')
                    ->label('Message')
                    ->placeholder('Write your message here...')
                    ->required()
                    ->maxLength(500),
            ]),
        ];
    }

    protected function getFormModel(): string
    {
        return SMSInbox::class;
    }

    public function submit(): void
    {
        // dd($this->form->getState());
        // dd("gere");
        $validated = $this->form->getState();

        SMSInbox::create([
            'message' => $validated['message'],
            'group_ids' => $validated['group_ids'], // Ensure this is a casted field or adjust structure
        ]);

        Notification::make()
            ->title('Success!')
            ->body('Your SMS has been scheduled.')
            ->success()
            ->send();

        $this->form->fill(); // reset form
    }
}
