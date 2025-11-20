<?php
namespace App\Filament\Resources\SmsReportResource\Widgets;

use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class SentSmsStatsOverview extends BaseWidget
{
    // 💡 Listen for the event dispatched from the page to trigger a refresh
    protected $listeners = ['widget-updated' => '$refresh']; 

    // Public property to hold the filters passed from the page
    public ?array $filters = [];

    protected function getStats(): array
    {
        // 1. Parse dates received from the filter array
        $startDate = $this->filters['start_date'] ? Carbon::parse($this->filters['start_date']) : null;
        $endDate = $this->filters['end_date'] ? Carbon::parse($this->filters['end_date']) : null;

        // 2. Base Query
        $query = DB::table('sms_inboxes')
                    ->where('phone_number', '!=',null);

        // 3. Apply Date Filtering Logic
        if ($startDate && $endDate) {
            // Filter between the two dates (inclusive of the whole day)
            $query->whereDate('created_at', '>=', $startDate->startOfDay())
                  ->whereDate('created_at', '<=', $endDate->endOfDay());

        } elseif ($startDate) {
            // Only start date is set: filter from that day forward
            $query->whereDate('created_at', '>=', $startDate->startOfDay());

        } elseif ($endDate) {
            // Only end date is set: filter up to that day
            $query->whereDate('created_at', '<=', $endDate->endOfDay());

        } else {
            // 💡 Crucial for initial load: If no filters are set, default to today
            $query->whereDate('created_at', '=', Carbon::today());
        }

        // 4. Run the aggregation using conditional counting (CASE WHEN)
        $results = $query->select(
            DB::raw("COUNT(CASE WHEN delivery_status = 'Delivered' THEN 1 END) AS DeliveryCount"),
            DB::raw("COUNT(CASE WHEN delivery_status = 'Failed' THEN 1 END) AS FailedDeliveryCount"),
            DB::raw("COUNT(CASE WHEN delivery_status = 'pending' THEN 1 END) AS PendingDeliveryCount"),
            DB::raw("COUNT(CASE WHEN status = 'sent' THEN 1 END) AS SentCount"),
            DB::raw("COUNT(CASE WHEN status = 'Failed' THEN 1 END) AS FailedCount"),
            DB::raw("COUNT(CASE WHEN status = 'pending' THEN 1 END) AS PendingCount"),
            DB::raw("COUNT(CASE WHEN delivery_status = 'pending' AND status = 'sent' THEN 1 END) AS PendingDeliveryWhileSentCount")
        )->first();

        // 5. Return the Stat components
        return [
            Stat::make('Successfully Delivered Messages', number_format($results->DeliveryCount ?? 0)),
            Stat::make('Delivery Failed Messages', number_format($results->FailedDeliveryCount ?? 0)),
            Stat::make('Pending Delivery of Messages marked as sent on the system', number_format($results->PendingDeliveryWhileSentCount ?? 0)),

            Stat::make('Successfully Sent Messages', number_format($results->SentCount ?? 0)),
            Stat::make('Sending Failed Messages', number_format($results->FailedCount ?? 0)),
            Stat::make('On Sending Queue Messages (Pending)', number_format($results->PendingCount ?? 0)),
        ];
    }
}