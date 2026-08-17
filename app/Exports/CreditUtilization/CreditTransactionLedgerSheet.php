<?php

namespace App\Exports\CreditUtilization;

use App\Exports\CreditUtilization\Concerns\StylesCreditReportSheet;
use App\Models\CreditTransaction;
use App\Services\CreditUtilizationReportService;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\WithChunkReading;
use Maatwebsite\Excel\Concerns\WithColumnFormatting;
use Maatwebsite\Excel\Concerns\WithColumnWidths;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStrictNullComparison;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;

class CreditTransactionLedgerSheet implements FromQuery, WithChunkReading, WithColumnFormatting, WithColumnWidths, WithEvents, WithHeadings, WithMapping, WithStrictNullComparison, WithTitle
{
    use StylesCreditReportSheet;

    public function __construct(private readonly array $filters) {}

    public function query()
    {
        return app(CreditUtilizationReportService::class)
            ->query($this->filters)
            ->orderBy('credit_transactions.created_at')
            ->orderBy('credit_transactions.id');
    }

    public function headings(): array
    {
        return [
            'Timestamp', 'Transaction ID', 'Direction', 'Transaction Type', 'Signed Credits',
            'Credits', 'Balance Before', 'Balance After', 'Survey ID', 'Survey',
            'Member ID', 'Member', 'Phone', 'Groups', 'County', 'Channel', 'Message Scope',
            'Survey Question', 'SMS Status', 'Delivery Status', 'Description', 'Recorded By',
            'SMS Inbox ID', 'Survey Response ID',
        ];
    }

    public function map($row): array
    {
        /** @var CreditTransaction $row */
        $survey = $row->attributedSurvey();
        $member = $row->attributedMember();
        $inbox = $row->attributedInbox();

        return [
            Date::dateTimeToExcel($row->created_at),
            $row->id,
            $row->type === 'add' ? 'Credit added' : 'Credit utilized',
            match ($row->transaction_type) {
                'load' => 'Credit load',
                'sms_sent' => 'Outbound SMS',
                'sms_received' => 'Inbound SMS',
                default => $row->transaction_type,
            },
            $row->type === 'add' ? (int) $row->amount : -(int) $row->amount,
            (int) $row->amount,
            (int) $row->balance_before,
            (int) $row->balance_after,
            $survey?->id,
            $survey?->title,
            $member?->id,
            $member?->name,
            $member?->phone ?? $inbox?->phone_number ?? $row->surveyResponse?->msisdn,
            $member?->groups?->pluck('name')->join(', '),
            $member?->county?->name,
            $inbox?->channel ? strtoupper($inbox->channel) : null,
            $inbox ? ($inbox->is_reminder ? 'Reminder' : 'Standard') : null,
            $row->surveyResponse?->question?->question,
            $inbox?->status,
            $inbox?->delivery_status_desc ?? $inbox?->delivery_status,
            $row->description,
            $row->user?->name ?? 'System',
            $row->sms_inbox_id,
            $row->survey_response_id,
        ];
    }

    public function chunkSize(): int
    {
        return 2000;
    }

    public function title(): string
    {
        return 'Transaction Ledger';
    }

    public function columnFormats(): array
    {
        return [
            'A' => 'yyyy-mm-dd hh:mm:ss',
            'B' => '0',
            'E' => NumberFormat::FORMAT_NUMBER_COMMA_SEPARATED1,
            'F' => NumberFormat::FORMAT_NUMBER_COMMA_SEPARATED1,
            'G' => NumberFormat::FORMAT_NUMBER_COMMA_SEPARATED1,
            'H' => NumberFormat::FORMAT_NUMBER_COMMA_SEPARATED1,
            'I' => '0',
            'K' => '0',
            'W' => '0',
            'X' => '0',
        ];
    }

    public function columnWidths(): array
    {
        return [
            'A' => 22, 'B' => 15, 'C' => 18, 'D' => 18, 'E' => 16, 'F' => 12,
            'G' => 17, 'H' => 16, 'I' => 12, 'J' => 34, 'K' => 12, 'L' => 27,
            'M' => 18, 'N' => 32, 'O' => 20, 'P' => 12, 'Q' => 16, 'R' => 44,
            'S' => 16, 'T' => 22, 'U' => 48, 'V' => 24, 'W' => 15, 'X' => 20,
        ];
    }

    public function registerEvents(): array
    {
        return [AfterSheet::class => function (AfterSheet $event) {
            $sheet = $event->sheet->getDelegate();
            $this->styleTabularSheet($sheet, 24, max(1, $sheet->getHighestRow()));
            $sheet->freezePane('E2');
            $sheet->getStyle("N2:N{$sheet->getHighestRow()}")->getAlignment()->setWrapText(true);
            $sheet->getStyle("R2:U{$sheet->getHighestRow()}")->getAlignment()->setWrapText(true);
            $sheet->getStyle("E2:H{$sheet->getHighestRow()}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
        }];
    }
}
