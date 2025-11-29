<?php

namespace App\Exports;

use App\Models\CreditTransaction;
use App\Models\SmsCredit;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Events\AfterSheet;
use Illuminate\Support\Facades\DB;

class CreditsReportExport implements FromCollection, ShouldAutoSize, WithEvents
{
    protected array $filters;

    public function __construct(array $filters = [])
    {
        $this->filters = $filters;
    }

    public function collection()
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

        $rows = collect();

        // --- Section 1: Main Stats ---
        $rows->push(['DESCRIPTION', 'CREDITS']); // Title row
        $rows->push(['SMS sent credits', $smsSent !=null ? $smsSent : '0' ]);
        $rows->push(['SMS received credits', $smsReceived != null ? $smsReceived : '0']);
        $rows->push(['Credits Balance', $currentBalance != null ? $currentBalance : '0']);
        $rows->push(['Total Credits loaded', $totalAdded !=null ? $totalAdded : '0']);
        $rows->push(['Total Credits used', $totalUsed !=null ? $totalUsed : '0']);

        return new Collection($rows);
    }

    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $event) {
                $sheet = $event->sheet->getDelegate();
                $highestRow = $sheet->getHighestRow();

                // Bold titles dynamically
                for ($row = 1; $row <= $highestRow; $row++) {
                    $cellValue = $sheet->getCell("A{$row}")->getValue();
                    if ($cellValue && strtoupper($cellValue) === $cellValue) {
                        $sheet->getStyle("A{$row}:B{$row}")->getFont()->setBold(true);
                    }
                }
            },
        ];
    }
}
