<x-filament-panels::page>
    <div class="space-y-8">
        <x-filament-widgets::widgets
            :widgets="$this->getHeaderWidgets()"
            :columns="1"
            :data="$this->getWidgetData()"
        />

        <section
            class="overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-sm dark:border-white/10 dark:bg-gray-900"
            wire:poll.10s
        >
            <div class="flex flex-col gap-4 border-b border-gray-200 bg-gradient-to-r from-emerald-950 via-emerald-900 to-emerald-800 px-6 py-5 text-white sm:flex-row sm:items-center sm:justify-between dark:border-white/10">
                <div>
                    <div class="flex items-center gap-2 text-xs font-semibold uppercase tracking-[0.18em] text-amber-300">
                        <x-heroicon-o-clock class="h-4 w-4" />
                        Export jobs
                    </div>
                    <h2 class="mt-1 text-xl font-semibold">Recent Excel workbooks</h2>
                    <p class="mt-1 text-sm text-emerald-100">Exports run in the background and remain private to the user who requested them.</p>
                </div>

                <x-filament::button
                    color="gray"
                    icon="heroicon-o-arrow-path"
                    size="sm"
                    wire:click="$refresh"
                >
                    Refresh status
                </x-filament::button>
            </div>

            <div class="divide-y divide-gray-100 dark:divide-white/5">
                @forelse ($this->getRecentExports() as $export)
                    <div class="flex flex-col gap-4 px-6 py-4 lg:flex-row lg:items-center lg:justify-between">
                        <div class="min-w-0">
                            <div class="flex flex-wrap items-center gap-2">
                                <p class="truncate font-medium text-gray-950 dark:text-white">{{ $export->file_name }}</p>
                                <x-filament::badge :color="$this->exportStatusColor($export->status)">
                                    {{ $this->exportStatusLabel($export->status) }}
                                </x-filament::badge>
                            </div>
                            <div class="mt-1 flex flex-wrap gap-x-4 gap-y-1 text-xs text-gray-500 dark:text-gray-400">
                                <span>Requested {{ $export->created_at->diffForHumans() }}</span>
                                <span>{{ $export->row_count !== null ? number_format($export->row_count).' ledger rows' : 'Rows pending' }}</span>
                                <span>{{ $this->formatFileSize($export->file_size) }}</span>
                            </div>
                            @if ($export->status === \App\Models\CreditReportExport::STATUS_FAILED)
                                <p class="mt-2 text-sm text-danger-600 dark:text-danger-400">Generation failed. Please retry the export or contact support if it happens again.</p>
                            @endif
                        </div>

                        <div class="shrink-0">
                            @if ($export->isDownloadable())
                                <x-filament::button
                                    tag="a"
                                    :href="route('credit-reports.download', $export)"
                                    icon="heroicon-o-arrow-down-tray"
                                    color="success"
                                    size="sm"
                                >
                                    Download Excel
                                </x-filament::button>
                            @elseif (in_array($export->status, [\App\Models\CreditReportExport::STATUS_QUEUED, \App\Models\CreditReportExport::STATUS_PROCESSING], true))
                                <div class="flex items-center gap-2 text-sm text-gray-500 dark:text-gray-400">
                                    <x-filament::loading-indicator class="h-5 w-5" />
                                    Workbook is being prepared
                                </div>
                            @endif
                        </div>
                    </div>
                @empty
                    <div class="px-6 py-10 text-center">
                        <x-heroicon-o-document-chart-bar class="mx-auto h-10 w-10 text-gray-400" />
                        <p class="mt-3 font-medium text-gray-950 dark:text-white">No Excel reports have been requested yet</p>
                        <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">Use "Generate Excel report" above to create your first detailed workbook.</p>
                    </div>
                @endforelse
            </div>
        </section>

        <section class="space-y-3">
            <div class="flex flex-col gap-1 sm:flex-row sm:items-end sm:justify-between">
                <div>
                    <p class="text-xs font-semibold uppercase tracking-[0.16em] text-primary-600 dark:text-primary-400">Audit ledger</p>
                    <h2 class="text-xl font-semibold text-gray-950 dark:text-white">Credit transactions</h2>
                </div>
                <p class="text-sm text-gray-500 dark:text-gray-400">Table filters also drive the KPI cards, trend chart, and export defaults.</p>
            </div>

            {{ $this->table }}
        </section>
    </div>
</x-filament-panels::page>
