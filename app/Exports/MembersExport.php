<?php

namespace App\Exports;

use App\Models\Member;
use App\Models\User;
use Filament\Notifications\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Builder;
use Maatwebsite\Excel\Concerns\Exportable;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithChunkReading;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Events\AfterSheet;

class MembersExport implements FromQuery, ShouldAutoSize, ShouldQueue, WithChunkReading, WithEvents, WithHeadings, WithMapping
{
    use Exportable;

    public function __construct(
        protected ?array $memberIds,
        protected int $userId,
        protected string $fileName,
    ) {}

    public function query(): Builder
    {
        $query = Member::query()
            ->with(['groups:id,name', 'county:id,name'])
            ->select([
                'id',
                'stage',
                'name',
                'email',
                'phone',
                'national_id',
                'account_number',
                'gender',
                'dob',
                'marital_status',
                'is_active',
                'county_id',
                'created_at',
            ])
            ->orderBy('id');

        if (! empty($this->memberIds)) {
            $query->whereIn('id', $this->memberIds);
        }

        $user = User::find($this->userId);
        if ($user?->hasRole('county_staff')) {
            $query->where('county_id', $user->county_id);
        }

        return $query;
    }

    public function headings(): array
    {
        return [
            'ID',
            'Account Number',
            'Name',
            'Groups',
            'County',
            'Stage',
            'Phone',
            'Email',
            'National ID',
            'Gender',
            'Date of Birth',
            'Marital Status',
            'Active',
            'Created At',
        ];
    }

    public function map($member): array
    {
        return [
            $member->id,
            $member->account_number,
            $member->name,
            $member->groups->pluck('name')->join(', '),
            $member->county?->name,
            $member->stage,
            $member->phone,
            $member->email,
            $member->national_id,
            $member->gender,
            $member->dob?->format('Y-m-d'),
            $member->marital_status,
            $member->is_active ? 'Yes' : 'No',
            $member->created_at?->format('Y-m-d H:i:s'),
        ];
    }

    public function chunkSize(): int
    {
        return 1000;
    }

    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $event) {
                $event->sheet->getStyle('A1:N1')->getFont()->setBold(true);

                $user = User::find($this->userId);
                if (! $user) {
                    return;
                }

                Notification::make()
                    ->title('Member export ready')
                    ->body('The member list export has finished and is ready to download.')
                    ->success()
                    ->actions([
                        Action::make('download')
                            ->label('Download member export')
                            ->url(asset("storage/{$this->fileName}"), shouldOpenInNewTab: true)
                            ->button(),
                    ])
                    ->sendToDatabase($user);
            },
        ];
    }
}
