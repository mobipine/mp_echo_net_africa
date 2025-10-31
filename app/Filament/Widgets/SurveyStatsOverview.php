<?php

namespace App\Filament\Widgets;

use App\Models\Member;
use App\Models\SurveyProgress;
use App\Filament\Pages\SurveyReports; 
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class SurveyStatsOverview extends BaseWidget
{
    // 1. Define the property to accept filters
    public ?array $filters = [];
    
    // 2. CRITICAL: Only show on this page.
    protected static ?string $page = SurveyReports::class;
    
    protected static ?int $sort = 1;

    protected function getStats(): array
    {
        // Get the filters
        $groupIds = $this->filters['group_id'] ?? null;
        $surveyId = $this->filters['survey_id'] ?? null; // ✅ Added survey filter

        // Start the base query
        $baseQuery = SurveyProgress::query();

        // ✅ Apply survey filter if selected
        if (!empty($surveyId)) {
            $baseQuery->where('survey_id', $surveyId);
        }

        // Apply group filter if any
        if (!empty($groupIds)) {
            $groupIds = is_array($groupIds) ? $groupIds : [$groupIds];

            $baseQuery->whereHas('member', function ($query) use ($groupIds) {
                $query->whereIn('group_id', $groupIds);
            });
        }
        
        // Use the filtered base query for all statistics calculations
        $totalParticipants = (clone $baseQuery)->count();
        
        $completedCount = (clone $baseQuery)
            ->whereNotNull('completed_at')
            ->where('status', 'COMPLETED')
            ->count();
            
        $inProgressCount = (clone $baseQuery)
            ->whereNull('completed_at')
            ->whereIn('status', ['ACTIVE', 'UPDATING_DETAILS', 'PENDING'])
            ->count();
        
        $cancelled = (clone $baseQuery)
            ->whereNull('completed_at')
            ->whereIn('status', ['CANCELLED'])
            ->count();
        
        $completionRate = $totalParticipants > 0 
            ? round(($completedCount / $totalParticipants) * 100, 1) 
            : 0;

        return [
            Stat::make('Total Survey Progresses', $totalParticipants)
                ->description('Total number of progress records created.'),

            Stat::make('Surveys Completed', $completedCount)
                ->description($completionRate . '% Completion Rate')
                ->descriptionIcon('heroicon-o-check-circle')
                ->color('success'),

            Stat::make('Still In Progress', $inProgressCount)
                ->description('Uncompleted Active surveys.')
                ->descriptionIcon('heroicon-o-x-circle')
                ->color('warning'),
                
            Stat::make('Cancelled Progress', $cancelled)
                ->description('Cancelled the survey progress.')
                ->descriptionIcon('heroicon-o-x-circle')
                ->color('danger'),
        ];
    }
}
