<?php

namespace App\Exports\CreditUtilization;

use App\Models\County;
use App\Models\Group;
use App\Models\Survey;
use App\Services\CreditUtilizationReportService;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithColumnWidths;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithStrictNullComparison;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;

class CreditUtilizationSummarySheet implements FromArray, WithColumnWidths, WithEvents, WithStrictNullComparison, WithTitle
{
    private array $summary;

    private array $rows;

    public function __construct(
        private readonly array $filters,
        private readonly string $requestedBy
    ) {
        $service = app(CreditUtilizationReportService::class);
        $this->summary = $service->summary($filters);
        $daily = $service->dailyBreakdown($filters);
        $peakDay = $daily->sortByDesc('credits_used')->first();

        $this->rows = [
            ['ECHO NET AFRICA | CREDIT UTILIZATION REPORT'],
            ['A decision-ready view of credit loading, usage, survey attribution and balance movement'],
            ['Generated', now()->format('Y-m-d H:i:s T')],
            ['Requested by', $requestedBy],
            [null, null, null],
            ['EXECUTIVE SUMMARY'],
            ['Metric', 'Value', 'What it means'],
            ['Current credit balance', $this->summary['current_balance'], 'Live balance when this workbook was generated'],
            ['Opening balance in selection', $this->summary['opening_balance'], 'Balance immediately before the first matching transaction'],
            ['Closing balance in selection', $this->summary['closing_balance'], 'Balance immediately after the last matching transaction'],
            ['Credits loaded', $this->summary['credits_loaded'], 'Credits added within the selected scope'],
            ['Credits utilized', $this->summary['credits_used'], 'All credits deducted within the selected scope'],
            ['Net balance movement', $this->summary['net_movement'], 'Credits loaded less credits utilized'],
            ['Transactions', $this->summary['transaction_count'], 'Number of matching credit ledger entries'],
            ['Average daily utilization', $this->summary['average_daily_usage'], 'Credits used per day with recorded activity'],
            ['Utilization / load ratio', $this->summary['utilization_to_load_ratio'], 'Credits utilized divided by credits loaded in this selection'],
            ['Peak utilization day', $peakDay['date'] ?? 'No activity', $peakDay ? number_format($peakDay['credits_used']).' credits used' : 'No matching usage'],
            [null, null, null],
            ['UTILIZATION MIX'],
            ['Metric', 'Credits', 'Share of utilized credits'],
            ['Outbound SMS', $this->summary['credits_sent'], $this->share($this->summary['credits_sent'])],
            ['Inbound SMS', $this->summary['credits_received'], $this->share($this->summary['credits_received'])],
            ['Survey-attributed usage', $this->summary['survey_attributed'], $this->share($this->summary['survey_attributed'])],
            ['Non-survey / unattributed usage', $this->summary['non_survey_usage'], $this->share($this->summary['non_survey_usage'])],
            [null, null, null],
            ['REPORT FILTERS'],
            ['Filter', 'Selection'],
            ['Date range', $this->dateRangeLabel()],
            ['Surveys', $this->modelLabels(Survey::class, 'title', $filters['survey_ids'])],
            ['Groups', $this->modelLabels(Group::class, 'name', $filters['group_ids'])],
            ['Counties', $this->modelLabels(County::class, 'name', $filters['county_ids'])],
            ['Direction', $this->optionLabels($filters['directions'], ['add' => 'Credits added', 'subtract' => 'Credits utilized'])],
            ['Transaction type', $this->optionLabels($filters['transaction_types'], ['load' => 'Credit loads', 'sms_sent' => 'Outbound SMS', 'sms_received' => 'Inbound SMS'])],
            ['Channel', $filters['channels'] ? implode(', ', array_map('strtoupper', $filters['channels'])) : 'All channels'],
            ['Outbound message scope', match ($filters['message_scope']) {
                'reminders' => 'Reminder messages only',
                'standard' => 'Standard messages only',
                default => 'All messages',
            }],
        ];
    }

    public function array(): array
    {
        return $this->rows;
    }

    public function title(): string
    {
        return 'Executive Summary';
    }

    public function columnWidths(): array
    {
        return ['A' => 34, 'B' => 30, 'C' => 58, 'D' => 3, 'E' => 3, 'F' => 3];
    }

    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $event) {
                $sheet = $event->sheet->getDelegate();
                $sheet->setShowGridlines(false);
                $sheet->mergeCells('A1:F1');
                $sheet->mergeCells('A2:F2');
                $sheet->getRowDimension(1)->setRowHeight(34);
                $sheet->getRowDimension(2)->setRowHeight(24);

                $sheet->getStyle('A1:F1')->applyFromArray([
                    'font' => ['bold' => true, 'size' => 18, 'color' => ['rgb' => 'FFFFFF']],
                    'fill' => ['fillType' => 'solid', 'startColor' => ['rgb' => '163A2B']],
                    'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
                ]);
                $sheet->getStyle('A2:F2')->applyFromArray([
                    'font' => ['italic' => true, 'size' => 10, 'color' => ['rgb' => 'DDE9E2']],
                    'fill' => ['fillType' => 'solid', 'startColor' => ['rgb' => '163A2B']],
                ]);

                foreach ([6, 19, 26] as $row) {
                    $sheet->mergeCells("A{$row}:F{$row}");
                    $sheet->getRowDimension($row)->setRowHeight(24);
                    $sheet->getStyle("A{$row}:F{$row}")->applyFromArray([
                        'font' => ['bold' => true, 'color' => ['rgb' => '163A2B']],
                        'fill' => ['fillType' => 'solid', 'startColor' => ['rgb' => 'E6EFE9']],
                        'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
                        'borders' => ['bottom' => ['borderStyle' => Border::BORDER_MEDIUM, 'color' => ['rgb' => 'D59B3B']]],
                    ]);
                }

                foreach ([7, 20, 27] as $row) {
                    $sheet->getStyle("A{$row}:C{$row}")->applyFromArray([
                        'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
                        'fill' => ['fillType' => 'solid', 'startColor' => ['rgb' => '3C6653']],
                    ]);
                }

                $sheet->getStyle('A8:C17')->getBorders()->getBottom()
                    ->setBorderStyle(Border::BORDER_HAIR)->getColor()->setRGB('DDE5E0');
                $sheet->getStyle('A21:C24')->getBorders()->getBottom()
                    ->setBorderStyle(Border::BORDER_HAIR)->getColor()->setRGB('DDE5E0');
                $sheet->getStyle('A28:B35')->getBorders()->getBottom()
                    ->setBorderStyle(Border::BORDER_HAIR)->getColor()->setRGB('DDE5E0');
                $sheet->getStyle('A1:F35')->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);
                $sheet->getStyle('C8:C24')->getAlignment()->setWrapText(true);
                $sheet->getStyle('B8:B16')->getNumberFormat()->setFormatCode('#,##0.00');
                $sheet->getStyle('B16')->getNumberFormat()->setFormatCode('0.0%');
                $sheet->getStyle('B21:B24')->getNumberFormat()->setFormatCode('#,##0');
                $sheet->getStyle('C21:C24')->getNumberFormat()->setFormatCode('0.0%');
                $sheet->freezePane('A7');
                $sheet->getPageSetup()->setOrientation(PageSetup::ORIENTATION_LANDSCAPE);
                $sheet->getPageSetup()->setFitToWidth(1)->setFitToHeight(0);
            },
        ];
    }

    private function share(int $value): float
    {
        return $this->summary['credits_used'] > 0 ? $value / $this->summary['credits_used'] : 0;
    }

    private function dateRangeLabel(): string
    {
        return match (true) {
            filled($this->filters['date_from']) && filled($this->filters['date_to']) => "{$this->filters['date_from']} to {$this->filters['date_to']}",
            filled($this->filters['date_from']) => "From {$this->filters['date_from']}",
            filled($this->filters['date_to']) => "Through {$this->filters['date_to']}",
            default => 'All available dates',
        };
    }

    private function modelLabels(string $model, string $column, array $ids): string
    {
        if (! $ids) {
            return 'All';
        }

        $labels = $model::query()->whereIn('id', $ids)->orderBy($column)->pluck($column)->all();

        return $labels ? implode(', ', $labels) : 'No matching records';
    }

    private function optionLabels(array $values, array $labels): string
    {
        return $values ? implode(', ', array_map(fn ($value) => $labels[$value] ?? $value, $values)) : 'All';
    }
}
