<?php

namespace App\Filament\Widgets;

use App\Filament\Pages\CreditReports;
use App\Services\CreditUtilizationReportService;
use Filament\Widgets\ChartWidget;
use Filament\Widgets\Concerns\InteractsWithPageTable;

class CreditUtilizationTrendWidget extends ChartWidget
{
    use InteractsWithPageTable;

    protected static bool $isDiscovered = false;

    protected static ?string $heading = 'Credit movement over time';

    protected static ?string $description = 'Daily loaded and utilized credits for the active transaction filters.';

    protected static ?string $pollingInterval = '30s';

    protected static ?string $maxHeight = '320px';

    protected int|string|array $columnSpan = 'full';

    protected function getTablePage(): string
    {
        return CreditReports::class;
    }

    protected function getData(): array
    {
        $daily = app(CreditUtilizationReportService::class)
            ->dailyBreakdownFromQuery($this->getPageTableQuery());

        return [
            'datasets' => [
                [
                    'label' => 'Credits utilized',
                    'data' => $daily->pluck('credits_used')->all(),
                    'borderColor' => '#D59B3B',
                    'backgroundColor' => 'rgba(213, 155, 59, 0.16)',
                    'fill' => true,
                    'tension' => 0.32,
                ],
                [
                    'label' => 'Credits loaded',
                    'data' => $daily->pluck('credits_loaded')->all(),
                    'borderColor' => '#277A53',
                    'backgroundColor' => 'rgba(39, 122, 83, 0.08)',
                    'fill' => false,
                    'tension' => 0.32,
                ],
            ],
            'labels' => $daily->pluck('date')->all(),
        ];
    }

    protected function getType(): string
    {
        return 'line';
    }

    protected function getOptions(): array
    {
        return [
            'interaction' => ['intersect' => false, 'mode' => 'index'],
            'plugins' => ['legend' => ['position' => 'bottom']],
            'scales' => [
                'y' => ['beginAtZero' => true, 'ticks' => ['precision' => 0]],
                'x' => ['grid' => ['display' => false]],
            ],
        ];
    }
}
