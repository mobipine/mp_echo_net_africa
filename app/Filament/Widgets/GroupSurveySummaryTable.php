<?php

namespace App\Filament\Widgets;

use App\Models\Group;
use App\Filament\Pages\SurveyReports;
use Filament\Tables;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Actions\Action;
use Maatwebsite\Excel\Facades\Excel;
use Illuminate\Support\Collection;
use App\Exports\GroupSummaryExport;
use pxlrbt\FilamentExcel\Actions\Tables\ExportBulkAction;
use pxlrbt\FilamentExcel\Exports\ExcelExport;
use App\Exports\GroupSurveySummaryExport;
class GroupSurveySummaryTable extends TableWidget
{
    public ?array $filters = [];

    protected static ?string $heading = 'Group Survey Summary';
    protected static ?string $page = SurveyReports::class;
    protected static ?int $sort = 3;

    public static function canView(): bool
    {
        // Returning false prevents the widget from being automatically displayed 
        // on the dashboard or resource pages.
        return false; 
    }

    protected function getTableQuery(): Builder
    {
        $groupQuery = Group::query();

        $groupIds = $this->filters['group_id'] ?? null;
        $surveyId = $this->filters['survey_id'] ?? null;

        if (!empty($groupIds)) {
            $groupIds = is_array($groupIds) ? $groupIds : [$groupIds];
            $groupQuery->whereIn('id', $groupIds);
        }

        $groupQuery->withCount([
            'members as total_progresses' => function ($query) use ($surveyId) {
                $query->whereHas('surveyProgresses', function ($sub) use ($surveyId) {
                    if (!empty($surveyId)) {
                        $sub->where('survey_id', $surveyId);
                    }
                });
            },
            'members as completed_progresses' => function ($query) use ($surveyId) {
                $query->whereHas('surveyProgresses', function ($sub) use ($surveyId) {
                    if (!empty($surveyId)) {
                        $sub->where('survey_id', $surveyId);
                    }
                    $sub->whereNotNull('completed_at')
                        ->where('status', 'COMPLETED');
                });
            },
            'members as ongoing_progresses' => function ($query) use ($surveyId) {
                $query->whereHas('surveyProgresses', function ($sub) use ($surveyId) {
                    if (!empty($surveyId)) {
                        $sub->where('survey_id', $surveyId);
                    }
                    $sub->whereNull('completed_at')
                        ->whereIn('status', ['ACTIVE', 'UPDATING_DETAILS', 'PENDING']);
                });
            },
            'members as cancelled_progresses' => function ($query) use ($surveyId) {
                $query->whereHas('surveyProgresses', function ($sub) use ($surveyId) {
                    if (!empty($surveyId)) {
                        $sub->where('survey_id', $surveyId);
                    }
                    $sub->where('status', 'CANCELLED');
                });
            },
        ]);

        return $groupQuery;
    }

    protected function getTableColumns(): array
    {
        return [
            TextColumn::make('name')->label('Group')->sortable()->searchable(),
            TextColumn::make('total_progresses')->label('Total')->sortable()->color('gray'),
            TextColumn::make('completed_progresses')->label('Completed')->color('success')->sortable(),
            TextColumn::make('ongoing_progresses')->label('Ongoing')->color('warning')->sortable(),
            TextColumn::make('cancelled_progresses')->label('Cancelled')->color('danger')->sortable(),
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
                    $export = new GroupSurveySummaryExport($this->filters ?? [], $selectedIds);
                    return Excel::download($export, 'group_survey_summary_' . now()->format('Y_m_d_H_i_s') . '.xlsx');
                })
                ->requiresConfirmation()
                ->color('success'),

        ];
    }


    protected function getTablePaginationPageSize(): int
    {
        return 10;
    }
}
