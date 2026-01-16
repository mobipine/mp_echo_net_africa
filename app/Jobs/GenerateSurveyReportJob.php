<?php

namespace App\Jobs;

use App\Exports\ExportSurveyReport;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Maatwebsite\Excel\Facades\Excel;

class GenerateSurveyReportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The number of seconds the job can run before timing out.
     */
    public int $timeout = 3600; // 1 hour

    /**
     * The number of times the job may be attempted.
     */
    public int $tries = 3;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public int $surveyId,
        public int $userId,
        public string $diskName,
        public string $filePath
    ) {}

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        try {
            Log::info("Starting survey report export job", [
                'survey_id' => $this->surveyId,
                'user_id' => $this->userId,
                'file_path' => $this->filePath
            ]);

            // Create export instance and store to disk
            $export = new ExportSurveyReport($this->surveyId, $this->userId, $this->diskName, $this->filePath);
            
            // Store the file (this runs in background)
            Excel::store($export, $this->filePath, $this->diskName);

            Log::info("Survey report export completed", [
                'survey_id' => $this->surveyId,
                'file_path' => $this->filePath
            ]);
        } catch (\Exception $e) {
            Log::error("Survey report export job failed", [
                'survey_id' => $this->surveyId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            throw $e;
        }
    }
}
