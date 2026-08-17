<?php

namespace App\Exports\CreditUtilization;

use App\Exports\CreditUtilization\Concerns\StylesCreditReportSheet;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithColumnWidths;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Events\AfterSheet;

class CreditDataDictionarySheet implements FromArray, WithColumnWidths, WithEvents, WithTitle
{
    use StylesCreditReportSheet;

    private array $rows;

    public function __construct(array $filters)
    {
        $this->rows = [
            ['Field / concept', 'Definition', 'Reporting note'],
            ['Credit', 'One billable message segment. The application calculates one credit per 160 message characters.', 'A message may consume more than one credit.'],
            ['Credits loaded', 'Positive ledger entries created when credits are added.', 'Transaction type: load.'],
            ['Credits utilized', 'Credits deducted for successful outbound or processed inbound SMS activity.', 'Failed outbound sends do not consume credits.'],
            ['Net movement', 'Credits loaded minus credits utilized within the filtered selection.', 'This is not the same as the current balance when filters are active.'],
            ['Current balance', 'Live SMS credit balance at workbook generation time.', 'This value is intentionally not constrained by report filters.'],
            ['Opening / closing balance', 'Balance before the first and after the last transaction matching the report filters.', 'When filters omit intervening transactions, use the ledger for audit context.'],
            ['Survey attribution', 'Inbound usage uses the linked survey response. Outbound usage uses the SMS record linked to survey progress.', 'Generic SMS and historical rows without these links are reported as non-survey / unattributed.'],
            ['Member attribution', 'The member on the SMS record is preferred; otherwise the member matching the survey response phone is used.', 'Historical phone-number changes may prevent attribution.'],
            ['Group attribution', 'All groups currently linked to the attributed member are listed.', 'A member can belong to multiple groups. Credits are not duplicated in totals.'],
            ['County attribution', 'The county currently linked to the attributed member.', 'Historical changes are reflected using current member data.'],
            ['Reminder scope', 'Reminder filtering applies to outbound transactions with a directly linked SMS record.', 'Inbound responses are excluded when a reminder-only or standard-only scope is selected.'],
            ['Timezone', config('app.timezone'), 'All workbook timestamps use the application timezone.'],
            ['Generated at', now()->format('Y-m-d H:i:s T'), 'The export is a point-in-time report.'],
            ['Filter payload', json_encode($filters, JSON_UNESCAPED_SLASHES), 'Normalized filters used by every worksheet in this workbook.'],
        ];
    }

    public function array(): array
    {
        return $this->rows;
    }

    public function title(): string
    {
        return 'Definitions';
    }

    public function columnWidths(): array
    {
        return ['A' => 26, 'B' => 72, 'C' => 62];
    }

    public function registerEvents(): array
    {
        return [AfterSheet::class => function (AfterSheet $event) {
            $sheet = $event->sheet->getDelegate();
            $this->styleTabularSheet($sheet, 3, count($this->rows));
            $sheet->getStyle('A1:C'.count($this->rows))->getAlignment()->setWrapText(true);
            foreach (range(2, count($this->rows)) as $row) {
                $sheet->getRowDimension($row)->setRowHeight(44);
            }
        }];
    }
}
