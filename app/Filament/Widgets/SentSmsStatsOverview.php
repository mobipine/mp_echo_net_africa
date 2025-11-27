<?php
namespace App\Filament\Widgets;

use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\DB;

class SentSmsStatsOverview extends BaseWidget
{
    // protected $listeners = ['widget-updated' => '$refresh';];
    public ?array $filters = [];

    public static function canView(): bool
    {
        return false;
    }

    protected function getStats(): array
    {
        $query = DB::table('sms_inboxes')
            ->whereNotNull('phone_number');

        $results = $query->select(
            DB::raw("COUNT(CASE WHEN delivery_status = 'Delivered' THEN 1 END) AS DeliveryCount"),
            DB::raw("COUNT(CASE WHEN delivery_status = 'Failed' THEN 1 END) AS FailedDeliveryCount"),
            DB::raw("COUNT(CASE WHEN delivery_status = 'pending' THEN 1 END) AS PendingDeliveryCount"),
            DB::raw("COUNT(CASE WHEN status = 'sent' THEN 1 END) AS SentCount"),
            DB::raw("COUNT(CASE WHEN status = 'Failed' THEN 1 END) AS FailedCount"),
            DB::raw("COUNT(CASE WHEN status = 'pending' THEN 1 END) AS PendingCount"),
            DB::raw("COUNT(CASE WHEN delivery_status = 'pending' AND status = 'sent' THEN 1 END) AS PendingDeliveryWhileSentCount"),
        )->first();

        $stats = [
            Stat::make('Successfully Delivered Messages', number_format($results->DeliveryCount ?? 0))
                ->description('Messages fully delivered')
                ->descriptionColor('success')
                ->color('success')
                ->icon('heroicon-o-check-badge'),

            Stat::make('Delivery Failed Messages', number_format($results->FailedDeliveryCount ?? 0))
                ->description('Messages that failed delivery')
                ->descriptionColor('danger')
                ->color('danger')
                ->icon('heroicon-o-x-circle'),

            Stat::make('Pending Delivery (Sent)', number_format($results->PendingDeliveryWhileSentCount ?? 0))
                ->description('Sent but still awaiting delivery')
                ->descriptionColor('warning')
                ->color('warning')
                ->icon('heroicon-o-clock'),

            Stat::make('Successfully Sent Messages', number_format($results->SentCount ?? 0))
                ->description('Messages submitted to gateway')
                ->descriptionColor('success')
                ->color('success')
                ->icon('heroicon-o-paper-airplane'),

            Stat::make('Sending Failed Messages', number_format($results->FailedCount ?? 0))
                ->description('Messages that failed at sending stage')
                ->descriptionColor('danger')
                ->color('danger')
                ->icon('heroicon-o-exclamation-triangle'),

            Stat::make('On Sending Queue (Pending)', number_format($results->PendingCount ?? 0))
                ->description('Messages still in queue')
                ->descriptionColor('warning')
                ->color('warning')
                ->icon('heroicon-o-arrow-path'),
        ];

        // --- Add Delivery Status Descriptions ---
        $deliveryReasons = DB::table('sms_inboxes')
            ->select('delivery_status_desc', DB::raw('COUNT(*) AS count'))
            ->whereNotNull('delivery_status_desc')
            ->groupBy('delivery_status_desc')
            ->orderByDesc('count')
            ->limit(5)
            ->get();

        foreach ($deliveryReasons as $reason) {
            $stats[] = Stat::make($reason->delivery_status_desc, $reason->count)
                ->description('Delivery Status Reason')
                ->descriptionColor('danger')
                ->color('danger')
                ->icon('heroicon-o-exclamation-triangle');
        }

        // --- Add Sending Failure Reasons ---
        $failureReasons = DB::table('sms_inboxes')
            ->select('failure_reason', DB::raw('COUNT(*) AS count'))
            ->whereNotNull('failure_reason')
            ->groupBy('failure_reason')
            ->orderByDesc('count')
            ->limit(5)
            ->get();

        foreach ($failureReasons as $reason) {
            $stats[] = Stat::make($reason->failure_reason, $reason->count)
                ->description('Sending Failure Reason')
                ->descriptionColor('danger')
                ->color('danger')
                ->icon('heroicon-o-x-circle');
        }

        return $stats;
    }
}
