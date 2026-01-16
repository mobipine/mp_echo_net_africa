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
        public string $filePath,
        public ?string $progressKey = null
    ) {
        // Generate progress key if not provided
        if (!$this->progressKey) {
            $this->progressKey = "export_progress_{$this->userId}_{$this->surveyId}_" . md5($this->filePath . now()->timestamp);
        }
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        // Increase memory limit for large exports
        $originalMemoryLimit = ini_get('memory_limit');
        ini_set('memory_limit', '1024M'); // 1GB

        try {
            Log::info("Starting survey report export job", [
                'survey_id' => $this->surveyId,
                'user_id' => $this->userId,
                'file_path' => $this->filePath,
                'memory_limit' => ini_get('memory_limit')
            ]);

            // Create export instance and store to disk
            $export = new ExportSurveyReport($this->surveyId, $this->userId, $this->diskName, $this->filePath, $this->progressKey);

            // Use memory-efficient writer settings
            Excel::store($export, $this->filePath, $this->diskName, \Maatwebsite\Excel\Excel::XLSX);

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
        } finally {
            // Restore original memory limit
            ini_set('memory_limit', $originalMemoryLimit);
        }
    }
}
