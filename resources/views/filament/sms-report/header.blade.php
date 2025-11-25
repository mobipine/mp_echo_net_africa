<div class="fi-header">
    <h1 class="text-2xl font-bold tracking-tight text-gray-950 dark:text-white">
        SMS Sent and Delivery Reports
    </h1>
    <p class="text-sm font-medium text-gray-500 dark:text-gray-400">
        Filter the statistics by Created At date range.
    </p>

    {{-- The Filtering Form is rendered here --}}
    <form wire:submit="submit" class="grid gap-6 py-6 md:grid-cols-2 lg:grid-cols-4">
        {{ $form }}
    </form>
</div>