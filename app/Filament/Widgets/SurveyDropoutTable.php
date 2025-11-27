<?php

namespace App\Filament\Widgets;
use Illuminate\Support\Collection;
use App\Exports\SurveyDropoutExport;
use App\Models\SurveyProgress;
use App\Filament\Pages\SurveyReports;
use Filament\Tables;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Maatwebsite\Excel\Facades\Excel;
use pxlrbt\FilamentExcel\Actions\Tables\ExportBulkAction;
use pxlrbt\FilamentExcel\Exports\ExcelExport;
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
        $countyId = $this->filters['county_id'] ?? null;

        $query = SurveyProgress::query()
            ->select(
                'current_question_id',
                DB::raw('COUNT(*) as total_stoppages'),
                DB::raw('MAX(id) as id')
            )
            ->whereNull('completed_at')
            ->whereIn('status', ['ACTIVE', 'PENDING', 'UPDATING_DETAILS'])
            ->groupBy('current_question_id');
            

        if (!empty($surveyId)) {
            $query->where('survey_id', $surveyId);
        }

        if (!empty($groupIds)) {
            $groupIds = is_array($groupIds) ? $groupIds : [$groupIds];
            $query->whereHas('member', function ($q) use ($groupIds) {
                $q->whereIn('group_id', $groupIds);
            });
        }
        if (!empty($countyId)) {
            $query->whereHas('member', function ($q) use ($countyId) {
                $q->where('county_id', $countyId);
            });
        }
   
        Log::info($query->get());

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

protected function getTableBulkActions(): array
    {
        return [
            // Option A: download selected rows (preferred when user selects rows)
           ExportBulkAction::make('export_selected')
                ->label('Export Selected to Excel')
                ->icon('heroicon-o-document-arrow-down')
                ->action(function (array $data, Collection $records) {
                    // $records are the selected group models
                    $selectedIds = $records->pluck('id')->toArray();
                    $export = new SurveyDropoutExport($this->filters ?? [], $selectedIds);
                    return Excel::download($export, 'group_survey_summary_' . now()->format('Y_m_d_H_i_s') . '.xlsx');
                })
                ->requiresConfirmation()
                ->color('success'),

        ];
    }

}
