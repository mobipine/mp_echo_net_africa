<x-filament-panels::page>
    <div class="space-y-8">
        <section class="overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-sm dark:border-white/10 dark:bg-gray-900">
            <div class="grid gap-0 lg:grid-cols-[minmax(0,1fr)_22rem]">
                <div class="p-6">
                    {{ $this->filtersForm }}
                </div>

                <aside class="border-t border-gray-200 bg-emerald-950 p-6 text-white lg:border-l lg:border-t-0 dark:border-white/10">
                    <div class="flex items-center gap-2 text-xs font-semibold uppercase tracking-[0.18em] text-amber-300">
                        <x-heroicon-o-lock-closed class="h-4 w-4" />
                        Workbook scope
                    </div>
                    <h2 class="mt-3 text-xl font-semibold">One complete survey record</h2>
                    <p class="mt-2 text-sm leading-6 text-emerald-100">
                        The export always includes every survey question and every member in the selected group, including members who never started.
                    </p>

                    <dl class="mt-5 grid grid-cols-2 gap-3 text-sm">
                        <div class="rounded-xl border border-white/10 bg-white/5 p-3">
                            <dt class="text-emerald-200">Dates</dt>
                            <dd class="mt-1 font-medium">All available</dd>
                        </div>
                        <div class="rounded-xl border border-white/10 bg-white/5 p-3">
                            <dt class="text-emerald-200">Direction</dt>
                            <dd class="mt-1 font-medium">Utilized</dd>
                        </div>
                        <div class="rounded-xl border border-white/10 bg-white/5 p-3">
                            <dt class="text-emerald-200">Activity</dt>
                            <dd class="mt-1 font-medium">Inbound + outbound</dd>
                        </div>
                        <div class="rounded-xl border border-white/10 bg-white/5 p-3">
                            <dt class="text-emerald-200">Channel</dt>
                            <dd class="mt-1 font-medium">SMS only</dd>
                        </div>
                    </dl>
                </aside>
            </div>
        </section>

        <section class="space-y-3">
            <div>
                <p class="text-xs font-semibold uppercase tracking-[0.18em] text-primary-600 dark:text-primary-400">Live analysis</p>
                <h2 class="mt-1 text-xl font-semibold text-gray-950 dark:text-white">Survey response performance</h2>
            </div>

            <x-filament-widgets::widgets
                :widgets="$this->getHeaderWidgets()"
                :columns="2"
            />
        </section>

        <section
            class="overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-sm dark:border-white/10 dark:bg-gray-900"
            wire:poll.10s
        >
            <div class="flex flex-col gap-4 border-b border-gray-200 bg-gradient-to-r from-emerald-950 via-emerald-900 to-emerald-800 px-6 py-5 text-white sm:flex-row sm:items-center sm:justify-between dark:border-white/10">
                <div>
                    <div class="flex items-center gap-2 text-xs font-semibold uppercase tracking-[0.18em] text-amber-300">
                        <x-heroicon-o-clock class="h-4 w-4" />
                        Background report jobs
                    </div>
                    <h2 class="mt-1 text-xl font-semibold">Comprehensive Survey Workbooks</h2>
                    <p class="mt-1 text-sm text-emerald-100">Files are generated on the dedicated report queue and remain private to the requesting user.</p>
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
                                <span>{{ $export->row_count !== null ? number_format($export->row_count).' member rows' : 'Rows pending' }}</span>
                                <span>{{ $this->formatFileSize($export->file_size) }}</span>
                            </div>
                            @if ($export->status === \App\Models\CreditReportExport::STATUS_FAILED)
                                <p class="mt-2 text-sm text-danger-600 dark:text-danger-400">Generation failed. Retry the workbook, or contact support if the problem continues.</p>
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
                        <p class="mt-3 font-medium text-gray-950 dark:text-white">No comprehensive reports requested yet</p>
                        <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">Select a survey and group, then use "Generate comprehensive report" above.</p>
                    </div>
                @endforelse
            </div>
        </section>
    </div>
</x-filament-panels::page>
