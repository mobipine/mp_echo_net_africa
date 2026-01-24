<?php

namespace App\Filament\Widgets;

use App\Exports\CountySurveySummaryExport;
use App\Models\County;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Facades\Excel;
use pxlrbt\FilamentExcel\Actions\Tables\ExportBulkAction;

class CountySurveySummaryWidget extends TableWidget
{
    protected static ?string $heading = 'County Survey Summary';

    public ?array $filters = [];
    public static function canView(): bool
    {
        // Returning false prevents the widget from being automatically displayed 
        // on the dashboard or resource pages.
        return false; 
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(
                County::query()
                    ->when(!empty($this->filters['county_id']), function ($q) {
                        // Only show selected county
                        $q->where('id', $this->filters['county_id']);
                    })
                    ->select([
                        'counties.id',
                        'counties.name',

                        DB::raw('(SELECT COUNT(*) 
                            FROM survey_progress 
                            JOIN members ON members.id = survey_progress.member_id
                            WHERE members.county_id = counties.id' 
                            . $this->buildFilterSQL(['ignoreCounty' => true]) .
                        ') as total'),

                        DB::raw('(SELECT COUNT(*) 
                            FROM survey_progress 
                            JOIN members ON members.id = survey_progress.member_id
                            WHERE members.county_id = counties.id 
                            AND survey_progress.completed_at IS NOT NULL'
                            . $this->buildFilterSQL(['ignoreCounty' => true]) .
                        ') as completed'),

                        DB::raw('(SELECT COUNT(*) 
                            FROM survey_progress 
                            JOIN members ON members.id = survey_progress.member_id
                            WHERE members.county_id = counties.id 
                            AND survey_progress.status IN ("ACTIVE","PENDING","UPDATING_DETAILS")'
                            . $this->buildFilterSQL(['ignoreCounty' => true]) .
                        ') as ongoing'),

                        DB::raw('(SELECT COUNT(*) 
                            FROM survey_progress 
                            JOIN members ON members.id = survey_progress.member_id
                            WHERE members.county_id = counties.id 
                            AND survey_progress.status = "CANCELLED"'
                            . $this->buildFilterSQL(['ignoreCounty' => true]) .
                        ') as cancelled'),
                    ])
            )
            ->columns([
                Tables\Columns\TextColumn::make('name')->label('County'),
                Tables\Columns\TextColumn::make('total')->label('Total'),
                Tables\Columns\TextColumn::make('completed')->label('Completed'),
                Tables\Columns\TextColumn::make('ongoing')->label('Ongoing'),
                Tables\Columns\TextColumn::make('cancelled')->label('Cancelled'),
            ])
            ->bulkActions([
                ExportBulkAction::make('export_county_summary')
                ->label('Export County Summary')
                ->action(function ($data, $records) {
                    $selectedIds = $records->pluck('id')->toArray();

                    return Excel::download(
                        new CountySurveySummaryExport($this->filters, $selectedIds),
                        'county_survey_summary_' . now()->format('Y_m_d_H_i_s') . '.xlsx'
                    );
                })
                ->icon('heroicon-o-arrow-down-tray'),
            ])
            ->defaultSort('name');
    }
    protected function buildFilterSQL(array $options = []): string
    {
        $sql = '';

        // When subqueries already use members.county_id = counties.id,
        // avoid adding county filter again unless necessary.
        $ignoreCounty = $options['ignoreCounty'] ?? false;

        if (!empty($this->filters['group_id'])) {
            if (is_array($this->filters['group_id'])) {
                $groupIds = implode(',', array_map('intval', $this->filters['group_id']));
                $sql .= " AND EXISTS (SELECT 1 FROM group_member WHERE group_member.member_id = members.id AND group_member.group_id IN ({$groupIds}))";
            } else {
                $sql .= " AND EXISTS (SELECT 1 FROM group_member WHERE group_member.member_id = members.id AND group_member.group_id = " . intval($this->filters['group_id']) . ")";
            }
        }


        if (!empty($this->filters['survey_id'])) {
            $sql .= " AND survey_progress.survey_id = {$this->filters['survey_id']}";
        }

        if (!$ignoreCounty && !empty($this->filters['county_id'])) {
            $sql .= " AND members.county_id = {$this->filters['county_id']}";
        }

        return $sql;
    }
}
