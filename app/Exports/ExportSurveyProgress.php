<?php

namespace App\Exports;

use App\Models\SurveyProgress;
use App\Models\SMSInbox;
use App\Models\User;
use Filament\Notifications\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithChunkReading;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\Exportable;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Events\AfterSheet;

class ExportSurveyProgress implements 
    FromQuery,
    WithMapping,
    WithHeadings,
    ShouldAutoSize,
    WithChunkReading,
    ShouldQueue,
    WithEvents
{
    use Exportable;

    protected ?array $filters;
    protected ?string $scope;
    protected int $userId;
    protected string $diskName;
    protected string $fileName;

    public function __construct(?string $scope = null, ?array $filters = [], int $userId = null, string $diskName = 'public', string $fileName = 'export.xlsx')
    {
        $this->scope = $scope;
        $this->filters = $filters;
        $this->userId = $userId ?? auth()->id();
        $this->diskName = $diskName;
        $this->fileName = $fileName;

        Log::info("ExportSurveyProgress constructor received filename: " . $this->fileName);
    }

    public function query()
    {
        $query = SurveyProgress::query()->with('member', 'survey');

        // Apply filters
        if (!empty($this->filters['survey_id'])) {
            $query->where('survey_id', $this->filters['survey_id']);
        }
        if (!empty($this->filters['group_id'])) {
            $groupIds = is_array($this->filters['group_id']) ? $this->filters['group_id'] : [$this->filters['group_id']];
            $query->whereHas('member', fn($q) => $q->whereIn('group_id', $groupIds));
        }
        if (!empty($this->filters['county_id'])) {
            $query->whereHas('member', fn($q) => $q->where('county_id', $this->filters['county_id']));
        }

        // Apply scope
        switch ($this->scope) {
            case 'completed':
                $query->whereNotNull('completed_at')->where('status', 'COMPLETED');
                break;
            case 'in_progress':
                $query->whereNull('completed_at')->whereIn('status', ['ACTIVE', 'UPDATING_DETAILS', 'PENDING']);
                break;
            case 'cancelled':
                $query->whereNull('completed_at')->where('status', 'CANCELLED');
                break;
            case 'members_with_reminders':
                $query->whereHas('member.smsInboxes', fn($q) => $q->where('is_reminder', true)->distinct('phone_number'));
                break;
            case 'repeat_reminders':
                $query->whereHas('member.smsInboxes', function($q) {
                    $q->where('is_reminder', true)
                      ->select('phone_number', 'message')
                      ->groupBy('phone_number', 'message')
                      ->havingRaw('COUNT(*) >= 3');
                });
                break;
            default:
                // total = no additional filter
                break;
        }

        return $query->orderBy('id');
    }

    public function chunkSize(): int
    {
        return 10000;
    }

    public function headings(): array
    {
        return [
            'Member Name',
            'Group Name',
            'Phone Number',
            'Survey Name',
            'Current Question',
            'County',
            'Status',
            'Completed At',
            'Created At',
        ];
    }

    public function map($row): array
    {
        return [
            $row->member->name ?? 'N/A',
            $row->member->group->name ?? 'N/A',
            $row->member->phone ?? 'N/A',
            $row->survey->title ?? 'N/A',
            $row->currentQuestion->question ?? 'N/A',
            $row->member->county->name ?? $row->member->group->County->name ?? 'N/A',
            $row->status,
            $row->completed_at?->format('Y-m-d H:i:s'),
            $row->created_at?->format('Y-m-d H:i:s'),
        ];
    }

    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $event) {
                $fullpath = "storage/{$this->fileName}";
                $downloadUrl = asset($fullpath);

                Log::info("Survey export finished for user: {$this->userId} and scope: {$this->scope}");

                $user = User::find($this->userId);
                if ($user) {
                    Notification::make()
                        ->title('Survey Export Complete! ✅')
                        ->body("Your survey export for scope '{$this->scope}' is ready for download.")
                        ->success()
                        ->actions([
                            Action::make('download')
                                ->label('Download Survey Export')
                                ->url($downloadUrl, shouldOpenInNewTab: true)
                                ->button(),
                        ])
                        ->sendToDatabase($user);
                }

                $event->sheet->getStyle('A1:F1')->getFont()->setBold(true);
            },
        ];
    }
}
