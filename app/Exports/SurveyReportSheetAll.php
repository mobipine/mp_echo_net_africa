<?php

namespace App\Exports;

use App\Models\SurveyProgress;
use App\Models\SurveyResponse;
use App\Models\User;
use Filament\Notifications\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
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
    protected ?int $userId = null;
    protected ?string $filePath = null;
    protected ?string $progressKey = null;

    public function __construct(int $surveyId, array $englishQuestions, array $headings, ?int $userId = null, ?string $filePath = null, ?string $progressKey = null)
    {
        $this->surveyId = $surveyId;
        $this->englishQuestions = $englishQuestions;
        $this->headings = $headings;
        $this->userId = $userId;
        $this->filePath = $filePath;
        $this->progressKey = $progressKey;
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
        // Optimized: Build response lookup map efficiently using database aggregation
        // This is much faster than loading all responses into memory
        $responseMap = $this->buildResponseMap();

        $data = collect();
        $processed = 0;
        $total = SurveyProgress::where('survey_id', $this->surveyId)->count();

        // Process progresses in smaller chunks for better memory management
        SurveyProgress::where('survey_id', $this->surveyId)
            ->with(['member.group', 'member.county'])
            ->orderBy('id') // Ensure consistent ordering
            ->chunk(250, function ($progresses) use (&$data, $responseMap, &$processed, $total) {
                $chunkData = $this->buildData($progresses, $responseMap);
                $data = $data->merge($chunkData);

                // Update progress
                $processed += $progresses->count();
                if ($this->progressKey && $total > 0) {
                    $progress = min(90, (int)(($processed / $total) * 90)); // 90% max (10% for file writing)
                    \Illuminate\Support\Facades\Cache::put($this->progressKey, [
                        'status' => 'processing',
                        'progress' => $progress,
                        'total' => $total,
                        'processed' => $processed,
                        'message' => "Processing {$processed} of {$total} records..."
                    ], 3600);
                }

                // Force garbage collection periodically
                if ($processed % 1000 == 0) {
                    gc_collect_cycles();
                }
            });

        return $data;
    }

    /**
     * Build response lookup map efficiently using database queries
     * Returns: [normalized_phone => [question_id => latest_response]]
     */
    protected function buildResponseMap(): \Illuminate\Support\Collection
    {
        // Get latest response per phone/question using subquery (more efficient than loading all)
        $responses = SurveyResponse::where('survey_id', $this->surveyId)
            ->select('msisdn', 'question_id', 'survey_response', 'created_at')
            ->whereIn('id', function ($query) {
                // Subquery to get only the latest response ID for each phone/question combo
                $query->select(DB::raw('MAX(id)'))
                    ->from('survey_responses')
                    ->where('survey_id', $this->surveyId)
                    ->groupBy('msisdn', 'question_id');
            })
            ->orderBy('created_at', 'desc')
            ->get();

        // Build lookup map: [normalized_phone => [question_id => response]]
        $map = collect();
        foreach ($responses as $response) {
            $normalizedPhone = normalizePhoneNumber($response->msisdn);
            if (!$map->has($normalizedPhone)) {
                $map[$normalizedPhone] = collect();
            }
            // Store response object
            $map[$normalizedPhone][$response->question_id] = (object)[
                'survey_response' => $response->survey_response,
                'created_at' => $response->created_at
            ];
        }

        return $map;
    }

    protected function buildData($progresses, $responseMap)
    {
        $data = collect();

        foreach ($progresses as $progress) {
            $member = $progress->member;
            if (!$member) {
                continue;
            }

            $msisdn = $member->phone;
            if (!$msisdn) {
                continue;
            }

            $normalizedMemberPhone = normalizePhoneNumber($msisdn);

            // Get responses for this member from optimized lookup map
            $memberResponses = $responseMap->get($normalizedMemberPhone, collect());

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
                $englishResponse = $memberResponses->get($englishQuestionId);
                $swahiliResponse = $swahiliQuestionId ? $memberResponses->get($swahiliQuestionId) : null;

                $answer = $englishResponse
                    ? ($englishResponse->survey_response ?? '')
                    : ($swahiliResponse ? ($swahiliResponse->survey_response ?? '') : '');

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

                // Send notification when export is complete (only from the "All" sheet to avoid duplicates)
                if ($this->userId && $this->filePath && $this->title() === 'All') {
                    $this->sendNotification();
                }
            },
        ];
    }

    protected function sendNotification(): void
    {
        try {
            $user = User::find($this->userId);
            if (!$user) {
                return;
            }

            // Use Storage facade to generate correct URL
            $downloadUrl = \Illuminate\Support\Facades\Storage::disk('public')->url($this->filePath);
            $survey = \App\Models\Survey::find($this->surveyId);

            Notification::make()
                ->title('Survey Report Export Complete! ✅')
                ->body("Your {$survey->title} report is ready for download.")
                ->success()
                ->actions([
                    Action::make('download')
                        ->label('Download Report')
                        ->url($downloadUrl, shouldOpenInNewTab: true)
                        ->button(),
                ])
                ->sendToDatabase($user);

            Log::info("Survey report export completed for user {$this->userId}, file: {$this->filePath}, url: {$downloadUrl}");
        } catch (\Exception $e) {
            Log::error("Failed to send notification for survey report export: " . $e->getMessage());
        }
    }
}
