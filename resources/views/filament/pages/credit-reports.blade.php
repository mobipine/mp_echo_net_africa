<x-filament-panels::page>
    <section class="space-y-3">
        <div class="flex flex-col gap-1 sm:flex-row sm:items-end sm:justify-between">
            <h2 class="text-xl font-semibold text-gray-950 dark:text-white">Credit transactions</h2>
            <p class="text-sm text-gray-500 dark:text-gray-400">Table filters update the KPI cards above.</p>
        </div>

        {{ $this->table }}
    </section>
</x-filament-panels::page>
