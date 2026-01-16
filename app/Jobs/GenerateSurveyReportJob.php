<?php

namespace App\Jobs;

use App\Exports\ExportSurveyReport;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Maatwebsite\Excel\Facades\Excel;

class GenerateSurveyReportJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The number of seconds the job can run before timing out.
     */
    public int $timeout = 3600; // 1 hour

    /**
     * The number of times the job may be attempted.
     */
    public int $tries = 1; // Only try once to prevent duplicate runs

    /**
     * The number of seconds the job's unique lock will be maintained.
     */
    public int $uniqueFor = 3600; // 1 hour - enough time for the export to complete

    /**
     * The unique ID of the job.
     */
    public $uniqueId;

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
        
        // Generate unique job ID to prevent duplicate runs
        $this->uniqueId = "survey_export_{$this->userId}_{$this->surveyId}_" . md5($this->filePath);
    }

    /**
     * The unique ID of the job.
     */
    public function uniqueId(): string
    {
        return $this->uniqueId;
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
                'unique_id' => $this->uniqueId,
                'memory_limit' => ini_get('memory_limit')
            ]);

            // Initialize progress
            if ($this->progressKey) {
                \Illuminate\Support\Facades\Cache::put($this->progressKey, [
                    'status' => 'initializing',
                    'progress' => 0,
                    'message' => 'Starting export...'
                ], 3600);
            }

            // Create export instance and store to disk
            $export = new ExportSurveyReport($this->surveyId, $this->userId, $this->diskName, $this->filePath, $this->progressKey);

            // Use memory-efficient writer settings
            Excel::store($export, $this->filePath, $this->diskName, \Maatwebsite\Excel\Excel::XLSX);

            // Mark as complete
            if ($this->progressKey) {
                \Illuminate\Support\Facades\Cache::put($this->progressKey, [
                    'status' => 'completed',
                    'progress' => 100,
                    'message' => 'Export completed!',
                    'file_path' => $this->filePath,
                    'download_url' => \Illuminate\Support\Facades\Storage::disk($this->diskName)->url($this->filePath)
                ], 3600);
            }

            Log::info("Survey report export completed successfully", [
                'survey_id' => $this->surveyId,
                'file_path' => $this->filePath,
                'unique_id' => $this->uniqueId
            ]);
        } catch (\Exception $e) {
            // Mark as failed
            if ($this->progressKey) {
                \Illuminate\Support\Facades\Cache::put($this->progressKey, [
                    'status' => 'failed',
                    'progress' => 0,
                    'message' => 'Export failed: ' . $e->getMessage()
                ], 3600);
            }

            Log::error("Survey report export job failed", [
                'survey_id' => $this->surveyId,
                'unique_id' => $this->uniqueId,
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
