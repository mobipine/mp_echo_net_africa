<x-filament-panels::page>
    <div class="space-y-6">

        <div class="filament-forms-card p-6 bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-200 dark:border-gray-700">
            {{ $this->filtersForm }}
        </div>

        <x-filament-widgets::widgets
        :widgets="$this->getHeaderWidgets()"
        :columns="2"
        />

    </div>

</x-filament-panels::page>
