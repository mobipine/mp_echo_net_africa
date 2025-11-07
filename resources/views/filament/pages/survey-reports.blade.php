<x-filament-panels::page>
    <div class="space-y-6">
        {{-- 🔹 Filters Card --}}
        <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-200 dark:border-gray-700">
            <!-- <div class="p-4 sm:p-6">
                <h3 class="text-lg font-semibold text-gray-800 dark:text-gray-100 mb-4">
                    Filters
                </h3> -->

                {{-- The actual filter form --}}
                <div class="filament-forms">
                    {{ $this->filtersForm }}
                </div>

                {{-- Apply Filters Button --}}
                <div class="flex justify-end mt-4 border-t border-gray-100 dark:border-gray-700 p-4">
                    <x-filament::button
                        color="primary"
                        icon="heroicon-o-funnel"
                        x-on:click="window.location.reload()"
                    >
                        Apply Filters
                    </x-filament::button>
                </div>
            <!-- </div> -->
        </div>

        {{-- 🔹 Widgets Section --}}
        <x-filament-widgets::widgets
            :widgets="$this->getHeaderWidgets()"
            :columns="2"
        />
    </div>
</x-filament-panels::page>
