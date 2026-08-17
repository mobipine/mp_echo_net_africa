<?php

namespace App\Exports\CreditUtilization;

use App\Exports\CreditUtilization\Concerns\StylesCreditReportSheet;
use App\Services\CreditUtilizationReportService;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithColumnFormatting;
use Maatwebsite\Excel\Concerns\WithColumnWidths;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithStrictNullComparison;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;

class CreditDailyTrendSheet implements FromArray, WithColumnFormatting, WithColumnWidths, WithEvents, WithStrictNullComparison, WithTitle
{
    use StylesCreditReportSheet;

    private array $rows;

    public function __construct(array $filters)
    {
        $daily = app(CreditUtilizationReportService::class)->dailyBreakdown($filters);
        $this->rows = [[
            'Date', 'Credits Loaded', 'Credits Used', 'Outbound SMS', 'Inbound SMS',
            'Net Movement', 'Transactions', 'Opening Balance', 'Closing Balance',
        ]];

        foreach ($daily as $row) {
            $this->rows[] = [
                Date::stringToExcel($row['date']),
                $row['credits_loaded'],
                $row['credits_used'],
                $row['credits_sent'],
                $row['credits_received'],
                $row['net_movement'],
                $row['transaction_count'],
                $row['opening_balance'],
                $row['closing_balance'],
            ];
        }
    }

    public function array(): array
    {
        return $this->rows;
    }

    public function title(): string
    {
        return 'Daily Trend';
    }

    public function columnFormats(): array
    {
        return [
            'A' => 'yyyy-mm-dd',
            'B' => NumberFormat::FORMAT_NUMBER_COMMA_SEPARATED1,
            'C' => NumberFormat::FORMAT_NUMBER_COMMA_SEPARATED1,
            'D' => NumberFormat::FORMAT_NUMBER_COMMA_SEPARATED1,
            'E' => NumberFormat::FORMAT_NUMBER_COMMA_SEPARATED1,
            'F' => NumberFormat::FORMAT_NUMBER_COMMA_SEPARATED1,
            'G' => NumberFormat::FORMAT_NUMBER_COMMA_SEPARATED1,
            'H' => NumberFormat::FORMAT_NUMBER_COMMA_SEPARATED1,
            'I' => NumberFormat::FORMAT_NUMBER_COMMA_SEPARATED1,
        ];
    }

    public function columnWidths(): array
    {
        return ['A' => 14, 'B' => 18, 'C' => 17, 'D' => 17, 'E' => 16, 'F' => 17, 'G' => 15, 'H' => 18, 'I' => 18];
    }

    public function registerEvents(): array
    {
        return [AfterSheet::class => fn (AfterSheet $event) => $this->styleTabularSheet(
            $event->sheet->getDelegate(),
            9,
            count($this->rows)
        )];
    }
}
