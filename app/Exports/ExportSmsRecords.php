<?php

namespace App\Exports;

use App\Models\SMSInbox;
use App\Models\User;
use Filament\Notifications\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithChunkReading;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Events\AfterSheet;
use Maatwebsite\Excel\Concerns\Exportable;
use Illuminate\Support\Facades\Storage;
class ExportSmsRecords implements 
    FromQuery, 
    WithHeadings, 
    WithMapping, 
    ShouldAutoSize, 
    WithChunkReading, 
    ShouldQueue, 
    WithEvents
{
    use Exportable;
    protected ?string $scope;
    protected int $userId;
    protected string $diskName; 
    protected string $fileName; 
    public function __construct(?string $scope = null,int $userId = null, string $diskName = 'public', string $fileName = 'export.xlsx')
    {
        $this->scope = $scope;
        $this->userId = $userId ?? auth()->id();
        $this->diskName = $diskName;
        $this->fileName = $fileName;
        Log::info("Export constructor received filename: " . $this->fileName);
    }

    public function query()
    {
        $query = SMSInbox::with('member:id,name,phone');

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
                $subQuery = SMSInbox::select(DB::raw('MAX(id)'))
                    ->where('delivery_status_desc', 'SenderName Blacklisted')
                    ->groupBy('member_id');

                $query->whereIn('id', $subQuery);
                break;
        }

        return $query->orderBy('id');
    }

    public function chunkSize(): int
    {
        return 1000;
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
            $row->member->name ?? 'N/A',
            $row->message,
            $row->status,
            $row->delivery_status,
            $row->delivery_status_desc,
            $row->failure_reason,
            $row->created_at?->format('Y-m-d H:i:s'),
        ];
    }

public function registerEvents(): array
{
    return [
        AfterSheet::class => function (AfterSheet $event) {
            $fullpath="storage/{$this->fileName}";
            $downloadUrl = asset($fullpath);
            
            Log::info("Export finished for scope: {$this->scope}");
            $user = User::find($this->userId);
            
            if ($user) {
                Notification::make()
                    ->title('Export Complete! ✅')
                    ->body('Your export is ready for download.')
                    ->success()
                    ->actions([
                        Action::make('download')
                            ->label('Download ' . strtoupper($this->scope) . ' Export')
                            ->url($downloadUrl, shouldOpenInNewTab: true)
                            ->button(),
                    ])
                    ->sendToDatabase($user);
            }
            
            $event->sheet->getStyle('A1:H1')->getFont()->setBold(true);
        },
    ];
}
    
}

