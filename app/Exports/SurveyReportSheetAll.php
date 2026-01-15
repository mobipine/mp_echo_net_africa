<?php

namespace App\Exports;

use App\Models\SurveyProgress;
use App\Models\SurveyResponse;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use Illuminate\Support\Collection;

class SurveyReportSheetAll implements FromCollection, WithHeadings, ShouldAutoSize, WithTitle, WithEvents
{
    protected int $surveyId;
    protected array $englishQuestions;
    protected array $headings;

    public function __construct(int $surveyId, array $englishQuestions, array $headings)
    {
        $this->surveyId = $surveyId;
        $this->englishQuestions = $englishQuestions;
        $this->headings = $headings;
    }

    public function title(): string
    {
        return 'All';
    }

    public function headings(): array
    {
        return $this->headings;
    }

    public function collection()
    {
        // Get ALL members who started this survey
        $allProgresses = SurveyProgress::where('survey_id', $this->surveyId)
            ->with(['member.group', 'member.county'])
            ->get();

        return $this->buildData($allProgresses);
    }

    protected function buildData($progresses)
    {
        // Get all responses for this survey
        $allResponses = SurveyResponse::where('survey_id', $this->surveyId)
            ->get();

        // Group responses by normalized phone number for reliable matching
        $responsesByPhone = collect();
        foreach ($allResponses as $response) {
            $normalizedPhone = normalizePhoneNumber($response->msisdn);
            if (!$responsesByPhone->has($normalizedPhone)) {
                $responsesByPhone[$normalizedPhone] = collect();
            }
            $responsesByPhone[$normalizedPhone]->push($response);
        }

        $data = collect();

        foreach ($progresses as $progress) {
            $member = $progress->member;
            if (!$member) {
                continue;
            }
            
            $msisdn = $member->phone;
            $normalizedMemberPhone = normalizePhoneNumber($msisdn);
            
            $memberResponses = $responsesByPhone->get($normalizedMemberPhone, collect());
            // Group by question_id and get the latest response for each question
            $responseMap = $memberResponses
                ->sortByDesc('created_at')
                ->groupBy('question_id')
                ->map(function ($responses) {
                    return $responses->first(); // Get the latest (already sorted by created_at desc)
                });

            // Build row with member details - replace empty values with N/A
            $row = [
                $member->group->name ?? 'N/A',
                $member->name ?? 'N/A',
                $member->email ?? 'N/A',
                $msisdn ?? 'N/A',
                $member->national_id ?? 'N/A',
                $member->gender ?? 'N/A',
                $member->dob ? $member->dob->format('Y-m-d') : 'N/A',
                $member->marital_status ?? 'N/A',
                $member->county->name ?? 'N/A',
            ];

            // Add answers for each English question (check both English and Kiswahili)
            foreach ($this->englishQuestions as $question) {
                $englishQuestionId = $question['id'];
                $swahiliQuestionId = $question['swahili_question_id'] ?? null;
                
                // Check English answer first, then Kiswahili
                $englishResponse = $responseMap->get($englishQuestionId);
                $swahiliResponse = $swahiliQuestionId ? $responseMap->get($swahiliQuestionId) : null;
                
                $answer = $englishResponse 
                    ? $englishResponse->survey_response 
                    : ($swahiliResponse ? $swahiliResponse->survey_response : '');
                
                // Replace empty answer with N/A
                $row[] = $answer ?: 'N/A';
            }

            $data->push($row);
        }

        return $data;
    }

    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $event) {
                $sheet = $event->sheet->getDelegate();
                $highestRow = $sheet->getHighestRow();
                $highestColumn = $sheet->getHighestColumn();
                $highestColumnIndex = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($highestColumn);

                // Style header row (row 1)
                $headerRange = 'A1:' . $highestColumn . '1';
                $sheet->getStyle($headerRange)->applyFromArray([
                    'font' => [
                        'bold' => true,
                        'color' => ['rgb' => 'FFFFFF'],
                        'size' => 11,
                    ],
                    'fill' => [
                        'fillType' => Fill::FILL_SOLID,
                        'startColor' => ['rgb' => '4472C4'], // Blue background
                    ],
                    'alignment' => [
                        'horizontal' => Alignment::HORIZONTAL_CENTER,
                        'vertical' => Alignment::VERTICAL_CENTER,
                    ],
                    'borders' => [
                        'allBorders' => [
                            'borderStyle' => Border::BORDER_THIN,
                            'color' => ['rgb' => '000000'],
                        ],
                    ],
                ]);

                // Set row height for header
                $sheet->getRowDimension(1)->setRowHeight(20);

                // Add borders to all data cells
                if ($highestRow > 1) {
                    $dataRange = 'A2:' . $highestColumn . $highestRow;
                    $sheet->getStyle($dataRange)->applyFromArray([
                        'borders' => [
                            'allBorders' => [
                                'borderStyle' => Border::BORDER_THIN,
                                'color' => ['rgb' => 'CCCCCC'],
                            ],
                        ],
                        'alignment' => [
                            'vertical' => Alignment::VERTICAL_CENTER,
                        ],
                    ]);

                    // Alternate row colors for better readability
                    for ($row = 2; $row <= $highestRow; $row++) {
                        if ($row % 2 == 0) {
                            $rowRange = 'A' . $row . ':' . $highestColumn . $row;
                            $sheet->getStyle($rowRange)->applyFromArray([
                                'fill' => [
                                    'fillType' => Fill::FILL_SOLID,
                                    'startColor' => ['rgb' => 'F2F2F2'], // Light gray
                                ],
                            ]);
                        }
                    }
                }

                // Auto-size columns
                for ($col = 1; $col <= $highestColumnIndex; $col++) {
                    $columnLetter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($col);
                    $sheet->getColumnDimension($columnLetter)->setAutoSize(true);
                }

                // Freeze header row
                $sheet->freezePane('A2');
            },
        ];
    }
}
