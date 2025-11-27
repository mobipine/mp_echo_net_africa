<x-filament-panels::page>
    <x-filament-widgets::widgets
        :widgets="$this->getHeaderWidgets()"
        :columns="$this->getHeaderWidgetsColumns()"
    />

    <div class="mt-6">
        {{ $this->table }}
    </div>
</x-filament-panels::page>
