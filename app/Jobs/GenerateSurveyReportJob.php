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
use App\Models\User;
use Filament\Notifications\Notification;
use Filament\Notifications\Actions\Action;

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

            // Wait a moment to ensure file is fully written to disk
            usleep(500000); // 0.5 seconds

            // Verify file was created and is accessible
            $storage = \Illuminate\Support\Facades\Storage::disk($this->diskName);
            if (!$storage->exists($this->filePath)) {
                throw new \Exception("Export file was not created at: {$this->filePath}");
            }

            // Verify file has content (not empty)
            $fileSize = $storage->size($this->filePath);
            if ($fileSize === 0 || $fileSize === false) {
                throw new \Exception("Export file is empty at: {$this->filePath}");
            }

            // Generate download URL - for 'public' disk, files are accessible via /storage/ path
            if ($this->diskName === 'public') {
                // For public disk, use asset() helper which points to storage/app/public
                // The filePath already includes 'exports/' directory
                $downloadUrl = asset('storage/' . $this->filePath);
            } else {
                // For other disks, get URL from config or construct manually
                $diskConfig = config("filesystems.disks.{$this->diskName}");
                $baseUrl = $diskConfig['url'] ?? config('app.url');
                $downloadUrl = rtrim($baseUrl, '/') . '/' . ltrim($this->filePath, '/');
            }

            // Test that the file can be read
            try {
                $fileContents = $storage->get($this->filePath);
                if (empty($fileContents)) {
                    throw new \Exception("Export file exists but cannot be read: {$this->filePath}");
                }
            } catch (\Exception $e) {
                throw new \Exception("Cannot read export file: {$this->filePath} - " . $e->getMessage());
            }

            // Mark as complete
            if ($this->progressKey) {
                \Illuminate\Support\Facades\Cache::put($this->progressKey, [
                    'status' => 'completed',
                    'progress' => 100,
                    'message' => 'Export completed!',
                    'file_path' => $this->filePath,
                    'download_url' => $downloadUrl
                ], 3600);
            }

            // Send notification only after file is verified and accessible
            $this->sendNotification($downloadUrl, $fileSize);

            Log::info("Survey report export completed successfully", [
                'survey_id' => $this->surveyId,
                'file_path' => $this->filePath,
                'unique_id' => $this->uniqueId,
                'download_url' => $downloadUrl,
                'file_size' => $fileSize,
                'file_exists' => $storage->exists($this->filePath)
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
            // Clear the running job cache
            $cacheKey = "export_job_running_{$this->uniqueId}";
            \Illuminate\Support\Facades\Cache::forget($cacheKey);

            // Restore original memory limit
            ini_set('memory_limit', $originalMemoryLimit);
        }
    }

    /**
     * Send notification to user after file is verified and accessible
     */
    protected function sendNotification(string $downloadUrl, int $fileSize): void
    {
        try {
            $user = User::find($this->userId);
            if (!$user) {
                Log::warning("Cannot send notification: User {$this->userId} not found");
                return;
            }

            $survey = \App\Models\Survey::find($this->surveyId);
            if (!$survey) {
                Log::warning("Cannot send notification: Survey {$this->surveyId} not found");
                return;
            }

            // Verify URL is accessible (basic check)
            if (empty($downloadUrl)) {
                Log::error("Cannot send notification: Download URL is empty");
                return;
            }

            Notification::make()
                ->title('Survey Report Export Complete! ✅')
                ->body("Your {$survey->title} report is ready for download. File size: " . $this->formatFileSize($fileSize))
                ->success()
                ->actions([
                    Action::make('download')
                        ->label('Download Report')
                        ->url($downloadUrl, shouldOpenInNewTab: true)
                        ->button(),
                ])
                ->sendToDatabase($user);

            Log::info("Notification sent successfully for survey report export", [
                'user_id' => $this->userId,
                'survey_id' => $this->surveyId,
                'file_path' => $this->filePath,
                'download_url' => $downloadUrl,
                'file_size' => $fileSize
            ]);
        } catch (\Exception $e) {
            // Log error but don't fail the job - file is already created
            Log::error("Failed to send notification for survey report export", [
                'user_id' => $this->userId,
                'survey_id' => $this->surveyId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
        }
    }

    /**
     * Format file size in human-readable format
     */
    protected function formatFileSize(int $bytes): string
    {
        if ($bytes >= 1073741824) {
            return number_format($bytes / 1073741824, 2) . ' GB';
        } elseif ($bytes >= 1048576) {
            return number_format($bytes / 1048576, 2) . ' MB';
        } elseif ($bytes >= 1024) {
            return number_format($bytes / 1024, 2) . ' KB';
        } else {
            return $bytes . ' bytes';
        }
    }
}
