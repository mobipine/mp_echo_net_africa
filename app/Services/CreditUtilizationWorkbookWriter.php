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
            $analysis = $this->writeMemberResponses(
                $writer,
                $sheets['members'],
                (int) $scope['survey']->id,
                (int) $scope['group']->id,
                $scope['questions'],
            );

            $creditSummary = $this->creditReports->summary($scope['credit_filters']);
            $dailyCredits = $this->creditReports->dailyBreakdown($scope['credit_filters']);

            $this->writeOverview($writer, $sheets['overview'], $scope, $analysis['stats'], $creditSummary, $requestedBy);
            $this->writeParticipationFunnel($writer, $sheets['funnel'], $analysis['stats']);
            $this->writeQuestionPerformance($writer, $sheets['questions'], $analysis);
            $this->writeCreditDailyTrend($writer, $sheets['credits'], $dailyCredits);
        } finally {
            $writer->close();
        }

        return $analysis['stats']['group_members'];
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

        $credits = $writer->addNewSheetAndMakeItCurrent();
        $credits->setName('Credit Daily Trend');

        return compact('overview', 'funnel', 'questions', 'members', 'credits');
    }

    private function writeMemberResponses(
        Writer $writer,
        Sheet $sheet,
        int $surveyId,
        int $groupId,
        Collection $questions,
    ): array {
        $writer->setCurrentSheet($sheet);
        $headings = [
            'Member ID',
            'Member',
            'Phone',
            'Email',
            'National ID',
            'Gender',
            'County',
            'Survey Outcome',
            'Progress Status',
            'Completion',
            'Questions Answered',
            'Current / Drop-off Question',
            'Reminders',
            'Dispatched At',
            'First Response',
            'Last Response',
            'Completed At',
            ...$questions->map(fn (array $question): string => 'Q'.$question['position'].': '.$question['question'])->all(),
        ];
        $widths = [12, 28, 18, 30, 18, 14, 20, 22, 18, 14, 14, 42, 12, 21, 21, 21, 21];
        $this->setWidths($sheet, [...$widths, ...array_fill(0, $questions->count(), 36)]);
        $sheet->setSheetView((new SheetView)
            ->setShowGridLines(false)
            ->setFreezeRow(2)
            ->setFreezeColumn('H'));
        $writer->addRow(Row::fromValues($headings, $this->headerStyle())->setHeight(38));

        $lastColumn = count($headings) - 1;
        $wrapColumns = [1, 3, 7, 8, 11];
        if ($lastColumn >= 17) {
            $wrapColumns = [...$wrapColumns, ...range(17, $lastColumn)];
        }
        $columnStyles = $this->columnStyles(
            [0 => '0', 9 => '0.0%', 10 => '0', 12 => '0'],
            $wrapColumns
        );

        $analysis = $this->surveyReports->streamMemberResponses(
            $surveyId,
            $groupId,
            $questions,
            function (array $row) use ($writer, $questions, $columnStyles): void {
                $member = $row['member'];
                $progress = $row['progress'];
                $answers = $row['answers'];
                $values = [
                    $member->id,
                    $member->name,
                    $member->phone,
                    $member->email,
                    $member->national_id,
                    $member->gender,
                    $member->county?->name,
                    $row['status'],
                    $progress?->status,
                    $row['completion_rate'],
                    $row['answered_count'],
                    $row['current_question'],
                    $progress?->number_of_reminders ?? 0,
                    $this->dateTime($progress?->last_dispatched_at ?? $progress?->created_at),
                    $this->dateTime($row['first_response_at']),
                    $this->dateTime($row['last_response_at']),
                    $this->dateTime($progress?->completed_at),
                    ...$questions->map(fn (array $question) => $answers[$question['id']]['value'] ?? null)->all(),
                ];

                $writer->addRow(Row::fromValuesWithStyles($values, $this->bodyStyle(), $columnStyles));
            }
        );

        $sheet->setAutoFilter(new AutoFilter(0, 1, $lastColumn, $analysis['stats']['group_members'] + 1));

        return $analysis;
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
            ['Selected group members', $memberTotal, $this->ratio($memberTotal, $memberTotal), null, 'Every member included in the Member Responses sheet'],
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

    private function writeCreditDailyTrend(Writer $writer, Sheet $sheet, Collection $dailyCredits): void
    {
        $rows = [['Date', 'Credits utilized', 'Outbound SMS', 'Inbound SMS', 'Transactions']];

        foreach ($dailyCredits as $day) {
            $rows[] = [
                data_get($day, 'usage_date'),
                (int) data_get($day, 'credits_used'),
                (int) data_get($day, 'credits_sent'),
                (int) data_get($day, 'credits_received'),
                (int) data_get($day, 'transaction_count'),
            ];
        }

        $this->writeTabularSheet(
            $writer,
            $sheet,
            $rows,
            [16, 20, 18, 18, 18],
            [1 => '#,##0', 2 => '#,##0', 3 => '#,##0', 4 => '#,##0']
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

    private function dateTime($value): ?string
    {
        if (! $value) {
            return null;
        }

        return method_exists($value, 'format') ? $value->format('Y-m-d H:i:s') : (string) $value;
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
