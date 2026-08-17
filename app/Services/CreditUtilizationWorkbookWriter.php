<?php

namespace App\Services;

use Illuminate\Support\Collection;
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
    public function __construct(
        private readonly CreditUtilizationReportService $creditReports,
        private readonly ComprehensiveSurveyReportService $surveyReports,
    ) {}

    public function write(array $filters, string $requestedBy, string $outputPath): int
    {
        $scope = $this->surveyReports->scope($filters);
        $options = new Options;

        foreach ([1, 2, 8, 20] as $row) {
            $options->mergeCells(0, $row, 5, $row);
        }

        $writer = new Writer($options);
        $writer->setCreator('Echo Net Africa');
        $writer->openToFile($outputPath);

        try {
            $sheets = $this->createSheets($writer);
            $analysis = $this->surveyReports->streamMemberResponses(
                (int) $scope['survey']->id,
                (int) $scope['group']->id,
                $scope['questions'],
            );
            $memberRows = $this->writeMemberResponses(
                $writer,
                $sheets['members'],
                (int) $scope['survey']->id,
                (int) $scope['group']->id,
                $scope['response_questions'],
            );

            $creditSummary = $this->creditReports->summary($scope['credit_filters']);

            $this->writeOverview($writer, $sheets['overview'], $scope, $analysis['stats'], $creditSummary, $requestedBy);
            $this->writeParticipationFunnel($writer, $sheets['funnel'], $analysis['stats']);
            $this->writeQuestionPerformance($writer, $sheets['questions'], $analysis);
        } finally {
            $writer->close();
        }

        return $memberRows;
    }

    private function createSheets(Writer $writer): array
    {
        $overview = $writer->getCurrentSheet();
        $overview->setName('Survey Overview');

        $funnel = $writer->addNewSheetAndMakeItCurrent();
        $funnel->setName('Participation Funnel');

        $questions = $writer->addNewSheetAndMakeItCurrent();
        $questions->setName('Question Performance');

        $members = $writer->addNewSheetAndMakeItCurrent();
        $members->setName('Member Responses');

        return compact('overview', 'funnel', 'questions', 'members');
    }

    private function writeMemberResponses(
        Writer $writer,
        Sheet $sheet,
        int $surveyId,
        int $groupId,
        Collection $questions,
    ): int {
        $writer->setCurrentSheet($sheet);
        $headings = [
            'Name',
            'Email',
            'Phone Number',
            'National ID',
            'Gender',
            'Date of Birth',
            'Marital Status',
            'County Name',
            ...$questions->pluck('question')->all(),
        ];
        $sheet->setSheetView((new SheetView)->setFreezeRow(2));
        $writer->addRow(Row::fromValues($headings, $this->legacyHeaderStyle())->setHeight(20));

        $maxLengths = array_map(fn ($heading): int => $this->displayLength($heading), $headings);
        $bodyStyle = $this->legacyBodyStyle();
        $alternateBodyStyle = $this->legacyBodyStyle(true);
        $dataRow = 0;

        $writtenRows = $this->surveyReports->streamLegacyMemberResponses(
            $surveyId,
            $groupId,
            $questions,
            function (array $values) use (
                $writer,
                $bodyStyle,
                $alternateBodyStyle,
                &$dataRow,
                &$maxLengths
            ): void {
                foreach ($values as $column => $value) {
                    $maxLengths[$column] = max($maxLengths[$column], $this->displayLength($value));
                }

                $style = $dataRow % 2 === 0 ? $alternateBodyStyle : $bodyStyle;
                $writer->addRow(Row::fromValues($values, $style));
                $dataRow++;
            }
        );

        foreach ($maxLengths as $column => $length) {
            $sheet->setColumnWidth(min(255, max(10, $length + 2)), $column + 1);
        }

        return $writtenRows;
    }

    private function writeOverview(
        Writer $writer,
        Sheet $sheet,
        array $scope,
        array $participation,
        array $credits,
        string $requestedBy,
    ): void {
        $writer->setCurrentSheet($sheet);
        $this->setWidths($sheet, [34, 20, 20, 22, 60, 3]);
        $sheet->setSheetView((new SheetView)->setShowGridLines(false)->setFreezeRow(9));

        $memberTotal = $participation['group_members'];
        $dispatched = $participation['dispatched'];
        $rows = [
            ['ECHO NET AFRICA | COMPREHENSIVE SURVEY REPORT'],
            ['Participation, responses, drop-offs, and SMS credit utilization in one audit-ready workbook'],
            ['Survey', $scope['survey']->title, null, null, 'Survey ID: '.$scope['survey']->id],
            ['Group', $scope['group']->name, null, null, 'Group ID: '.$scope['group']->id],
            ['Requested by', $requestedBy],
            ['Generated at', now()->format('Y-m-d H:i:s T')],
            [],
            ['SURVEY PARTICIPATION'],
            ['Metric', 'Count', '% of group', '% dispatched', 'What it shows'],
            ['Selected group members', $memberTotal, $this->ratio($memberTotal, $memberTotal), null, 'Full selected group; Member Responses retains the legacy dispatched-member format'],
            ['Survey dispatched', $dispatched, $this->ratio($dispatched, $memberTotal), $this->ratio($dispatched, $dispatched), 'Members with a survey progress record'],
            ['Responded', $participation['responded'], $this->ratio($participation['responded'], $memberTotal), $this->ratio($participation['responded'], $dispatched), 'Members with at least one answer or a recorded response'],
            ['Completed', $participation['completed'], $this->ratio($participation['completed'], $memberTotal), $this->ratio($participation['completed'], $dispatched), 'Members who reached a completed survey state'],
            ['In progress', $participation['in_progress'], $this->ratio($participation['in_progress'], $memberTotal), $this->ratio($participation['in_progress'], $dispatched), 'Responding members whose survey remains open'],
            ['Dropped / cancelled', $participation['dropped'], $this->ratio($participation['dropped'], $memberTotal), $this->ratio($participation['dropped'], $dispatched), 'Members whose survey ended before completion'],
            ['Dispatched, no response', $participation['no_response'], $this->ratio($participation['no_response'], $memberTotal), $this->ratio($participation['no_response'], $dispatched), 'Survey sent but no response recorded'],
            ['Not dispatched', $participation['not_dispatched'], $this->ratio($participation['not_dispatched'], $memberTotal), null, 'Group members without a survey progress record'],
            ['Question answers captured', $participation['total_answers'], null, null, 'Total non-duplicated member-question answers in this report'],
            [],
            ['SMS CREDIT UTILIZATION'],
            ['Metric', 'Credits', '% of utilized', 'Transactions', 'Scope'],
            ['Total credits utilized', $credits['credits_used'], $this->ratio($credits['credits_used'], $credits['credits_used']), $credits['transaction_count'], 'All available dates; selected survey and group'],
            ['Outbound SMS', $credits['credits_sent'], $this->ratio($credits['credits_sent'], $credits['credits_used']), null, 'Credits utilized by outbound SMS'],
            ['Inbound SMS', $credits['credits_received'], $this->ratio($credits['credits_received'], $credits['credits_used']), null, 'Credits utilized by inbound SMS'],
            ['Observed activity days', $credits['observed_days'], null, null, 'Distinct dates with matching SMS credit activity'],
            ['Average daily utilization', $credits['average_daily_usage'], null, null, 'Total utilized credits divided by observed activity days'],
        ];

        foreach ($rows as $index => $values) {
            $rowNumber = $index + 1;
            $style = match (true) {
                $rowNumber === 1 => $this->titleStyle(),
                $rowNumber === 2 => $this->subtitleStyle(),
                in_array($rowNumber, [8, 20], true) => $this->sectionStyle(),
                in_array($rowNumber, [9, 21], true) => $this->headerStyle(),
                default => $this->bodyStyle(),
            };
            $columnStyles = [];

            if ($rowNumber >= 10 && $rowNumber <= 18) {
                $columnStyles = [1 => $this->numberStyle(), 2 => $this->percentStyle(), 3 => $this->percentStyle(), 4 => $this->wrapStyle()];
            } elseif ($rowNumber >= 22 && $rowNumber <= 25) {
                $columnStyles = [1 => $this->numberStyle(), 2 => $this->percentStyle(), 3 => $this->numberStyle(), 4 => $this->wrapStyle()];
            } elseif ($rowNumber === 26) {
                $columnStyles = [1 => $this->numberStyle(2), 4 => $this->wrapStyle()];
            }

            $row = Row::fromValuesWithStyles($values, $style, $columnStyles);
            if (in_array($rowNumber, [1, 2], true)) {
                $row->setHeight($rowNumber === 1 ? 34 : 24);
            }
            $writer->addRow($row);
        }
    }

    private function writeParticipationFunnel(Writer $writer, Sheet $sheet, array $stats): void
    {
        $groupMembers = $stats['group_members'];
        $dispatched = $stats['dispatched'];
        $rows = [
            ['Stage', 'Members', '% of group', '% dispatched', 'Interpretation'],
            ['Selected group members', $groupMembers, $this->ratio($groupMembers, $groupMembers), null, 'Full report population'],
            ['Survey dispatched', $dispatched, $this->ratio($dispatched, $groupMembers), $this->ratio($dispatched, $dispatched), 'Reached the survey workflow'],
            ['At least one response', $stats['responded'], $this->ratio($stats['responded'], $groupMembers), $this->ratio($stats['responded'], $dispatched), 'Engaged with at least one question'],
            ['Completed survey', $stats['completed'], $this->ratio($stats['completed'], $groupMembers), $this->ratio($stats['completed'], $dispatched), 'Reached completion'],
            ['Active / in progress', $stats['in_progress'], $this->ratio($stats['in_progress'], $groupMembers), $this->ratio($stats['in_progress'], $dispatched), 'Started and can still continue'],
            ['Dropped / cancelled', $stats['dropped'], $this->ratio($stats['dropped'], $groupMembers), $this->ratio($stats['dropped'], $dispatched), 'Ended before completion'],
            ['Dispatched, no response', $stats['no_response'], $this->ratio($stats['no_response'], $groupMembers), $this->ratio($stats['no_response'], $dispatched), 'No engagement after dispatch'],
            ['Not dispatched', $stats['not_dispatched'], $this->ratio($stats['not_dispatched'], $groupMembers), null, 'Member belongs to the group but has no dispatch record'],
        ];

        $this->writeTabularSheet(
            $writer,
            $sheet,
            $rows,
            [30, 16, 17, 17, 58],
            [1 => '#,##0', 2 => '0.0%', 3 => '0.0%'],
            [0, 4]
        );
    }

    private function writeQuestionPerformance(Writer $writer, Sheet $sheet, array $analysis): void
    {
        $memberTotal = $analysis['stats']['group_members'];
        $rows = [[
            'Position',
            'Question',
            'Respondents',
            'Response rate (% group)',
            'Active at question',
            'Current drop-offs',
            'Most common answer',
            'Answer count',
            'First response',
            'Last response',
        ]];

        foreach ($analysis['questions'] as $question) {
            $answerCounts = collect($question['answer_counts'])->sortDesc();
            $rows[] = [
                $question['position'],
                $question['question'],
                $question['respondents'],
                $this->ratio($question['respondents'], $memberTotal),
                $question['active_at_question'],
                $question['drop_offs'],
                $answerCounts->keys()->first(),
                $answerCounts->first() ?? 0,
                $question['first_response_at'],
                $question['last_response_at'],
            ];
        }

        $this->writeTabularSheet(
            $writer,
            $sheet,
            $rows,
            [12, 58, 16, 17, 20, 20, 34, 16, 21, 21],
            [0 => '0', 2 => '#,##0', 3 => '0.0%', 4 => '#,##0', 5 => '#,##0', 7 => '#,##0'],
            [1, 6]
        );
    }

    private function writeTabularSheet(
        Writer $writer,
        Sheet $sheet,
        array $rows,
        array $widths,
        array $formats = [],
        array $wrapColumns = [],
    ): void {
        $writer->setCurrentSheet($sheet);
        $this->setWidths($sheet, $widths);
        $sheet->setSheetView((new SheetView)->setShowGridLines(false)->setFreezeRow(2));

        $headings = array_shift($rows) ?? [];
        $writer->addRow(Row::fromValues($headings, $this->headerStyle())->setHeight(30));
        $columnStyles = $this->columnStyles($formats, $wrapColumns);

        foreach ($rows as $values) {
            $writer->addRow(Row::fromValuesWithStyles($values, $this->bodyStyle(), $columnStyles));
        }

        $sheet->setAutoFilter(new AutoFilter(0, 1, max(0, count($headings) - 1), count($rows) + 1));
    }

    private function ratio(int|float $value, int|float $total): ?float
    {
        return $total > 0 ? $value / $total : null;
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

    private function displayLength(mixed $value): int
    {
        return collect(preg_split('/\R/u', (string) $value) ?: [''])
            ->map(fn (string $line): int => mb_strlen($line))
            ->max() ?? 0;
    }

    private function allBorders(string $color): Border
    {
        return new Border(
            new BorderPart(Border::LEFT, $color, Border::WIDTH_THIN),
            new BorderPart(Border::RIGHT, $color, Border::WIDTH_THIN),
            new BorderPart(Border::TOP, $color, Border::WIDTH_THIN),
            new BorderPart(Border::BOTTOM, $color, Border::WIDTH_THIN),
        );
    }

    private function legacyHeaderStyle(): Style
    {
        return (new Style)
            ->setFontName('Calibri')
            ->setFontSize(11)
            ->setFontBold()
            ->setFontColor(Color::WHITE)
            ->setBackgroundColor('4472C4')
            ->setCellAlignment(CellAlignment::CENTER)
            ->setCellVerticalAlignment(CellVerticalAlignment::CENTER)
            ->setBorder($this->allBorders('000000'));
    }

    private function legacyBodyStyle(bool $alternate = false): Style
    {
        $style = (new Style)
            ->setFontName('Calibri')
            ->setFontSize(11)
            ->setCellVerticalAlignment(CellVerticalAlignment::CENTER)
            ->setBorder($this->allBorders('CCCCCC'));

        if ($alternate) {
            $style->setBackgroundColor('F2F2F2');
        }

        return $style;
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
