<?php

namespace App\Filament\Widgets;

use App\Models\Setting;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class LoanNotificationOverview extends BaseWidget
{
    protected static ?int $sort = 3;

    protected static bool $isLazy = false;

    protected function getStats(): array
    {
        $enabled = Setting::get('loan_notifications.enabled', true);
        $adminEmail = Setting::get('loan_notifications.admin_email', 'Not configured');
        $memberNotifications = Setting::get('loan_notifications.member_notifications', true);
        $adminNotifications = Setting::get('loan_notifications.admin_notifications', true);

        return [
            Stat::make('Notification System', $enabled ? 'Active' : 'Disabled')
                ->description($enabled ? 'Loan notifications are enabled' : 'Loan notifications are disabled')
                ->descriptionIcon($enabled ? 'heroicon-m-check-circle' : 'heroicon-m-x-circle')
                ->color($enabled ? 'success' : 'danger'),

            Stat::make('Admin Email', $adminEmail !== 'Not configured' ? 'Configured' : 'Not Set')
                ->description($adminEmail !== 'Not configured' ? $adminEmail : 'Admin email not configured')
                ->descriptionIcon($adminEmail !== 'Not configured' ? 'heroicon-m-envelope' : 'heroicon-m-exclamation-triangle')
                ->color($adminEmail !== 'Not configured' ? 'success' : 'warning'),

            Stat::make('Active Recipients', $this->getActiveRecipientsCount())
                ->description('Member and admin notifications')
                ->descriptionIcon('heroicon-m-users')
                ->color('info'),
        ];
    }

    private function getActiveRecipientsCount(): string
    {
        $memberNotifications = Setting::get('loan_notifications.member_notifications', true);
        $adminNotifications = Setting::get('loan_notifications.admin_notifications', true);

        $active = [];
        if ($memberNotifications) $active[] = 'Members';
        if ($adminNotifications) $active[] = 'Admin';

        return empty($active) ? 'None' : implode(' + ', $active);
    }
}
