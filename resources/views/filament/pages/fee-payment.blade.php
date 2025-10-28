<x-filament-panels::page>
    <form wire:submit="submit">
        {{ $this->form }}
        
        <div class="mt-6 flex justify-end gap-x-3">
            <x-filament::button 
                type="submit" 
                size="lg"
                color="primary"
            >
                <x-slot name="icon">
                    heroicon-o-banknotes
                </x-slot>
                Record Payment
            </x-filament::button>
        </div>
    </form>
    
    <x-filament-actions::modals />
</x-filament-panels::page>

