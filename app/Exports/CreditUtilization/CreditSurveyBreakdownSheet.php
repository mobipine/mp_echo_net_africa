<?php

namespace App\Exports\CreditUtilization;

use App\Exports\CreditUtilization\Concerns\StylesCreditReportSheet;
use App\Services\CreditUtilizationReportService;
use Carbon\Carbon;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithColumnFormatting;
use Maatwebsite\Excel\Concerns\WithColumnWidths;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithStrictNullComparison;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;

class CreditSurveyBreakdownSheet implements FromArray, WithColumnFormatting, WithColumnWidths, WithEvents, WithStrictNullComparison, WithTitle
{
    use StylesCreditReportSheet;

    private array $rows;

    public function __construct(array $filters)
    {
        $breakdown = app(CreditUtilizationReportService::class)->surveyBreakdown($filters);
        $this->rows = [[
            'Survey ID', 'Survey', 'Credits Used', 'Outbound SMS', 'Inbound SMS',
            'Transactions', 'Average Credits', 'Share of Usage', 'First Activity', 'Last Activity',
        ]];

        foreach ($breakdown as $row) {
            $this->rows[] = [
                $row['survey_id'],
                $row['survey_title'],
                $row['credits_used'],
                $row['credits_sent'],
                $row['credits_received'],
                $row['transaction_count'],
                $row['average_credits'],
                $row['share_of_usage'],
                $this->excelDate($row['first_activity_at']),
                $this->excelDate($row['last_activity_at']),
            ];
        }
    }

    public function array(): array
    {
        return $this->rows;
    }

    public function title(): string
    {
        return 'Survey Utilization';
    }

    public function columnFormats(): array
    {
        return [
            'A' => '0',
            'C' => NumberFormat::FORMAT_NUMBER_COMMA_SEPARATED1,
            'D' => NumberFormat::FORMAT_NUMBER_COMMA_SEPARATED1,
            'E' => NumberFormat::FORMAT_NUMBER_COMMA_SEPARATED1,
            'F' => NumberFormat::FORMAT_NUMBER_COMMA_SEPARATED1,
            'G' => '0.00',
            'H' => '0.0%',
            'I' => 'yyyy-mm-dd hh:mm',
            'J' => 'yyyy-mm-dd hh:mm',
        ];
    }

    public function columnWidths(): array
    {
        return ['A' => 12, 'B' => 38, 'C' => 17, 'D' => 17, 'E' => 16, 'F' => 15, 'G' => 17, 'H' => 17, 'I' => 21, 'J' => 21];
    }

    public function registerEvents(): array
    {
        return [AfterSheet::class => fn (AfterSheet $event) => $this->styleTabularSheet(
            $event->sheet->getDelegate(),
            10,
            count($this->rows)
        )];
    }

    private function excelDate(mixed $value): ?float
    {
        return $value ? Date::dateTimeToExcel(Carbon::parse($value)) : null;
    }
}
