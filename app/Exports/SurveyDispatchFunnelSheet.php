<?php

namespace App\Exports;

use App\Services\SurveyDispatchFunnelService;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;

class SurveyDispatchFunnelSheet implements FromArray, WithHeadings, WithTitle, ShouldAutoSize, WithEvents
{
    public function __construct(
        protected int $surveyId,
        protected ?int $groupId = null
    ) {}

    public function title(): string
    {
        return 'Dispatch Funnel';
    }

    public function headings(): array
    {
        return ['Stage', 'Dispatched On', 'Total Sent', 'Total Responses'];
    }

    public function array(): array
    {
        $stages = app(SurveyDispatchFunnelService::class)->build($this->surveyId, $this->groupId);

        return array_map(function ($stage) {
            return [
                $stage['label'],
                $stage['dispatched_at'] ?? 'N/A',
                $stage['sent'],
                $stage['responses'] ?? 0,
            ];
        }, $stages);
    }

    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $event) {
                $sheet = $event->sheet->getDelegate();
                $highestColumn = $sheet->getHighestColumn();
                $highestRow = $sheet->getHighestRow();

                $sheet->getStyle('A1:' . $highestColumn . '1')->applyFromArray([
                    'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF'], 'size' => 11],
                    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '4472C4']],
                    'alignment' => [
                        'horizontal' => Alignment::HORIZONTAL_CENTER,
                        'vertical' => Alignment::VERTICAL_CENTER,
                    ],
                    'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => '000000']]],
                ]);

                if ($highestRow > 1) {
                    $sheet->getStyle('A2:' . $highestColumn . $highestRow)->applyFromArray([
                        'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'CCCCCC']]],
                    ]);
                }

                $sheet->freezePane('A2');
            },
        ];
    }
}
