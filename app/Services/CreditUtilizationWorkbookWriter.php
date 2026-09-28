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
        private readonly ConsolidatedSurveyReportDataService $consolidatedData,
    ) {}

    public function write(array $filters, string $requestedBy, string $outputPath): int
    {
        $scope = $this->surveyReports->scope($filters);

        if ($scope['is_consolidated']) {
            return $this->writeConsolidated($scope, $requestedBy, $outputPath);
        }

        $options = new Options;

        foreach ([1, 2, 8, 20] as $row) {
            $options->mergeCells(0, $row, 5, $row);
        }

        $writer = new Writer($options);
        $writer->setCreator('Echo Net Africa');
        $writer->openToFile($outputPath);

        try {
            $sheets = $this->createSheets($writer);

            $surveyId = $scope['survey']?->id;
            $groupId = $scope['group']?->id;
            $isConsolidated = $scope['is_consolidated'] ?? false;

            $analysis = $this->surveyReports->streamMemberResponses(
                $surveyId,
                $groupId,
                $scope['questions'],
            );

            $memberRows = $this->writeMemberResponses(
                $writer,
                $sheets['members'],
                $surveyId,
                $groupId,
                $scope['response_questions'],
                $isConsolidated,
            );

            $creditSummary = $this->creditReports->summary($scope['credit_filters']);

            $this->writeOverview($writer, $sheets['overview'], $scope, $analysis['stats'], $creditSummary, $requestedBy);
            $this->writeParticipationFunnel($writer, $sheets['funnel'], $analysis['stats']);
            $this->writeQuestionPerformance($writer, $sheets['questions'], $analysis, $isConsolidated);
        } finally {
            $writer->close();
        }

        return $memberRows;
    }

    private function writeConsolidated(array $scope, string $requestedBy, string $outputPath): int
    {
        $writer = new Writer(new Options);
        $writer->setCreator('Echo Net Africa');
        $writer->openToFile($outputPath);

        $readMe = $writer->getCurrentSheet();
        $readMe->setName('Read Me & Scope');
        $executive = $writer->addNewSheetAndMakeItCurrent();
        $executive->setName('Executive Summary');
        $surveys = $writer->addNewSheetAndMakeItCurrent();
        $surveys->setName('Survey Summary');
        $groupSurvey = $writer->addNewSheetAndMakeItCurrent();
        $groupSurvey->setName('Group x Survey');
        $groupCoverage = $writer->addNewSheetAndMakeItCurrent();
        $groupCoverage->setName('Group Coverage');
        $questions = $writer->addNewSheetAndMakeItCurrent();
        $questions->setName('Question Performance');
        $participantSurvey = $writer->addNewSheetAndMakeItCurrent();
        $participantSurvey->setName('Participant Survey Summary');
        $participantResponses = $writer->addNewSheetAndMakeItCurrent();
        $participantResponses->setName('Participant Responses');

        $participantSurveyHeaders = [
            'Survey', 'Survey ID', 'Survey Status', 'Group', 'Group ID', 'Group Attribution',
            'Member ID', 'Participant', 'Phone', 'Progress ID', 'Progress Status', 'Responded',
            'Response Count', 'Completed At', 'Dispatch Batch UUID',
        ];
        $participantResponseHeaders = [
            'Survey', 'Survey ID', 'Survey Status', 'Group', 'Group ID', 'Group Attribution',
            'Member ID', 'Participant', 'Phone', 'Question Position', 'Question', 'Response',
            'Responded At', 'Progress Status', 'Dispatch Batch UUID',
        ];
        $this->startStreamingSheet($writer, $participantSurvey, $participantSurveyHeaders, [28, 12, 16, 28, 12, 38, 12, 28, 18, 12, 20, 14, 16, 21, 38]);
        $this->startStreamingSheet($writer, $participantResponses, $participantResponseHeaders, [28, 12, 16, 28, 12, 38, 12, 28, 18, 16, 58, 38, 21, 20, 38]);

        try {
            $data = $this->consolidatedData->stream(
                $scope['surveys'],
                $scope['questions'],
                $scope['credit_filters'],
                function (array $values) use ($writer, $participantSurvey): void {
                    $writer->setCurrentSheet($participantSurvey);
                    $writer->addRow(Row::fromValues($values, $this->bodyStyle()));
                },
                function (array $values) use ($writer, $participantResponses): void {
                    $writer->setCurrentSheet($participantResponses);
                    $writer->addRow(Row::fromValues($values, $this->bodyStyle()));
                },
            );

            $this->writeReadMe($writer, $readMe, $scope, $data, $requestedBy);
            $this->writeExecutiveSummary($writer, $executive, $data, $requestedBy);
            $this->writeConsolidatedSurveySummary($writer, $surveys, $data['survey_rows']);
            $this->writeConsolidatedGroupSurvey($writer, $groupSurvey, $data['pair_rows']);
            $this->writeGroupCoverage($writer, $groupCoverage, $data['group_rows']);
            $this->writeConsolidatedQuestions($writer, $questions, $data['question_rows'], $data['survey_rows']);

            $this->finishStreamingSheet($participantSurvey, count($data['survey_rows']) > 0
                ? $data['participant_surveys']
                : 0, count($participantSurveyHeaders));
            $this->finishStreamingSheet($participantResponses, $data['responses'], count($participantResponseHeaders));
        } finally {
            $writer->close();
        }

        return (int) $data['responses'];
    }

    private function startStreamingSheet(Writer $writer, Sheet $sheet, array $headers, array $widths): void
    {
        $writer->setCurrentSheet($sheet);
        $this->setWidths($sheet, $widths);
        $sheet->setSheetView((new SheetView)->setShowGridLines(false)->setFreezeRow(2));
        $writer->addRow(Row::fromValues($headers, $this->headerStyle())->setHeight(30));
    }

    private function finishStreamingSheet(Sheet $sheet, int $dataRows, int $columnCount): void
    {
        $sheet->setAutoFilter(new AutoFilter(0, 1, max(0, $columnCount - 1), $dataRows + 1));
    }

    private function writeReadMe(Writer $writer, Sheet $sheet, array $scope, array $data, string $requestedBy): void
    {
        $rows = [
            ['Item', 'Details'],
            ['Purpose', 'One workbook covering all active surveys and all groups.'],
            ['Generated at', now()->format('Y-m-d H:i:s T')],
            ['Requested by', $requestedBy],
            ['Survey scope', 'All surveys marked Active at generation time; inactive surveys are excluded.'],
            ['Group scope', 'All groups; configured assignments and groups without assignments are listed.'],
            ['Survey count', $scope['surveys']->count()],
            ['Group-survey assignment count', $data['configured_group_survey_pairs']],
            ['Unique participants', $data['unique_participants']],
            ['Participants with progress', $data['participant_surveys']],
            ['Participant response rows', $data['responses']],
            ['Unattributed participant-survey records', $data['unattributed_participant_surveys']],
            ['Unattributed response rows', $data['unattributed_responses']],
            ['Group attribution', 'A group is verified only when a progress record dispatch batch matches group-survey dispatch metadata. Other records are marked as legacy/unattributed.'],
            ['Response row grain', 'One row per stored participant-survey-question response; repeated answers remain separate rows.'],
            ['Participant summary grain', 'Latest progress record per participant and survey; response history remains in Participant Responses.'],
            ['Filtering', 'Use each sheet’s header filters to analyze by survey, group, status, participant, or question.'],
        ];

        $this->writeTabularSheet($writer, $sheet, $rows, [34, 100], [], [1]);
    }

    private function writeExecutiveSummary(Writer $writer, Sheet $sheet, array $data, string $requestedBy): void
    {
        $credits = collect($data['survey_rows'])->sum(fn (array $row): int => (int) ($row['credit_summary']['survey_attributed'] ?? 0));
        $rows = [
            ['Metric', 'Value', 'Definition'],
            ['Active surveys included', count($data['survey_rows']), 'Only surveys marked Active at generation time; see Survey Summary.'],
            ['Configured group-survey assignments', $data['configured_group_survey_pairs'], 'Distinct configured group and survey pairs.'],
            ['Unique participants', $data['unique_participants'], 'Distinct member IDs across the included surveys.'],
            ['Group-survey pairs with dispatch recorded', $data['groups_with_dispatch_recorded'], 'Distinct configured group-survey pairs with a recorded dispatch.'],
            ['Queued recipient entries', $data['queued_recipients'], 'Recipient count recorded by group-survey dispatch metadata; not necessarily unique people.'],
            ['Participants with progress', $data['participant_surveys'], 'Latest progress record for each participant and survey.'],
            ['Respondents across surveys', $data['respondents'], 'Survey-level unique respondents summed across surveys; a participant can count once per survey.'],
            ['Completed participant-surveys', $data['completed'], 'Latest progress state per participant and survey.'],
            ['Stored response rows', $data['responses'], 'One row per stored response record.'],
            ['Unattributed participant-survey records', $data['unattributed_participant_surveys'], 'Progress records without a reliable group dispatch-batch match.'],
            ['Survey-attributed SMS credits', $credits, 'Credits linked to survey responses or survey progress.'],
            ['Unattributed response rows', $data['unattributed_responses'], 'Responses without a verified group dispatch-batch association.'],
            ['Requested by', $requestedBy, 'Workbook requester.'],
        ];

        $this->writeTabularSheet($writer, $sheet, $rows, [42, 22, 90], [1 => '#,##0'], [2]);
    }

    private function writeConsolidatedSurveySummary(Writer $writer, Sheet $sheet, Collection $rows): void
    {
        $headers = [
            'Survey', 'Survey ID', 'Status', 'Configured Groups', 'Dispatched Groups',
            'Participants with Progress', 'Unique Participants Observed', 'Unique Respondents', 'Completed', 'In Progress',
            'Dropped / Cancelled', 'Dispatched, No Response', 'Response Rate', 'Completion Rate',
            'Response Rows', 'Survey-Attributed Credits', 'Group-Attributed Credits', 'Unattributed Credits',
            'Unattributed Participants with Progress', 'Unattributed Response Rows',
        ];
        $dataRows = $rows->map(fn (array $row): array => [
            $row['survey_title'], $row['survey_id'], $row['survey_status'], $row['configured_group_count'],
            $row['dispatched_group_count'], $row['participant_survey_count'], $row['unique_participants'],
            $row['unique_respondents'], $row['completed_count'], $row['in_progress_count'], $row['dropped_count'], $row['no_response_count'],
            $row['response_rate'], $row['completion_rate'], $row['response_count'],
            $row['credit_summary']['survey_attributed'] ?? 0, $row['attributed_group_credits'], $row['unattributed_credits'],
            $row['unattributed_participant_surveys'], $row['unattributed_response_count'],
        ])->prepend($headers)->all();

        $this->writeTabularSheet($writer, $sheet, $dataRows, [32, 12, 16, 18, 18, 22, 22, 18, 14, 14, 18, 22, 16, 16, 16, 22, 22, 20, 28, 23], [1 => '#,##0', 3 => '#,##0', 4 => '#,##0', 5 => '#,##0', 6 => '#,##0', 7 => '#,##0', 8 => '#,##0', 9 => '#,##0', 10 => '#,##0', 11 => '#,##0', 12 => '0.0%', 13 => '0.0%', 14 => '#,##0', 15 => '#,##0', 16 => '#,##0', 17 => '#,##0', 18 => '#,##0', 19 => '#,##0'], [0]);
    }

    private function writeConsolidatedGroupSurvey(Writer $writer, Sheet $sheet, Collection $rows): void
    {
        $headers = [
            'Group', 'Group ID', 'Survey', 'Survey ID', 'Survey Status', 'Assignment State',
            'Queued Recipients', 'Skipped Recipients', 'Participants with Progress', 'Unique Respondents',
            'Completed', 'In Progress', 'Dropped / Cancelled', 'Dispatched, No Response',
            'Response Rows', 'Response Rate', 'Completion Rate', 'Attributed SMS Credits', 'Attribution',
            'Dispatched At', 'Dispatch Batch UUIDs',
        ];
        $dataRows = $rows->map(fn (array $row): array => [
            $row['group_name'], $row['group_id'], $row['survey_title'], $row['survey_id'], $row['survey_status'] ?? '',
            $row['was_dispatched'] ? 'Dispatched' : 'Not recorded as dispatched', $row['queued_recipients'],
            $row['skipped_recipients'], $row['progress_count'], $row['unique_respondents'],
            $row['completed_count'], $row['in_progress_count'], $row['dropped_count'], $row['no_response_count'],
            $row['response_count'], $row['response_rate'], $row['completion_rate'], $row['attributed_credits'], $row['attribution'],
            $row['dispatched_at']?->toDateTimeString(), implode(', ', $row['dispatch_batch_uuids']),
        ])->prepend($headers)->all();

        $this->writeTabularSheet($writer, $sheet, $dataRows, [30, 12, 32, 12, 16, 24, 18, 18, 22, 18, 14, 14, 18, 22, 16, 16, 16, 20, 42, 21, 42], [1 => '#,##0', 3 => '#,##0', 6 => '#,##0', 7 => '#,##0', 8 => '#,##0', 9 => '#,##0', 10 => '#,##0', 11 => '#,##0', 12 => '#,##0', 13 => '#,##0', 14 => '#,##0', 15 => '0.0%', 16 => '0.0%', 17 => '#,##0'], [0, 2, 18, 20]);
    }

    private function writeGroupCoverage(Writer $writer, Sheet $sheet, Collection $rows): void
    {
        $headers = ['Group', 'Group ID', 'Current Members', 'Configured Surveys', 'Dispatched Surveys', 'Participant-Survey Records', 'Coverage'];
        $dataRows = $rows->map(fn (array $row): array => [
            $row['group_name'], $row['group_id'], $row['member_count'], $row['configured_surveys'],
            $row['dispatched_surveys'], $row['participant_surveys'], $row['coverage_status'],
        ])->prepend($headers)->all();

        $this->writeTabularSheet($writer, $sheet, $dataRows, [34, 12, 18, 20, 20, 26, 42], [1 => '#,##0', 2 => '#,##0', 3 => '#,##0', 4 => '#,##0', 5 => '#,##0'], [0, 6]);
    }

    private function writeConsolidatedQuestions(Writer $writer, Sheet $sheet, Collection $rows, Collection $surveyRows): void
    {
        $participantCounts = $surveyRows->keyBy('survey_id')->map(fn (array $row): int => $row['unique_participants']);
        $headers = [
            'Survey', 'Survey ID', 'Question Position', 'Question', 'Observed Participant Denominator',
            'Unique Respondents', 'Response Rows', 'Response Rate', 'Answer Distribution (top 10)',
            'Most Common Answer', 'Most Common Answer Count', 'First Response', 'Last Response',
        ];
        $dataRows = $rows->map(fn (array $row): array => [
            $row['survey_title'], $row['survey_id'], $row['position'], $row['question'],
            $participantCounts->get($row['survey_id'], 0), $row['unique_respondents'], $row['response_count'],
            $participantCounts->get($row['survey_id'], 0) > 0
                ? $row['unique_respondents'] / $participantCounts->get($row['survey_id'])
                : null,
            $row['answer_distribution'], $row['most_common_answer'], $row['most_common_answer_count'], $row['first_response_at'], $row['last_response_at'],
        ])->prepend($headers)->all();

        $this->writeTabularSheet($writer, $sheet, $dataRows, [32, 12, 16, 64, 24, 18, 16, 16, 60, 40, 24, 22, 22], [1 => '#,##0', 2 => '#,##0', 4 => '#,##0', 5 => '#,##0', 6 => '#,##0', 7 => '0.0%', 10 => '#,##0'], [0, 3, 8, 9]);
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
        ?int $surveyId,
        ?int $groupId,
        Collection $questions,
        bool $isConsolidated = false,
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
        ];

        if ($isConsolidated) {
            $headings[] = 'Survey';
        }

        $headings = array_merge($headings, $questions->pluck('question')->all());
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
            },
            $isConsolidated
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
        $isConsolidated = $scope['is_consolidated'] ?? false;

        $surveyLabel = $scope['survey']?->title ?? 'All active surveys';
        $groupLabel = $scope['group']?->name ?? 'All groups';

        $rows = [
            ['ECHO NET AFRICA | COMPREHENSIVE SURVEY REPORT'],
            [$isConsolidated
                ? 'Consolidated report covering all active surveys and all groups'
                : 'Participation, responses, drop-offs, and SMS credit utilization in one audit-ready workbook'],
            ['Survey', $surveyLabel, null, null, $scope['survey'] ? 'Survey ID: '.$scope['survey']->id : 'Consolidated'],
            ['Group', $groupLabel, null, null, $scope['group'] ? 'Group ID: '.$scope['group']->id : 'All groups'],
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

    private function writeQuestionPerformance(Writer $writer, Sheet $sheet, array $analysis, bool $isConsolidated = false): void
    {
        $memberTotal = $analysis['stats']['group_members'];

        $headerRow = $isConsolidated
            ? ['Survey', 'Position', 'Question', 'Respondents', 'Response rate (% group)', 'Active at question', 'Current drop-offs', 'Most common answer', 'Answer count', 'First response', 'Last response']
            : ['Position', 'Question', 'Respondents', 'Response rate (% group)', 'Active at question', 'Current drop-offs', 'Most common answer', 'Answer count', 'First response', 'Last response'];

        $rows = [$headerRow];

        foreach ($analysis['questions'] as $question) {
            $answerCounts = collect($question['answer_counts'])->sortDesc();
            $row = $isConsolidated
                ? [
                    $question['survey_title'] ?? '',
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
                ]
                : [
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
            $rows[] = $row;
        }

        $widths = $isConsolidated
            ? [24, 12, 58, 16, 17, 20, 20, 34, 16, 21, 21]
            : [12, 58, 16, 17, 20, 20, 34, 16, 21, 21];

        $formats = $isConsolidated
            ? [1 => '0', 3 => '#,##0', 4 => '0.0%', 5 => '#,##0', 6 => '#,##0', 8 => '#,##0']
            : [0 => '0', 2 => '#,##0', 3 => '0.0%', 4 => '#,##0', 5 => '#,##0', 7 => '#,##0'];

        $wrapColumns = $isConsolidated ? [2, 7] : [1, 6];

        $this->writeTabularSheet($writer, $sheet, $rows, $widths, $formats, $wrapColumns);
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
