<?php

namespace App\Exports;

use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Events\AfterSheet;
use Illuminate\Support\Facades\DB;

class SmsStatsExport implements FromCollection, ShouldAutoSize, WithEvents
{
    protected array $filters;

    public function __construct(array $filters = [])
    {
        $this->filters = $filters;
    }

    public function collection()
    {
        $query = DB::table('sms_inboxes')->whereNotNull('phone_number');

        $results = $query->select(
            DB::raw("COUNT(CASE WHEN delivery_status = 'Failed' THEN 1 END) AS DeliveryFailed"),
            DB::raw("COUNT(CASE WHEN delivery_status = 'pending' AND status = 'sent' THEN 1 END) AS PendingDeliveryWhileSent"),
            DB::raw("COUNT(CASE WHEN status = 'sent' THEN 1 END) AS SuccessfullySent"),
            DB::raw("COUNT(CASE WHEN status = 'Failed' THEN 1 END) AS SendingFailed"),
            DB::raw("COUNT(CASE WHEN status = 'pending' THEN 1 END) AS PendingQueue"),
        )->first();

        $rows = collect();

        // --- Section 1: Main Stats ---
        $rows->push(['MAIN STATS', '']); // Title row
        $rows->push(['Delivery Failed', $results->DeliveryFailed ?? 0]);
        $rows->push(['Pending Delivery (Sent)', $results->PendingDeliveryWhileSent ?? 0]);
        $rows->push(['Successfully Sent', $results->SuccessfullySent ?? 0]);
        $rows->push(['Sending Failed', $results->SendingFailed ?? 0]);
        $rows->push(['On Sending Queue (Pending)', $results->PendingQueue ?? 0]);
        $rows->push([]); // Empty line

        // --- Section 2: Delivery Failure Reasons ---
        $rows->push(['DELIVERY FAILURE REASONS', '']); // Title

        $deliveryFailures = DB::table('sms_inboxes')
            ->select('delivery_status_desc', DB::raw('COUNT(*) as count'))
            ->where('delivery_status', 'Failed')
            ->whereNotNull('delivery_status_desc')
            ->groupBy('delivery_status_desc')
            ->get();

        if ($deliveryFailures->isNotEmpty()) {
            foreach ($deliveryFailures as $fail) {
                $rows->push([$fail->delivery_status_desc, $fail->count]);
            }
        } else {
            $rows->push(['N/A', 0]);
        }

        $rows->push([]); // Empty line

        // --- Section 3: Message Not Sent Reasons ---
        $rows->push(['MESSAGE NOT SENT REASONS', '']); // Title

        $sendingFailures = DB::table('sms_inboxes')
            ->select('failure_reason', DB::raw('COUNT(*) as count'))
            ->where('status', 'Failed')
            ->whereNotNull('failure_reason')
            ->groupBy('failure_reason')
            ->get();

        if ($sendingFailures->isNotEmpty()) {
            foreach ($sendingFailures as $fail) {
                $rows->push([$fail->failure_reason, $fail->count]);
            }
        } else {
            $rows->push(['N/A', 0]);
        }

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
