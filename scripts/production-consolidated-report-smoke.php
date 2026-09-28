<?php

declare(strict_types=1);

use App\Services\CreditUtilizationWorkbookWriter;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Storage;

require dirname(__DIR__).'/vendor/autoload.php';

$app = require dirname(__DIR__).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$disk = Storage::disk('local');
$relativePath = 'private/consolidated-report-smoke-'.date('YmdHis').'-'.getmypid().'.xlsx';
$disk->makeDirectory(dirname($relativePath));
$fullPath = $disk->path($relativePath);

$countXmlRows = static function (ZipArchive $zip, string $entry): int {
    $xml = $zip->getFromName($entry);
    if ($xml === false) {
        throw new RuntimeException("Workbook is missing {$entry}.");
    }

    $reader = new XMLReader;
    if (! $reader->XML($xml, null, LIBXML_NONET | LIBXML_COMPACT)) {
        throw new RuntimeException("Workbook sheet {$entry} is not valid XML.");
    }

    $rows = 0;
    while ($reader->read()) {
        if ($reader->nodeType === XMLReader::ELEMENT && $reader->localName === 'row') {
            $rows++;
        }
    }
    $reader->close();

    return $rows;
};

try {
    $responseCount = app(CreditUtilizationWorkbookWriter::class)->write(
        ['report_mode' => 'consolidated'],
        'Production smoke test',
        $fullPath,
    );

    if (! $disk->exists($relativePath) || $disk->size($relativePath) < 1) {
        throw new RuntimeException('The production workbook was not created or is empty.');
    }

    $zip = new ZipArchive;
    if ($zip->open($fullPath, ZipArchive::CHECKCONS) !== true) {
        throw new RuntimeException('The production workbook is not a valid ZIP/XLSX archive.');
    }

    try {
        for ($index = 0; $index < $zip->numFiles; $index++) {
            if ($zip->getFromIndex($index) === false) {
                throw new RuntimeException('The production workbook archive failed its integrity check.');
            }
        }

        $workbookXml = $zip->getFromName('xl/workbook.xml');
        if ($workbookXml === false) {
            throw new RuntimeException('The workbook metadata is missing.');
        }

        $reader = new XMLReader;
        if (! $reader->XML($workbookXml, null, LIBXML_NONET | LIBXML_COMPACT)) {
            throw new RuntimeException('The workbook metadata is not valid XML.');
        }

        $sheetNames = [];
        while ($reader->read()) {
            if ($reader->nodeType === XMLReader::ELEMENT && $reader->localName === 'sheet') {
                $sheetNames[] = html_entity_decode((string) $reader->getAttribute('name'), ENT_QUOTES | ENT_XML1, 'UTF-8');
            }
        }
        $reader->close();

        $expectedSheetNames = [
            'Read Me & Scope',
            'Executive Summary',
            'Survey Summary',
            'Group x Survey',
            'Group Coverage',
            'Question Performance',
            'Participant Survey Summary',
            'Participant Responses',
        ];
        if ($sheetNames !== $expectedSheetNames) {
            throw new RuntimeException('The production workbook sheet names or order do not match the expected layout.');
        }

        $surveyRows = $countXmlRows($zip, 'xl/worksheets/sheet3.xml') - 1;
        $groupCoverageRows = $countXmlRows($zip, 'xl/worksheets/sheet5.xml') - 1;
        $participantSurveyRows = $countXmlRows($zip, 'xl/worksheets/sheet7.xml') - 1;
        $participantResponseRows = $countXmlRows($zip, 'xl/worksheets/sheet8.xml') - 1;

        if ($surveyRows < 1 || $groupCoverageRows < 1 || $participantSurveyRows < 1 || $participantResponseRows < 1) {
            throw new RuntimeException('One or more production data sheets contain no populated rows.');
        }
        if ($participantResponseRows !== $responseCount) {
            throw new RuntimeException("Response row mismatch: writer returned {$responseCount}, workbook contains {$participantResponseRows}.");
        }
    } finally {
        $zip->close();
    }

    echo json_encode([
        'status' => 'passed',
        'sheets' => $sheetNames,
        'survey_rows' => $surveyRows,
        'group_coverage_rows' => $groupCoverageRows,
        'participant_survey_rows' => $participantSurveyRows,
        'participant_response_rows' => $participantResponseRows,
        'writer_response_count' => $responseCount,
        'file_size_bytes' => $disk->size($relativePath),
        'archive_integrity' => 'passed',
    ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL;
} finally {
    $disk->delete($relativePath);
}
