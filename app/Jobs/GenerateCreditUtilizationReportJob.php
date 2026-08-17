<?php

namespace App\Jobs;

use App\Exports\CreditsReportExport;
use App\Models\CreditReportExport;
use App\Services\CreditUtilizationReportService;
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
use Maatwebsite\Excel\Excel as ExcelWriter;
use Maatwebsite\Excel\Facades\Excel;
use RuntimeException;
use Throwable;

class GenerateCreditUtilizationReportJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 3600;

    public int $tries = 1;

    public int $uniqueFor = 3600;

    public function __construct(public readonly int $creditReportExportId) {}

    public function uniqueId(): string
    {
        return 'credit-utilization-report-'.$this->creditReportExportId;
    }

    public function handle(CreditUtilizationReportService $service): void
    {
        $report = CreditReportExport::query()->with('user')->findOrFail($this->creditReportExportId);
        $report->update([
            'status' => CreditReportExport::STATUS_PROCESSING,
            'started_at' => now(),
            'failed_at' => null,
            'error_message' => null,
        ]);

        try {
            $stored = Excel::store(
                new CreditsReportExport($report->filters, $report->user?->name ?? 'Echo Net Africa user'),
                $report->file_path,
                $report->disk,
                ExcelWriter::XLSX
            );

            $disk = Storage::disk($report->disk);
            if (! $stored || ! $disk->exists($report->file_path) || $disk->size($report->file_path) < 1) {
                throw new RuntimeException('The Excel report could not be verified after generation.');
            }

            $report->update([
                'status' => CreditReportExport::STATUS_COMPLETED,
                'row_count' => $service->query($report->filters)->count(),
                'file_size' => $disk->size($report->file_path),
                'completed_at' => now(),
            ]);

            if ($report->user) {
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
            }
        } catch (Throwable $exception) {
            $this->markFailed($report, $exception);
            throw $exception;
        }
    }

    public function failed(Throwable $exception): void
    {
        $report = CreditReportExport::query()->with('user')->find($this->creditReportExportId);
        if ($report && $report->status !== CreditReportExport::STATUS_FAILED) {
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
