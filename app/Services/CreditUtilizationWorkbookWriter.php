<?php

namespace App\Services;

use App\Exports\CreditUtilization\CreditDailyTrendSheet;
use App\Exports\CreditUtilization\CreditDataDictionarySheet;
use App\Exports\CreditUtilization\CreditSurveyBreakdownSheet;
use App\Exports\CreditUtilization\CreditTransactionLedgerSheet;
use App\Exports\CreditUtilization\CreditUtilizationSummarySheet;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Border;
use OpenSpout\Common\Entity\Style\BorderPart;
use OpenSpout\Common\Entity\Style\CellAlignment;
use OpenSpout\Common\Entity\Style\CellVerticalAlignment;
use OpenSpout\Common\Entity\Style\Color;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\AutoFilter;
use OpenSpout\Writer\Common\Entity\Sheet;
use OpenSpout\Writer\XLSX\Entity\SheetView;
use OpenSpout\Writer\XLSX\Options;
use OpenSpout\Writer\XLSX\Writer;

class CreditUtilizationWorkbookWriter
{
    private const LEDGER_CHUNK_SIZE = 1000;

    public function __construct(private readonly CreditUtilizationReportService $reports) {}

    public function write(array $filters, string $requestedBy, string $outputPath): int
    {
        $filters = $this->reports->normalizeFilters($filters);
        $options = new Options;

        foreach ([1, 2, 6, 19, 26] as $row) {
            $options->mergeCells(0, $row, 5, $row);
        }

        $writer = new Writer($options);
        $writer->setCreator('Echo Net Africa');
        $writer->openToFile($outputPath);

        try {
            $this->writeSummary($writer, $filters, $requestedBy);
            $this->writeDailyTrend($writer, $filters);
            $this->writeSurveyBreakdown($writer, $filters);
            $rowCount = $this->writeLedger($writer, $filters);
            $this->writeDefinitions($writer, $filters);
        } finally {
            $writer->close();
        }

        return $rowCount;
    }

    private function writeSummary(Writer $writer, array $filters, string $requestedBy): void
    {
        $sheet = $writer->getCurrentSheet();
        $sheet->setName('Executive Summary');
        $this->setWidths($sheet, [34, 30, 58, 3, 3, 3]);
        $sheet->setSheetView((new SheetView)->setShowGridLines(false)->setFreezeRow(7));

        $rows = (new CreditUtilizationSummarySheet($filters, $requestedBy))->array();
        foreach ($rows as $index => $values) {
            $rowNumber = $index + 1;
            $rowStyle = match (true) {
                $rowNumber === 1 => $this->titleStyle(),
                $rowNumber === 2 => $this->subtitleStyle(),
                in_array($rowNumber, [6, 19, 26], true) => $this->sectionStyle(),
                in_array($rowNumber, [7, 20, 27], true) => $this->headerStyle(),
                default => $this->bodyStyle(),
            };

            $columnStyles = [];
            if ($rowNumber >= 8 && $rowNumber <= 15) {
                $columnStyles[1] = $this->numberStyle(2);
            } elseif ($rowNumber === 16) {
                $columnStyles[1] = $this->percentStyle();
            } elseif ($rowNumber >= 21 && $rowNumber <= 24) {
                $columnStyles[1] = $this->numberStyle();
                $columnStyles[2] = $this->percentStyle();
            }

            if ($rowNumber >= 8 && $rowNumber <= 24) {
                $columnStyles[2] = $columnStyles[2] ?? $this->wrapStyle();
            }

            $row = Row::fromValuesWithStyles($values, $rowStyle, $columnStyles);
            if (in_array($rowNumber, [1, 2], true)) {
                $row->setHeight($rowNumber === 1 ? 34 : 24);
            }
            $writer->addRow($row);
        }
    }

    private function writeDailyTrend(Writer $writer, array $filters): void
    {
        $rows = (new CreditDailyTrendSheet($filters))->array();
        $this->writeTabularSheet(
            $writer,
            'Daily Trend',
            $rows,
            [14, 18, 17, 17, 16, 17, 15, 18, 18],
            [0 => 'yyyy-mm-dd', 1 => '#,##0', 2 => '#,##0', 3 => '#,##0', 4 => '#,##0', 5 => '#,##0', 6 => '#,##0', 7 => '#,##0', 8 => '#,##0']
        );
    }

    private function writeSurveyBreakdown(Writer $writer, array $filters): void
    {
        $rows = (new CreditSurveyBreakdownSheet($filters))->array();
        $this->writeTabularSheet(
            $writer,
            'Survey Utilization',
            $rows,
            [12, 38, 17, 17, 16, 15, 17, 17, 21, 21],
            [0 => '0', 2 => '#,##0', 3 => '#,##0', 4 => '#,##0', 5 => '#,##0', 6 => '0.00', 7 => '0.0%', 8 => 'yyyy-mm-dd hh:mm', 9 => 'yyyy-mm-dd hh:mm'],
            [1]
        );
    }

    private function writeLedger(Writer $writer, array $filters): int
    {
        $sheet = $writer->addNewSheetAndMakeItCurrent();
        $sheet->setName('Transaction Ledger');
        $this->setWidths($sheet, [
            22, 15, 18, 18, 16, 12, 17, 16, 12, 34, 12, 27,
            18, 32, 20, 12, 16, 44, 16, 22, 48, 24, 15, 20,
        ]);
        $sheet->setSheetView((new SheetView)
            ->setShowGridLines(false)
            ->setFreezeRow(2)
            ->setFreezeColumn('E'));

        $ledger = new CreditTransactionLedgerSheet($filters);
        $headings = $ledger->headings();
        $writer->addRow(Row::fromValues($headings, $this->headerStyle())->setHeight(28));

        $columnStyles = $this->columnStyles(
            [0 => 'yyyy-mm-dd hh:mm:ss', 1 => '0', 4 => '#,##0', 5 => '#,##0', 6 => '#,##0', 7 => '#,##0', 8 => '0', 10 => '0', 22 => '0', 23 => '0'],
            [9, 11, 13, 14, 17, 18, 19, 20, 21]
        );

        $rowCount = 0;
        $query = $this->reports->query($filters)->reorder();
        foreach ($query->lazyById(self::LEDGER_CHUNK_SIZE, 'credit_transactions.id', 'id') as $transaction) {
            $writer->addRow(Row::fromValuesWithStyles(
                $ledger->map($transaction),
                $this->bodyStyle(),
                $columnStyles
            ));
            $rowCount++;
        }

        $sheet->setAutoFilter(new AutoFilter(0, 1, count($headings) - 1, $rowCount + 1));

        return $rowCount;
    }

    private function writeDefinitions(Writer $writer, array $filters): void
    {
        $rows = (new CreditDataDictionarySheet($filters))->array();
        $this->writeTabularSheet(
            $writer,
            'Definitions',
            $rows,
            [26, 72, 62],
            [],
            [0, 1, 2],
            44
        );
    }

    private function writeTabularSheet(
        Writer $writer,
        string $name,
        array $rows,
        array $widths,
        array $formats = [],
        array $wrapColumns = [],
        ?float $bodyRowHeight = null
    ): void {
        $sheet = $writer->addNewSheetAndMakeItCurrent();
        $sheet->setName($name);
        $this->setWidths($sheet, $widths);
        $sheet->setSheetView((new SheetView)->setShowGridLines(false)->setFreezeRow(2));

        $headings = array_shift($rows) ?? [];
        $writer->addRow(Row::fromValues($headings, $this->headerStyle())->setHeight(28));
        $columnStyles = $this->columnStyles($formats, $wrapColumns);

        foreach ($rows as $values) {
            $row = Row::fromValuesWithStyles($values, $this->bodyStyle(), $columnStyles);
            if ($bodyRowHeight) {
                $row->setHeight($bodyRowHeight);
            }
            $writer->addRow($row);
        }

        $sheet->setAutoFilter(new AutoFilter(0, 1, max(0, count($headings) - 1), count($rows) + 1));
    }

    private function columnStyles(array $formats, array $wrapColumns): array
    {
        $styles = [];
        foreach (array_unique([...array_keys($formats), ...$wrapColumns]) as $column) {
            $style = new Style;
            if (isset($formats[$column])) {
                $style->setFormat($formats[$column]);
            }
            if (in_array($column, $wrapColumns, true)) {
                $style->setShouldWrapText();
            }
            $styles[$column] = $style;
        }

        return $styles;
    }

    private function setWidths(Sheet $sheet, array $widths): void
    {
        foreach ($widths as $index => $width) {
            $sheet->setColumnWidth($width, $index + 1);
        }
    }

    private function titleStyle(): Style
    {
        return (new Style)
            ->setFontName('Aptos Display')
            ->setFontSize(18)
            ->setFontBold()
            ->setFontColor(Color::WHITE)
            ->setBackgroundColor('163A2B')
            ->setCellVerticalAlignment(CellVerticalAlignment::CENTER);
    }

    private function subtitleStyle(): Style
    {
        return (new Style)
            ->setFontName('Aptos')
            ->setFontSize(10)
            ->setFontItalic()
            ->setFontColor('DDE9E2')
            ->setBackgroundColor('163A2B');
    }

    private function sectionStyle(): Style
    {
        return (new Style)
            ->setFontName('Aptos')
            ->setFontSize(10)
            ->setFontBold()
            ->setFontColor('163A2B')
            ->setBackgroundColor('E6EFE9')
            ->setBorder(new Border(new BorderPart(Border::BOTTOM, 'D59B3B', Border::WIDTH_MEDIUM)));
    }

    private function headerStyle(): Style
    {
        return (new Style)
            ->setFontName('Aptos')
            ->setFontSize(10)
            ->setFontBold()
            ->setFontColor(Color::WHITE)
            ->setBackgroundColor('163A2B')
            ->setShouldWrapText()
            ->setCellVerticalAlignment(CellVerticalAlignment::CENTER);
    }

    private function bodyStyle(): Style
    {
        return (new Style)
            ->setFontName('Aptos')
            ->setFontSize(10)
            ->setFontColor('26352F')
            ->setCellVerticalAlignment(CellVerticalAlignment::TOP)
            ->setBorder(new Border(new BorderPart(Border::BOTTOM, 'DDE5E0', Border::WIDTH_THIN)));
    }

    private function numberStyle(int $decimals = 0): Style
    {
        return (new Style)
            ->setFormat($decimals > 0 ? '#,##0.00' : '#,##0')
            ->setCellAlignment(CellAlignment::RIGHT);
    }

    private function percentStyle(): Style
    {
        return (new Style)->setFormat('0.0%')->setCellAlignment(CellAlignment::RIGHT);
    }

    private function wrapStyle(): Style
    {
        return (new Style)->setShouldWrapText();
    }
}
