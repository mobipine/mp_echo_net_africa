<?php

namespace App\Jobs;

use App\Models\CreditReportExport;
use App\Services\CreditUtilizationWorkbookWriter;
use Filament\Notifications\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

class GenerateCreditUtilizationReportJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 3600;

    public int $tries = 1;

    public int $uniqueFor = 3900;

    public function __construct(public readonly int $creditReportExportId)
    {
        $this->onConnection('credit-reports');
        $this->onQueue('credit-reports');
    }

    public function uniqueId(): string
    {
        return 'credit-utilization-report-'.$this->creditReportExportId;
    }

    public function handle(CreditUtilizationWorkbookWriter $writer): void
    {
        $report = CreditReportExport::query()->with('user')->findOrFail($this->creditReportExportId);
        $report->update([
            'status' => CreditReportExport::STATUS_PROCESSING,
            'started_at' => now(),
            'completed_at' => null,
            'failed_at' => null,
            'error_message' => null,
            'row_count' => null,
            'file_size' => null,
        ]);

        $disk = Storage::disk($report->disk);
        $temporaryPath = $report->file_path.'.part';

        try {
            $disk->makeDirectory(dirname($report->file_path));
            $disk->delete($temporaryPath);

            $rowCount = $writer->write(
                $report->filters,
                $report->user?->name ?? 'Echo Net Africa user',
                $disk->path($temporaryPath)
            );

            if (! $disk->exists($temporaryPath) || $disk->size($temporaryPath) < 1) {
                throw new RuntimeException('The Excel report could not be verified after generation.');
            }

            $disk->delete($report->file_path);
            if (! $disk->move($temporaryPath, $report->file_path)) {
                throw new RuntimeException('The Excel report could not be finalized after generation.');
            }

            $report->update([
                'status' => CreditReportExport::STATUS_COMPLETED,
                'row_count' => $rowCount,
                'file_size' => $disk->size($report->file_path),
                'completed_at' => now(),
                'failed_at' => null,
                'error_message' => null,
            ]);

            if ($report->user) {
                try {
                    Notification::make()
                        ->title('Credit utilization report ready')
                        ->body('Your detailed Excel workbook is ready to download.')
                        ->success()
                        ->actions([
                            Action::make('download')
                                ->label('Download workbook')
                                ->url(route('credit-reports.download', $report))
                                ->button(),
                        ])
                        ->sendToDatabase($report->user);
                } catch (Throwable $exception) {
                    Log::warning('Credit report completed, but its notification could not be sent.', [
                        'credit_report_export_id' => $report->id,
                        'user_id' => $report->user_id,
                        'exception' => $exception,
                    ]);
                }
            }
        } catch (Throwable $exception) {
            $disk->delete($temporaryPath);
            $this->markFailed($report, $exception);
            throw $exception;
        }
    }

    public function failed(Throwable $exception): void
    {
        $report = CreditReportExport::query()->with('user')->find($this->creditReportExportId);
        if ($report && in_array($report->status, [
            CreditReportExport::STATUS_QUEUED,
            CreditReportExport::STATUS_PROCESSING,
        ], true)) {
            $this->markFailed($report, $exception);
        }
    }

    private function markFailed(CreditReportExport $report, Throwable $exception): void
    {
        $report->update([
            'status' => CreditReportExport::STATUS_FAILED,
            'failed_at' => now(),
            'error_message' => str($exception->getMessage())->limit(1000)->toString(),
        ]);

        Log::error('Credit utilization report generation failed.', [
            'credit_report_export_id' => $report->id,
            'user_id' => $report->user_id,
            'exception' => $exception,
        ]);

        if ($report->user) {
            Notification::make()
                ->title('Credit report export failed')
                ->body('The workbook could not be generated. Please retry or contact support if the issue continues.')
                ->danger()
                ->sendToDatabase($report->user);
        }
    }
}
