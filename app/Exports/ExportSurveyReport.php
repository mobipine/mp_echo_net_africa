<?php

namespace App\Exports;

use App\Models\Survey;
use App\Models\User;
use Filament\Notifications\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;
use Maatwebsite\Excel\Concerns\Exportable;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;

class ExportSurveyReport implements WithMultipleSheets
{
    use Exportable;

    protected int $surveyId;
    protected int $userId;
    protected string $diskName;
    protected string $filePath;
    protected ?string $progressKey = null;
    protected ?Survey $survey = null;
    protected array $englishQuestions = [];
    protected array $headings = [];

    public function __construct(int $surveyId, int $userId, string $diskName, string $filePath, ?string $progressKey = null)
    {
        // Only store IDs - don't do heavy queries here (runs synchronously before queuing)
        $this->surveyId = $surveyId;
        $this->userId = $userId;
        $this->diskName = $diskName;
        $this->filePath = $filePath;
        $this->progressKey = $progressKey;
    }

    /**
     * Initialize survey data when job runs (not in constructor)
     */
    protected function initializeData(): void
    {
        if ($this->survey !== null) {
            return; // Already initialized
        }

        try {
            $this->survey = Survey::with('questions')->findOrFail($this->surveyId);

            // Get English questions (those with swahili_question_id set, including "no alternative" ones)
            // A question is English if:
            // 1. swahili_question_id is NOT NULL (has alternative OR marked as "no alternative")
            // 2. If swahili_question_id equals the question's own ID, it means "no alternative"
            $this->englishQuestions = $this->survey->questions()
                ->whereNotNull('swahili_question_id') // Only English questions (with or without alternative)
                ->orderBy('pivot_position')
                ->get()
                ->map(function ($question) {
                    // If swahili_question_id equals the question's own ID, it means "no alternative"
                    // Set it to null for easier handling in the export
                    if ($question->swahili_question_id == $question->id) {
                        $question->swahili_question_id = null; // Mark as no alternative
                    }
                    return $question;
                })
                ->toArray();

            // Build headings: Member details + English question texts
            $this->headings = [
                'Group Name',
                'Name',
                'Email',
                'Phone Number',
                'National ID',
                'Gender',
                'Date of Birth',
                'Marital Status',
                'County Name'
            ];

            foreach ($this->englishQuestions as $question) {
                $this->headings[] = $question['question'];
            }
        } catch (\Exception $e) {
            Log::error("Failed to initialize ExportSurveyReport: " . $e->getMessage());
            throw $e;
        }
    }

    public function sheets(): array
    {
        // Initialize data when sheets() is called (when job runs, not during queuing)
        $this->initializeData();

        // Only pass userId and filePath to the first sheet to avoid duplicate notifications
        return [
            new SurveyReportSheetAll($this->surveyId, $this->englishQuestions, $this->headings, $this->userId, $this->filePath, $this->progressKey),
            // new SurveyReportSheetCompleted($this->surveyId, $this->englishQuestions, $this->headings, null, null, null),
            // new SurveyReportSheetIncomplete($this->surveyId, $this->englishQuestions, $this->headings, null, null, null),
        ];
    }
}
