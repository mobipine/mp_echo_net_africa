<?php

namespace App\Exports;

use App\Exports\CreditUtilization\CreditDailyTrendSheet;
use App\Exports\CreditUtilization\CreditDataDictionarySheet;
use App\Exports\CreditUtilization\CreditSurveyBreakdownSheet;
use App\Exports\CreditUtilization\CreditTransactionLedgerSheet;
use App\Exports\CreditUtilization\CreditUtilizationSummarySheet;
use App\Services\CreditUtilizationReportService;
use Maatwebsite\Excel\Concerns\Exportable;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;

class CreditsReportExport implements WithMultipleSheets
{
    use Exportable;

    public function __construct(
        protected array $filters = [],
        protected string $requestedBy = 'Echo Net Africa user'
    ) {}

    public function sheets(): array
    {
        $service = app(CreditUtilizationReportService::class);
        $filters = $service->normalizeFilters($this->filters);

        return [
            new CreditUtilizationSummarySheet($filters, $this->requestedBy),
            new CreditDailyTrendSheet($filters),
            new CreditSurveyBreakdownSheet($filters),
            new CreditTransactionLedgerSheet($filters),
            new CreditDataDictionarySheet($filters),
        ];
    }
}
