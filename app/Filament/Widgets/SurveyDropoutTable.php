<?php

namespace App\Filament\Widgets;

use App\Models\SurveyProgress;
use App\Filament\Pages\SurveyReports;
use Filament\Tables;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class SurveyDropoutTable extends TableWidget
{
    protected static ?string $heading = 'Survey Dropout Table (Incomplete)';
    protected static ?string $page = SurveyReports::class;
    public ?array $filters = [];

    public static function canView(): bool
    {
        // Returning false prevents the widget from being automatically displayed 
        // on the dashboard or resource pages.
        return false; 
    }

    protected function getTableQuery(): Builder
    {
        $groupIds = $this->filters['group_id'] ?? null;
        $surveyId = $this->filters['survey_id'] ?? null;

        $query = SurveyProgress::query()
            ->select(
                'current_question_id',
                DB::raw('COUNT(*) as total_stoppages'),
                DB::raw('MAX(id) as id')
            )
            ->whereNull('completed_at')
            ->whereIn('status', ['ACTIVE', 'PENDING', 'UPDATING_DETAILS'])
            ->groupBy('current_question_id')
            ->orderByDesc('total_stoppages');

        if (!empty($surveyId)) {
            $query->where('survey_id', $surveyId);
        }

        if (!empty($groupIds)) {
            $groupIds = is_array($groupIds) ? $groupIds : [$groupIds];
            $query->whereHas('member', function ($q) use ($groupIds) {
                $q->whereIn('group_id', $groupIds);
            });
        }

        return $query;
    }

    protected function getTableColumns(): array
    {
        return [
            Tables\Columns\TextColumn::make('currentQuestion.question')
                ->label('Question')
                ->wrap()
                ->formatStateUsing(fn($state) => $state ?: 'Not Started / Error'),

            Tables\Columns\TextColumn::make('total_stoppages')
                ->label('Members Stopped')
                ->sortable(),
        ];
    }
}
