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
                wire:click="$refresh"
            >
                Apply Filters
            </x-filament::button>
        </div>

        {{-- Widgets (rendered once, below filters) --}}
        <x-filament-widgets::widgets
            :widgets="$this->getReportWidgets()"
            :columns="2"
        />

        {{-- Recent report downloads --}}
        @php
            $exports = $this->getRecentExports();
        @endphp

        @if ($exports->isNotEmpty())
            <section class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-800 dark:bg-gray-900">
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
