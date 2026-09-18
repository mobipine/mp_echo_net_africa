<x-filament-panels::page>
    <div class="space-y-6">
        {{-- Filters --}}
        <div class="filament-forms">
            {{ $this->filtersForm }}
        </div>

        <div class="flex justify-end gap-3 p-4">
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

            {{-- Stats cards: 4 columns on large screens, 2 on small --}}
            @php
                $statCards = [
                    ['label' => 'Total Progresses', 'value' => number_format($stats['total']), 'description' => 'Total progress records created.', 'color' => 'gray', 'icon' => 'heroicon-o-chart-bar'],
                    ['label' => 'Surveys Completed', 'value' => number_format($stats['completed']), 'description' => $stats['completion_rate'] . '% Completion Rate', 'color' => 'success', 'icon' => 'heroicon-o-check-circle'],
                    ['label' => 'Still In Progress', 'value' => number_format($stats['in_progress']), 'description' => 'Uncompleted active surveys.', 'color' => 'warning', 'icon' => 'heroicon-o-clock'],
                    ['label' => 'Cancelled', 'value' => number_format($stats['cancelled']), 'description' => 'Cancelled the survey progress.', 'color' => 'danger', 'icon' => 'heroicon-o-x-circle'],
                    ['label' => 'Reminders Sent', 'value' => number_format($stats['reminders_sent']), 'description' => 'Total reminders sent to members.', 'color' => 'info', 'icon' => 'heroicon-o-bell'],
                    ['label' => 'Members Sent Reminder', 'value' => number_format($stats['members_sent_reminder']), 'description' => 'Unique members who received reminders.', 'color' => 'primary', 'icon' => 'heroicon-o-user-group'],
                    ['label' => 'Repeated Reminders (3+)', 'value' => number_format($stats['repeat_reminders']), 'description' => 'Members who received 3+ reminders.', 'color' => 'danger', 'icon' => 'heroicon-o-exclamation-circle'],
                ];
                $colorMap = [
                    'gray' => 'text-gray-600 dark:text-gray-400',
                    'success' => 'text-green-600 dark:text-green-400',
                    'warning' => 'text-yellow-600 dark:text-yellow-400',
                    'danger' => 'text-red-600 dark:text-red-400',
                    'info' => 'text-blue-600 dark:text-blue-400',
                    'primary' => 'text-primary-600 dark:text-primary-400',
                ];
            @endphp

            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
                @foreach ($statCards as $card)
                    <div class="rounded-xl border border-gray-200 bg-white p-5 shadow-sm dark:border-gray-800 dark:bg-gray-900">
                        <div class="flex items-center justify-between">
                            <span class="text-sm font-medium text-gray-700 dark:text-white">{{ $card['label'] }}</span>
                            <x-filament::icon :name="$card['icon']" class="h-5 w-5 {{ $colorMap[$card['color']] ?? $colorMap['gray'] }}" />
                        </div>
                        <p class="mt-2 text-3xl font-bold {{ $colorMap[$card['color']] ?? 'text-gray-700 dark:text-white' }}">{{ $card['value'] }}</p>
                        <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ $card['description'] }}</p>
                    </div>
                @endforeach
            </div>

            {{-- Group survey summary --}}
            <div class="rounded-xl border border-gray-200 bg-white p-5 shadow-sm dark:border-gray-800 dark:bg-gray-900">
                <h2 class="mb-4 text-lg font-semibold text-gray-900 dark:text-white">Group Survey Summary</h2>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b border-gray-200 dark:border-gray-700">
                                <th class="px-4 py-3 text-left font-medium text-gray-700 dark:text-white">Group</th>
                                <th class="px-4 py-3 text-right font-medium text-gray-700 dark:text-white">Total Members</th>
                                <th class="px-4 py-3 text-right font-medium text-gray-700 dark:text-white">Total</th>
                                <th class="px-4 py-3 text-right font-medium text-gray-700 dark:text-white">Completed</th>
                                <th class="px-4 py-3 text-right font-medium text-gray-700 dark:text-white">Ongoing</th>
                                <th class="px-4 py-3 text-right font-medium text-gray-700 dark:text-white">Cancelled</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr class="border-b border-gray-100 dark:border-gray-700">
                                <td class="px-4 py-3 text-gray-700 dark:text-white">{{ $groupSummary['name'] }}</td>
                                <td class="px-4 py-3 text-right text-gray-700 dark:text-white">{{ number_format($groupSummary['total_members']) }}</td>
                                <td class="px-4 py-3 text-right text-gray-700 dark:text-white">{{ number_format($groupSummary['total_progresses']) }}</td>
                                <td class="px-4 py-3 text-right font-medium text-green-600 dark:text-green-400">{{ number_format($groupSummary['completed_progresses']) }}</td>
                                <td class="px-4 py-3 text-right font-medium text-yellow-600 dark:text-yellow-400">{{ number_format($groupSummary['ongoing_progresses']) }}</td>
                                <td class="px-4 py-3 text-right font-medium text-red-600 dark:text-red-400">{{ number_format($groupSummary['cancelled_progresses']) }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            {{-- Dropout table --}}
            <div class="rounded-xl border border-gray-200 bg-white p-5 shadow-sm dark:border-gray-800 dark:bg-gray-900">
                <h2 class="mb-4 text-lg font-semibold text-gray-900 dark:text-white">Survey Dropout Table (Incomplete)</h2>
                @if (empty($dropoutData))
                    <p class="text-gray-700 dark:text-white">No dropout data for the selected filters.</p>
                @else
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead>
                                <tr class="border-b border-gray-200 dark:border-gray-700">
                                    <th class="px-4 py-3 text-left font-medium text-gray-700 dark:text-white">Question</th>
                                    <th class="px-4 py-3 text-right font-medium text-gray-700 dark:text-white">Members Stopped</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($dropoutData as $row)
                                    <tr class="border-b border-gray-100 dark:border-gray-700">
                                        <td class="px-4 py-3 text-gray-700 dark:text-white">{{ $row['question'] }}</td>
                                        <td class="px-4 py-3 text-right text-gray-700 dark:text-white">{{ number_format($row['stoppages']) }}</td>
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
            <section class="rounded-xl border border-gray-200 bg-white p-5 shadow-sm dark:border-gray-800 dark:bg-gray-900">
                <h2 class="mb-3 text-lg font-semibold text-gray-900 dark:text-white">Recent report downloads</h2>
                <div class="space-y-2">
                    @foreach ($exports as $export)
                        <div class="flex items-center justify-between rounded-lg border border-gray-100 p-3 dark:border-gray-700">
                            <div class="min-w-0 flex-1">
                                <p class="truncate text-sm font-medium text-gray-900 dark:text-white">{{ $export->file_name }}</p>
                                <p class="text-xs text-gray-500 dark:text-gray-400">
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
            </section>
        @endif
    </div>
</x-filament-panels::page>