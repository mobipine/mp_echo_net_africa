<?php

namespace App\Filament\Widgets;

use App\Models\Member;
use App\Models\SurveyProgress;
use App\Filament\Pages\SurveyReports;
use App\Models\SMSInbox;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class SurveyStatsOverview extends BaseWidget
{
    // 1. Define the property to accept filters
    public ?array $filters = [];
    
    // 2. CRITICAL: Only show on this page.
    protected static ?string $page = SurveyReports::class;
    
    protected static ?int $sort = 1;
    public static function canView(): bool
    {
        // Returning false prevents the widget from being automatically displayed 
        // on the dashboard or resource pages.
        return false; 
    }

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

        $smsQuery = SMSInbox::query()->where('is_reminder', true);

        // Filter reminders by group if applicable
        if (!empty($groupIds)) {
            $smsQuery->whereHas('member', function ($query) use ($groupIds) {
                $query->whereIn('group_id', $groupIds);
            });
        }

        // Total number of reminders sent (count all)
        $remindersSent = (clone $smsQuery)->count();

        // Unique members who have been sent at least one reminder
        $membersSentReminder = (clone $smsQuery)
            ->distinct('phone_number')
            ->count('member_id');

        $repeatReminders = (clone $smsQuery)
            ->select('phone_number', 'message')
            ->groupBy('phone_number', 'message')
            ->havingRaw('COUNT(*) >= 3')
            ->get()
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

            Stat::make('Reminders Sent', $remindersSent)
                ->description('Total reminders sent to members.')
                ->descriptionIcon('heroicon-o-bell')
                ->color('info'),

            Stat::make('Members Sent Reminder', $membersSentReminder)
                ->description('Unique members who received reminders.')
                ->descriptionIcon('heroicon-o-user-group')
                ->color('primary'),
            Stat::make('Repeated Reminders (3+ Times)', $repeatReminders)
                ->description('Phone numbers that received the same reminder 3+ times.')
                ->descriptionIcon('heroicon-o-exclamation-circle')
                ->color('danger')
                // ->url(route('filament.pages.send-sms')),

        ];
    }
}
