<x-filament-panels::page>
    <form wire:submit="submit">
        {{ $this->form }}
        
        <div class="mt-6 flex justify-end gap-x-3">
            <x-filament::button 
                type="submit" 
                size="lg"
                color="warning"
            >
                <x-slot name="icon">
                    heroicon-o-arrow-down-tray
                </x-slot>
                Process Withdrawal
            </x-filament::button>
        </div>
    </form>
    
    <x-filament-actions::modals />
</x-filament-panels::page>

