<x-filament::page>
    <form wire:submit.prevent="submit" class="space-y-6">
        {{ $this->form }}

        <x-filament::button type="submit" size="md">
            Send SMS
        </x-filament::button>
    </form>
</x-filament::page>
