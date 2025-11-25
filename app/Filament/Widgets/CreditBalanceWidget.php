<?php

namespace App\Filament\Widgets;

use App\Models\SmsCredit;
use App\Filament\Pages\CreditManagement;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class CreditBalanceWidget extends BaseWidget
{
    protected static ?string $pollingInterval = '10s';
    protected static ?string $page = CreditManagement::class;

    public static function canView(): bool
    {
        return false; // Only show on CreditManagement page
    }

    protected function getStats(): array
    {
        $currentBalance = SmsCredit::getBalance();

        $status = match(true) {
            $currentBalance > 1000 => 'success',
            $currentBalance > 500 => 'primary',
            $currentBalance > 100 => 'warning',
            default => 'danger'
        };

        $statusText = match(true) {
            $currentBalance > 1000 => 'Healthy Balance',
            $currentBalance > 500 => 'Good Balance',
            $currentBalance > 100 => 'Low Balance - Consider Loading',
            default => 'Critical - Load Credits Now!'
        };

        return [
            Stat::make('Current Credit Balance', number_format($currentBalance))
                ->description($statusText)
                ->descriptionIcon('heroicon-o-banknotes')
                ->color($status)
                ->chart([])
                ->extraAttributes([
                    'class' => 'text-left',
                ]),
        ];
    }
}

