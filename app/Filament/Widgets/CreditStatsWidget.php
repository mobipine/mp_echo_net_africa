<?php

namespace App\Filament\Widgets;

use App\Filament\Pages\CreditReports;
use App\Services\CreditUtilizationReportService;
use Filament\Widgets\Concerns\InteractsWithPageTable;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class CreditStatsWidget extends BaseWidget
{
    use InteractsWithPageTable;

    protected static bool $isDiscovered = false;

    protected static ?string $pollingInterval = '30s';

    protected int|string|array $columnSpan = 'full';

    protected function getTablePage(): string
    {
        return CreditReports::class;
    }

    protected function getStats(): array
    {
        $summary = app(CreditUtilizationReportService::class)
            ->summaryFromQuery($this->getPageTableQuery());
        $balanceStatus = $summary['current_balance'] > 1000
            ? 'success'
            : ($summary['current_balance'] > 100 ? 'warning' : 'danger');

        return [
            Stat::make('Current balance', number_format($summary['current_balance']).' credits')
                ->description('Live, independent of filters')
                ->descriptionIcon('heroicon-o-banknotes')
                ->color($balanceStatus),

            Stat::make('Filtered utilization', number_format($summary['credits_used']).' credits')
                ->description(number_format($summary['transaction_count']).' matching transactions')
                ->descriptionIcon('heroicon-o-arrow-trending-down')
                ->color('danger'),

            Stat::make('Filtered loads', number_format($summary['credits_loaded']).' credits')
                ->description('Credits added in this selection')
                ->descriptionIcon('heroicon-o-arrow-trending-up')
                ->color('success'),

            Stat::make('Net movement', ($summary['net_movement'] >= 0 ? '+' : '').number_format($summary['net_movement']))
                ->description('Loaded less utilized')
                ->descriptionIcon('heroicon-o-arrows-right-left')
                ->color($summary['net_movement'] >= 0 ? 'success' : 'warning'),

            Stat::make('Outbound usage', number_format($summary['credits_sent']).' credits')
                ->description('Successful SMS sends')
                ->descriptionIcon('heroicon-o-paper-airplane')
                ->color('warning'),

            Stat::make('Inbound usage', number_format($summary['credits_received']).' credits')
                ->description('Processed SMS responses')
                ->descriptionIcon('heroicon-o-inbox-arrow-down')
                ->color('info'),
        ];
    }
}
