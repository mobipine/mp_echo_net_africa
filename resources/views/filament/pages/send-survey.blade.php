<x-filament::page>
    <form wire:submit.prevent="submit" class="space-y-6">
        {{ $this->form }}

        @if ($automated)
            <x-filament::button type="submit" size="md">
                Schedule Survey
            </x-filament::button>
        @else
            <x-filament::button type="submit" size="md">
                Send Survey
            </x-filament::button>
        @endif
    </form>
</x-filament::page>