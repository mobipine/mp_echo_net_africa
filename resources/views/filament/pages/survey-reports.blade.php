<x-filament-panels::page>
    <div class="space-y-6">
        {{-- Filters --}}
        <div class="filament-forms">
            {{ $this->filtersForm }}
        </div>

        <div class="flex justify-end gap-3 px-1">
            <x-filament::button
                color="gray"
                icon="heroicon-o-x-mark"
                wire:click="resetFilters"
            >
                Clear filters
            </x-filament::button>

            <x-filament::button
                color="primary"
                icon="heroicon-o-funnel"
                wire:click="applyFilters"
            >
                Apply Filters
            </x-filament::button>
        </div>

        @if ($this->hasFiltersApplied())
            @php
                $stats = $this->getStats();
                $groupSummary = $this->getGroupSummary();
                $dropoutData = $this->getDropoutData();
            @endphp

            {{-- Stat cards matching Filament's StatsOverviewWidget design --}}
            @php
                $statCards = [
                    ['label' => 'Total Progresses', 'value' => number_format($stats['total']), 'description' => 'Total progress records created.', 'icon' => 'heroicon-o-chart-bar', 'iconColor' => 'text-gray-400 dark:text-gray-500'],
                    ['label' => 'Surveys Completed', 'value' => number_format($stats['completed']), 'description' => $stats['completion_rate'] . '% Completion Rate', 'icon' => 'heroicon-o-check-circle', 'iconColor' => 'text-green-500 dark:text-green-400'],
                    ['label' => 'Still In Progress', 'value' => number_format($stats['in_progress']), 'description' => 'Uncompleted active surveys.', 'icon' => 'heroicon-o-clock', 'iconColor' => 'text-yellow-500 dark:text-yellow-400'],
                    ['label' => 'Cancelled', 'value' => number_format($stats['cancelled']), 'description' => 'Cancelled the survey progress.', 'icon' => 'heroicon-o-x-circle', 'iconColor' => 'text-red-500 dark:text-red-400'],
                    ['label' => 'Reminders Sent', 'value' => number_format($stats['reminders_sent']), 'description' => 'Total reminders sent to members.', 'icon' => 'heroicon-o-bell', 'iconColor' => 'text-blue-500 dark:text-blue-400'],
                    ['label' => 'Members Sent Reminder', 'value' => number_format($stats['members_sent_reminder']), 'description' => 'Unique members who received reminders.', 'icon' => 'heroicon-o-user-group', 'iconColor' => 'text-primary-500 dark:text-primary-400'],
                    ['label' => 'Repeated Reminders (3+)', 'value' => number_format($stats['repeat_reminders']), 'description' => 'Members who received 3+ reminders.', 'icon' => 'heroicon-o-exclamation-circle', 'iconColor' => 'text-red-500 dark:text-red-400'],
                ];
            @endphp

            <div class="grid gap-6 sm:grid-cols-2 xl:grid-cols-4">
                @foreach ($statCards as $card)
                    <div class="relative rounded-xl bg-white p-6 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                        <div class="grid gap-y-2">
                            <div class="flex items-center gap-x-2">
                                <x-filament::icon :icon="$card['icon']" class="h-5 w-5 {{ $card['iconColor'] }}" />
                                <span class="text-sm font-medium text-gray-500 dark:text-gray-400">{{ $card['label'] }}</span>
                            </div>
                            <div class="text-3xl font-semibold tracking-tight text-gray-950 dark:text-white">
                                {{ $card['value'] }}
                            </div>
                            <div class="text-sm text-gray-500 dark:text-gray-400">
                                {{ $card['description'] }}
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>

            {{-- Group survey summary table matching Filament's table design --}}
            <div class="divide-y divide-gray-200 overflow-hidden rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:divide-white/10 dark:bg-gray-900 dark:ring-white/10">
                <div class="flex items-center justify-between px-4 py-3">
                    <h2 class="text-base font-semibold leading-6 text-gray-950 dark:text-white">Group Survey Summary</h2>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full whitespace-nowrap text-left text-sm">
                        <thead>
                            <tr class="border-b border-gray-200 dark:border-white/10">
                                <th class="px-4 py-3 font-medium text-gray-500 dark:text-gray-400">Group</th>
                                <th class="px-4 py-3 text-right font-medium text-gray-500 dark:text-gray-400">Total Members</th>
                                <th class="px-4 py-3 text-right font-medium text-gray-500 dark:text-gray-400">Total</th>
                                <th class="px-4 py-3 text-right font-medium text-gray-500 dark:text-gray-400">Completed</th>
                                <th class="px-4 py-3 text-right font-medium text-gray-500 dark:text-gray-400">Ongoing</th>
                                <th class="px-4 py-3 text-right font-medium text-gray-500 dark:text-gray-400">Cancelled</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                            <tr>
                                <td class="px-4 py-3 font-medium text-gray-950 dark:text-white">{{ $groupSummary['name'] }}</td>
                                <td class="px-4 py-3 text-right text-gray-700 dark:text-gray-300">{{ number_format($groupSummary['total_members']) }}</td>
                                <td class="px-4 py-3 text-right text-gray-700 dark:text-gray-300">{{ number_format($groupSummary['total_progresses']) }}</td>
                                <td class="px-4 py-3 text-right font-semibold text-green-600 dark:text-green-400">{{ number_format($groupSummary['completed_progresses']) }}</td>
                                <td class="px-4 py-3 text-right font-semibold text-yellow-600 dark:text-yellow-400">{{ number_format($groupSummary['ongoing_progresses']) }}</td>
                                <td class="px-4 py-3 text-right font-semibold text-red-600 dark:text-red-400">{{ number_format($groupSummary['cancelled_progresses']) }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            {{-- Dropout table matching Filament's table design --}}
            <div class="divide-y divide-gray-200 overflow-hidden rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:divide-white/10 dark:bg-gray-900 dark:ring-white/10">
                <div class="flex items-center justify-between px-4 py-3">
                    <h2 class="text-base font-semibold leading-6 text-gray-950 dark:text-white">Survey Dropout Table</h2>
                    <span class="text-sm text-gray-500 dark:text-gray-400">Where members stopped</span>
                </div>
                @if (empty($dropoutData))
                    <div class="px-4 py-6 text-center text-sm text-gray-500 dark:text-gray-400">
                        No dropout data for the selected filters.
                    </div>
                @else
                    <div class="overflow-x-auto">
                        <table class="w-full whitespace-nowrap text-left text-sm">
                            <thead>
                                <tr class="border-b border-gray-200 dark:border-white/10">
                                    <th class="px-4 py-3 font-medium text-gray-500 dark:text-gray-400">Question</th>
                                    <th class="px-4 py-3 text-right font-medium text-gray-500 dark:text-gray-400">Members Stopped</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                                @foreach ($dropoutData as $row)
                                    <tr>
                                        <td class="px-4 py-3 text-gray-700 dark:text-gray-300">{{ $row['question'] }}</td>
                                        <td class="px-4 py-3 text-right font-semibold text-gray-950 dark:text-white">{{ number_format($row['stoppages']) }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>
        @endif

        {{-- Recent report downloads --}}
        @php
            $exports = $this->getRecentExports();
        @endphp

        @if ($exports->isNotEmpty())
            <div class="divide-y divide-gray-200 overflow-hidden rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:divide-white/10 dark:bg-gray-900 dark:ring-white/10">
                <div class="flex items-center justify-between px-4 py-3">
                    <h2 class="text-base font-semibold leading-6 text-gray-950 dark:text-white">Recent report downloads</h2>
                </div>
                <div class="divide-y divide-gray-100 dark:divide-white/5">
                    @foreach ($exports as $export)
                        <div class="flex items-center justify-between px-4 py-3">
                            <div class="min-w-0 flex-1">
                                <p class="truncate text-sm font-medium text-gray-950 dark:text-white">{{ $export->file_name }}</p>
                                <p class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">
                                    {{ $this->exportRowLabel($export) }} · {{ $this->formatFileSize($export->file_size) }} · {{ $export->created_at->diffForHumans() }}
                                </p>
                            </div>
                            <div class="ml-4 flex items-center gap-3">
                                <x-filament::badge :color="$this->exportStatusColor($export->status)">
                                    {{ $this->exportStatusLabel($export->status) }}
                                </x-filament::badge>
                                @if ($export->isDownloadable())
                                    <x-filament::button
                                        tag="a"
                                        href="{{ route('credit-reports.download', $export) }}"
                                        size="sm"
                                        color="success"
                                        icon="heroicon-o-arrow-down-tray"
                                    >
                                        Download
                                    </x-filament::button>
                                @endif
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        @endif
    </div>
</x-filament-panels::page>