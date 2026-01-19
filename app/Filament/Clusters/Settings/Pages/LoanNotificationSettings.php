<?php

namespace App\Filament\Clusters\Settings\Pages;

use App\Filament\Clusters\Settings;
use App\Models\Setting;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Log;

class LoanNotificationSettings extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-envelope';

    protected static string $view = 'filament.clusters.settings.pages.loan-notification-settings';

    protected static ?string $cluster = Settings::class;

    protected static ?string $title = 'Loan Notifications';

    protected static ?string $navigationLabel = 'Loan Notifications';

    protected static ?int $navigationSort = 2;

    public ?array $data = [];

    public function mount(): void
    {
        $this->form->fill([
            'enabled' => Setting::get('loan_notifications.enabled', true),
            'admin_email' => Setting::get('loan_notifications.admin_email', ''),
            'member_notifications' => Setting::get('loan_notifications.member_notifications', true),
            'admin_notifications' => Setting::get('loan_notifications.admin_notifications', true),
            'email_enabled' => Setting::get('loan_repayment.email_enabled', true),
            'sms_enabled' => Setting::get('loan_repayment.sms_enabled', false),
            'queue' => Setting::get('loan_notifications.queue', 'emails'),
        ]);
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('General Settings')
                    ->description('Master controls for loan notification system')
                    ->schema([
                        Forms\Components\Toggle::make('enabled')
                            ->label('Enable Loan Notifications')
                            ->helperText('Master switch for all loan-related notifications')
                            ->columnSpanFull(),

                        Forms\Components\Grid::make(2)
                            ->schema([
                                Forms\Components\Toggle::make('email_enabled')
                                    ->label('Email Notifications')
                                    ->helperText('Enable email notifications for loan repayments'),

                                Forms\Components\Toggle::make('sms_enabled')
                                    ->label('SMS Notifications')
                                    ->helperText('Enable SMS notifications (future feature)')
                                    ->disabled()
                                    ->dehydrated(), // This ensures the field is included in form data even when disabled
                            ]),
                    ]),

                Forms\Components\Section::make('Email Configuration')
                    ->description('Configure email notification recipients')
                    ->schema([
                        Forms\Components\TextInput::make('admin_email')
                            ->label('Admin Email Address')
                            ->email()
                            ->required()
                            ->helperText('Email address to receive admin notifications for loan repayments')
                            ->placeholder('admin@example.com')
                            ->suffixIcon('heroicon-m-envelope')
                            ->columnSpanFull(),

                        Forms\Components\Grid::make(2)
                            ->schema([
                                Forms\Components\Toggle::make('member_notifications')
                                    ->label('Member Notifications')
                                    ->helperText('Send notifications to loan members'),

                                Forms\Components\Toggle::make('admin_notifications')
                                    ->label('Admin Notifications')
                                    ->helperText('Send notifications to administrators'),
                            ]),
                    ]),

                Forms\Components\Section::make('Advanced Settings')
                    ->description('Technical configuration options')
                    ->collapsed()
                    ->schema([
                        Forms\Components\TextInput::make('queue')
                            ->label('Notification Queue')
                            ->helperText('Queue name for processing notification jobs')
                            ->default('emails')
                            ->columnSpanFull(),
                    ]),

                Forms\Components\Section::make('Current Status')
                    ->description('Overview of current notification settings')
                    ->schema([
                        Forms\Components\Placeholder::make('status_overview')
                            ->label('Configuration Summary')
                            ->content(function () {
                                $enabled = Setting::get('loan_notifications.enabled', true);
                                $adminEmail = Setting::get('loan_notifications.admin_email', 'Not configured');
                                $memberNotifications = Setting::get('loan_notifications.member_notifications', true);
                                $adminNotifications = Setting::get('loan_notifications.admin_notifications', true);
                                $emailEnabled = Setting::get('loan_repayment.email_enabled', true);

                                $status = [
                                    'System Status' => $enabled ? '✅ Enabled' : '❌ Disabled',
                                    'Email Notifications' => $emailEnabled ? '✅ Enabled' : '❌ Disabled',
                                    'Admin Email' => $adminEmail,
                                    'Member Notifications' => $memberNotifications ? '✅ Enabled' : '❌ Disabled',
                                    'Admin Notifications' => $adminNotifications ? '✅ Enabled' : '❌ Disabled',
                                ];

                                return collect($status)
                                    ->map(fn($value, $key) => "{$key}: {$value}")
                                    ->implode("\n");
                            })
                            ->columnSpanFull(),
                    ]),
            ])
            ->statePath('data');
    }

    public function save(): void
    {
        $data = $this->form->getState();

        try {
            // Update all settings with safe array access
            Setting::set('loan_notifications.enabled', $data['enabled'] ?? true, 'boolean', 'loan_notifications', 'Enable Loan Notifications', 'Master switch for all loan-related email notifications');
            Setting::set('loan_notifications.admin_email', $data['admin_email'] ?? '', 'string', 'loan_notifications', 'Admin Email Address', 'Email address to receive admin notifications for loan repayments');
            Setting::set('loan_notifications.member_notifications', $data['member_notifications'] ?? true, 'boolean', 'loan_notifications', 'Member Notifications', 'Send email notifications to loan members when repayments are recorded');
            Setting::set('loan_notifications.admin_notifications', $data['admin_notifications'] ?? true, 'boolean', 'loan_notifications', 'Admin Notifications', 'Send email notifications to administrators when repayments are recorded');
            Setting::set('loan_repayment.email_enabled', $data['email_enabled'] ?? true, 'boolean', 'loan_notifications', 'Email Notifications for Repayments', 'Enable email notifications specifically for loan repayments');
            Setting::set('loan_repayment.sms_enabled', $data['sms_enabled'] ?? false, 'boolean', 'loan_notifications', 'SMS Notifications for Repayments', 'Enable SMS notifications for loan repayments (future feature)');
            Setting::set('loan_notifications.queue', $data['queue'] ?? 'emails', 'string', 'loan_notifications', 'Notification Queue', 'Queue name for processing loan notification jobs');

            Log::info('Loan notification settings updated', [
                'admin_email' => $data['admin_email'] ?? 'not_provided',
                'enabled' => $data['enabled'] ?? false,
                'updated_by' => auth()->check() ? auth()->user()->id : 'system',
            ]);

            Notification::make()
                ->success()
                ->title('Settings Updated')
                ->body('Loan notification settings have been saved successfully.')
                ->send();

        } catch (\Exception $e) {
            Log::error('Failed to update loan notification settings', [
                'error' => $e->getMessage(),
                'data' => $data,
                'trace' => $e->getTraceAsString(),
            ]);

            Notification::make()
                ->danger()
                ->title('Update Failed')
                ->body('There was an error saving the settings. Please try again.')
                ->send();
        }
    }

    public function testEmail(): void
    {
        $data = $this->form->getState();
        $adminEmail = $data['admin_email'] ?? Setting::get('loan_notifications.admin_email');
        
        if (!$adminEmail || $adminEmail === 'admin@example.com') {
            Notification::make()
                ->warning()
                ->title('No Admin Email')
                ->body('Please configure an admin email address first.')
                ->send();
            return;
        }

        try {
            \Illuminate\Support\Facades\Mail::raw(
                'This is a test email from your loan notification system. If you received this, your email configuration is working correctly.',
                function ($message) use ($adminEmail) {
                    $message->to($adminEmail)
                            ->subject('Test Email - Loan Notification System');
                }
            );

            Notification::make()
                ->success()
                ->title('Test Email Sent')
                ->body("Test email sent successfully to {$adminEmail}")
                ->send();

            Log::info('Test email sent successfully', ['admin_email' => $adminEmail]);

        } catch (\Exception $e) {
            Notification::make()
                ->danger()
                ->title('Test Email Failed')
                ->body('Failed to send test email: ' . $e->getMessage())
                ->send();

            Log::error('Test email failed', [
                'admin_email' => $adminEmail,
                'error' => $e->getMessage(),
            ]);
        }
    }
}