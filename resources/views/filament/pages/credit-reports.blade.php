<x-filament-panels::page>
    <div class="space-y-8">
        <x-filament-widgets::widgets
            :widgets="$this->getHeaderWidgets()"
            :columns="1"
            :data="$this->getWidgetData()"
        />

        <section class="space-y-3">
            <div class="flex flex-col gap-1 sm:flex-row sm:items-end sm:justify-between">
                <div>
                    <h2 class="text-xl font-semibold text-gray-950 dark:text-white">Credit transactions</h2>
                </div>
                <p class="text-sm text-gray-500 dark:text-gray-400">Table filters drive the KPI cards and utilization trend above.</p>
            </div>

            {{ $this->table }}
        </section>
    </div>
</x-filament-panels::page>
