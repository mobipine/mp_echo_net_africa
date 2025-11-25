<?php

namespace App\Filament\Widgets;

use App\Models\SmsCredit;
use App\Models\CreditTransaction;
use App\Filament\Pages\CreditReports;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class CreditStatsWidget extends BaseWidget
{
    protected static ?string $pollingInterval = '30s';
    protected static ?string $page = CreditReports::class;

    public static function canView(): bool
    {
        return false; // Only show on CreditReports page
    }

    protected function getStats(): array
    {
        $currentBalance = SmsCredit::getBalance();
        $totalAdded = CreditTransaction::where('type', 'add')->sum('amount');
        $totalUsed = CreditTransaction::where('type', 'subtract')->sum('amount');
        $smsSent = CreditTransaction::where('transaction_type', 'sms_sent')->sum('amount');
        $smsReceived = CreditTransaction::where('transaction_type', 'sms_received')->sum('amount');

        // Today's stats
        $todaySent = CreditTransaction::where('transaction_type', 'sms_sent')
            ->whereDate('created_at', today())
            ->sum('amount');
        $todayReceived = CreditTransaction::where('transaction_type', 'sms_received')
            ->whereDate('created_at', today())
            ->sum('amount');

        // Balance status
        $balanceStatus = $currentBalance > 1000 ? 'success' : ($currentBalance > 100 ? 'warning' : 'danger');
        $balanceDescription = $currentBalance > 1000 ? 'Healthy' : ($currentBalance > 100 ? 'Low' : 'Critical');

        return [
            Stat::make('Current Balance', number_format($currentBalance) . ' credits')
                ->description($balanceDescription)
                ->descriptionIcon('heroicon-o-banknotes')
                ->color($balanceStatus),

            Stat::make('Total Loaded', number_format($totalAdded) . ' credits')
                ->description('Lifetime credit additions')
                ->descriptionIcon('heroicon-o-arrow-up-circle')
                ->color('success'),

            Stat::make('Total Used', number_format($totalUsed) . ' credits')
                ->description('Lifetime credit subtractions')
                ->descriptionIcon('heroicon-o-arrow-down-circle')
                ->color('danger'),

            Stat::make('Today\'s Activity', number_format($todaySent + $todayReceived) . ' credits')
                ->description("Sent: {$todaySent} | Received: {$todayReceived}")
                ->descriptionIcon('heroicon-o-clock')
                ->color('info'),

            Stat::make('SMS Sent', number_format($smsSent) . ' credits')
                ->description('Total credits used for sending')
                ->descriptionIcon('heroicon-o-paper-airplane')
                ->color('warning'),

            Stat::make('SMS Received', number_format($smsReceived) . ' credits')
                ->description('Total credits used for receiving')
                ->descriptionIcon('heroicon-o-inbox')
                ->color('primary'),
        ];
    }
}

