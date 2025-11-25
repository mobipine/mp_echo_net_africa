<x-filament-panels::page>
    <div class="space-y-6">
        {{-- 🔹 Filters Card --}}
        <div class="">

                {{-- The actual filter form --}}
                <div class="filament-forms">
                    {{ $this->filtersForm }}
                </div>

                {{-- Apply Filters Button --}}
                <div class="flex justify-end mt-4 p-4">
                    <x-filament::button
                        color="primary"
                        icon="heroicon-o-funnel"
                        x-on:click="window.location.reload()"
                    >
                        Apply Filters
                    </x-filament::button>
                </div>
        </div>

        {{-- 🔹 Widgets Section --}}
        <x-filament-widgets::widgets
            :widgets="$this->getHeaderWidgets()"
            :columns="2"
        />
    </div>
</x-filament-panels::page>
