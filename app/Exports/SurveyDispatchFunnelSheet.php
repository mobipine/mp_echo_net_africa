<?php

namespace App\Exports;

use App\Services\SurveyDispatchFunnelService;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;

class SurveyDispatchFunnelSheet implements FromArray, WithTitle, ShouldAutoSize, WithEvents
{
    /** Row numbers (1-based) that are section titles. */
    protected array $titleRows = [];

    /** Row numbers (1-based) that are column headers. */
    protected array $headerRows = [];

    public function __construct(
        protected int $surveyId,
        protected ?int $groupId = null
    ) {}

    public function title(): string
    {
        return 'Dispatch Funnel';
    }

    public function array(): array
    {
        $service = app(SurveyDispatchFunnelService::class);
        $funnel = $service->build($this->surveyId, $this->groupId);
        $participation = $service->participation($this->surveyId, $this->groupId);

        $rows = [];

        // --- Section 1: Dispatch funnel ---
        $rows[] = ['Dispatch Funnel'];
        $this->titleRows[] = count($rows);
        $rows[] = ['Stage', 'Dispatched On', 'Total Sent', 'Total Responses'];
        $this->headerRows[] = count($rows);
        foreach ($funnel as $stage) {
            $rows[] = [
                $stage['label'],
                $stage['dispatched_at'] ?? 'N/A',
                $stage['sent'],
                $stage['responses'] ?? 0,
            ];
        }

        $rows[] = [];

        // --- Section 2: Participation summary ---
        $rows[] = ['Participation Summary'];
        $this->titleRows[] = count($rows);
        $rows[] = ['Members involved', $participation['involved']];
        $rows[] = ['Completed', $participation['completed']];
        $rows[] = ['Stalled (did not complete)', $participation['stalled']];

        $rows[] = [];

        // --- Section 3: Where members stalled, by question ---
        $rows[] = ['Where Members Stalled (by question)'];
        $this->titleRows[] = count($rows);
        $rows[] = ['#', 'Question', 'Members Stalled Here'];
        $this->headerRows[] = count($rows);
        foreach ($participation['byQuestion'] as $question) {
            $rows[] = [
                $question['position'],
                $question['question'],
                $question['count'],
            ];
        }

        return $rows;
    }

    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $event) {
                $sheet = $event->sheet->getDelegate();
                $highestColumn = $sheet->getHighestColumn();

                foreach ($this->titleRows as $row) {
                    $sheet->mergeCells("A{$row}:{$highestColumn}{$row}");
                    $sheet->getStyle("A{$row}")->applyFromArray([
                        'font' => ['bold' => true, 'size' => 13, 'color' => ['rgb' => '1F3864']],
                    ]);
                }

                foreach ($this->headerRows as $row) {
                    $sheet->getStyle("A{$row}:{$highestColumn}{$row}")->applyFromArray([
                        'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
                        'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '4472C4']],
                        'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
                        'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => '000000']]],
                    ]);
                }
            },
        ];
    }
}
