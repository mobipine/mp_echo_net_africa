<?php

namespace App\Filament\Pages;

use App\Models\SmsCredit;
use App\Filament\Widgets\CreditBalanceWidget;
use Filament\Forms;
use Filament\Pages\Page;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Auth;
use Filament\Forms\Form;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Actions\Action;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;

class CreditManagement extends Page implements HasForms, HasActions
{
    use InteractsWithForms;
    use InteractsWithActions;

    protected static ?string $navigationIcon = 'heroicon-o-currency-dollar';
    protected static string $view = 'filament.pages.credit-management';
    protected static ?string $navigationGroup = 'SMS & Credits';
    protected static ?string $title = 'Load SMS Credits';
    protected static ?int $navigationSort = 1;

    public ?array $data = [];

    public function mount(): void
    {
        $this->form->fill();
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Add SMS Credits')
                    ->description('Load new credits into the system. 1 credit = 160 characters per SMS.')
                    ->schema([
                        Forms\Components\TextInput::make('credits_to_add')
                            ->label('Credits to Add')
                            ->numeric()
                            ->required()
                            ->minValue(1)
                            ->helperText('Enter the number of credits you want to add'),

                        Forms\Components\Textarea::make('description')
                            ->label('Description (Optional)')
                            ->maxLength(500)
                            ->rows(3)
                            ->helperText('e.g., "Loaded via Mpesa Ref: ABC123" or "Monthly top-up"'),
                    ])
                    ->columns(1),
            ])
            ->statePath('data');
    }

    protected function getHeaderWidgets(): array
    {
        return [
            CreditBalanceWidget::class,
        ];
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('loadCredits')
                ->label('Load Credits')
                ->color('success')
                ->icon('heroicon-o-plus-circle')
                ->size('lg')
                ->requiresConfirmation()
                ->modalHeading('Confirm Credit Loading')
                ->modalDescription(function () {
                    $credits = $this->data['credits_to_add'] ?? 0;
                    return "Are you sure you want to add " . number_format($credits) . " credits?";
                })
                ->modalSubmitActionLabel('Yes, Load Credits')
                ->action(function () {
                    $this->loadCredits();
                }),
        ];
    }

    public function loadCredits(): void
    {
        try {
            $data = $this->form->getState();

            SmsCredit::addCredits(
                (int) $data['credits_to_add'],
                $data['description'] ?: "Credits loaded by " . Auth::user()->name,
                Auth::id()
            );

            $newBalance = SmsCredit::getBalance();

            Notification::make()
                ->title('Credits Loaded Successfully!')
                ->body("{$data['credits_to_add']} credits have been added. New balance: " . number_format($newBalance))
                ->success()
                ->send();

            // Reset form
            $this->form->fill();

            // Refresh the page to update widget
            $this->dispatch('$refresh');
        } catch (\Exception $e) {
            Notification::make()
                ->title('Error Loading Credits')
                ->body($e->getMessage())
                ->danger()
                ->send();
        }
    }
}

