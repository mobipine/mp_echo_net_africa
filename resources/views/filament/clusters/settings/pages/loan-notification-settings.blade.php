<x-filament-panels::page>
    <div class="space-y-6">
        <div class="bg-white dark:bg-gray-800 shadow rounded-lg p-6">
            <div class="flex items-center space-x-3 mb-4">
                <div class="flex-shrink-0">
                    <svg class="h-8 w-8 text-blue-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 4.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"></path>
                    </svg>
                </div>
                <div>
                    <h3 class="text-lg font-medium text-gray-900 dark:text-white">
                        Loan Notification Settings
                    </h3>
                    <p class="text-sm text-gray-500 dark:text-gray-400">
                        Configure email notifications for loan repayments and manage recipient settings.
                    </p>
                </div>
            </div>
        </div>

        <form wire:submit="save">
            {{ $this->form }}
            
            <div class="flex justify-end space-x-3 mt-6">
                <x-filament::button
                    type="submit"
                    color="primary"
                    icon="heroicon-m-check"
                >
                    Save Settings
                </x-filament::button>
                
                <x-filament::button
                    type="button"
                    color="gray"
                    icon="heroicon-m-paper-airplane"
                    wire:click="testEmail"
                >
                    Send Test Email
                </x-filament::button>
            </div>
        </form>
    </div>
</x-filament-panels::page>