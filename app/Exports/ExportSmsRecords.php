<?php

namespace App\Exports;

use App\Models\SMSInbox;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Events\AfterSheet;

class ExportSmsRecords implements FromQuery, WithHeadings, WithMapping, ShouldAutoSize, WithEvents
{
    protected ?string $scope;

    public function __construct(?string $scope = null)
    {
        Log::info("Exporting... {$scope}");
        $this->scope = $scope;
    }

    public function query()
    {
        $query = SMSInbox::query();

        switch ($this->scope) {
            case 'DeliveredToTerminal':
                $query->where('delivery_status_desc', 'DeliveredToTerminal');
                break;
            case 'failed':
                $query->where('delivery_status', 'Failed');
                break;
            case 'sent':
                $query->where('status', 'sent');
                break;
            case 'SenderName Blacklisted':
                $query->where('delivery_status_desc', 'SenderName Blacklisted');
                break;
            case 'AbsentSubscriber':
                $query->where('delivery_status_desc', 'AbsentSubscriber');
                break;
            case 'DeliveryImpossible':
                $query->where('delivery_status_desc', 'DeliveryImpossible');
                break;
            case 'DeliveredToNetwork':
                $query->where('delivery_status_desc', 'DeliveredToNetwork');
                break;
            case 'SendingFailed':
                $query->where('status', 'Failed');
                break;
            case 'unique_members_that_have_sender_blacklisted':
                $query->where('delivery_status_desc', 'SenderName Blacklisted');

                $subQuery = SMSInbox::select(DB::raw('MAX(id) as max_id'))
                                    ->where('delivery_status_desc', 'SenderName Blacklisted')
                                    ->groupBy('member_id');
                
                $query->whereIn('id', $subQuery);
                break;

        }
        
        return $query;
    }

    public function headings(): array
    {
        return [
            'Phone Number',
            'Member Name',
            'Message',
            'Status',
            'Delivery Status',
            'Delivery Description',
            'Failure Reason',
            'Created At',
        ];
    }

    public function map($row): array
    {
        return [
            $row->phone_number,
            $row?->member?->name,
            $row->message,
            $row->status,
            $row->delivery_status,
            $row->delivery_status_desc,
            $row->failure_reason,
            $row->created_at,
        ];
    }

    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $event) {
                // Make heading bold
                $event->sheet->getStyle('A1:G1')->getFont()->setBold(true);

                // Optional: adjust row height
                $event->sheet->getRowDimension(1)->setRowHeight(22);
            },
        ];
    }
}
