<?php

namespace App\Filament\Widgets;

use App\Models\SurveyQuestion;
use App\Models\SurveyResponse;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class SmsResponsesStatsOverview extends BaseWidget
{
    public ?array $filters = [];

    public static function canView(): bool
    {
        return false; // Make visible if needed
    }

    protected function getStats(): array
    {
        $surveyId = $this->filters['survey_id'] ?? null;
        $questionId = $this->filters['question_id'] ?? null;

        $stats = [];

        if (!$questionId) {
            return [
                Stat::make('Select a Question', '---')
                    ->description('Filter a multiple choice question to see results.'),
            ];
        }

        $question = SurveyQuestion::find($questionId);
        if (!$question) {
            return [
                Stat::make('Invalid Question', '---')
                    ->description('Selected question does not exist.'),
            ];
        }

        // Handle NON-Multiple Choice Questions
        if ($question->answer_strictness !== 'Multiple Choice') {

            // Base query
            $baseQuery = SurveyResponse::query()
                ->where('question_id', $questionId);

            if ($surveyId) {
                $baseQuery->where('survey_id', $surveyId);
            }

            // Total responses
            $totalResponses = (clone $baseQuery)->count();

            $stats = [];

            // Total responses stat
            $stats[] = Stat::make('Total Responses', $totalResponses)
                ->description('All responses to this question')
                ->color('primary')
                ->icon('heroicon-o-chart-bar')
                ->url(route('response.export', [
                    'survey_id' => $surveyId,
                    'question_id' => $questionId,
                ]));

            // Fetch unique answers + counts
            $counts = $baseQuery->selectRaw('survey_response, COUNT(*) as total')
                ->groupBy('survey_response')
                ->pluck('total', 'survey_response')
                ->toArray();

            // Build one widget per unique user answer
            foreach ($counts as $answerText => $count) {

                $percentage = $totalResponses > 0
                    ? round(($count / $totalResponses) * 100, 1)
                    : 0;

                // Color logic
                if ($percentage >= 50) {
                    $color = 'success';
                } elseif ($percentage >= 25) {
                    $color = 'warning';
                } else {
                    $color = 'danger';
                }

                $stats[] = Stat::make($answerText ?: '(empty answer)', $count)
                    ->description("Given by {$count} respondents ({$percentage}%)")
                    ->color($color)
                    ->icon('heroicon-o-pencil-square')
                    ->url(route('response.export', [
                        'survey_id' => $surveyId,
                        'question_id' => $questionId,
                        'answer' => $answerText,
                    ]));
            }

            return $stats;
        }


        // Already an array thanks to the model cast
        $answers = $question->possible_answers ?? [];

        // Base query
        $baseQuery = SurveyResponse::query()
            ->where('question_id', $questionId);

        if ($surveyId) {
            $baseQuery->where('survey_id', $surveyId);
        }

        // Total responses
        $totalResponses = (clone $baseQuery)->count();

        $stats[] = Stat::make('Total Responses', $totalResponses)
            ->description('All responses to this question')
            ->color('primary')
            ->icon('heroicon-o-chart-bar')
           ->url(route('response.export', [
                'survey_id' => $surveyId,
                'question_id' => $questionId,
            ]));


        // Fetch counts for all answers in one query (optimized)
        $counts = $baseQuery->selectRaw('survey_response, COUNT(*) as total')
            ->groupBy('survey_response')
            ->pluck('total', 'survey_response')
            ->toArray();

        foreach ($answers as $answerObj) {
            $answerText = $answerObj['answer'] ?? null;
            if (!$answerText) continue;

            $count = $counts[$answerText] ?? 0;
            $percentage = $totalResponses > 0 ? round(($count / $totalResponses) * 100, 1) : 0;

            // Dynamic color based on percentage
            if ($percentage >= 50) {
                $color = 'success';
            } elseif ($percentage >= 25) {
                $color = 'warning';
            } elseif ($percentage >= 0) {
                $color = 'danger';
            } else {
                $color = 'gray';
            }

            $stats[] = Stat::make($answerText, "{$count} ")
                ->description("Chosen by {$count} respondents ({$percentage}%)")
                ->color($color)
                ->icon('heroicon-o-check-circle')
                ->url(route('response.export', [
                    'survey_id' => $surveyId,
                    'question_id' => $questionId,
                    'answer' => $answerText,
                ]));
        }

        return $stats;
    }
}
