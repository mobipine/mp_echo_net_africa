@php
    $grouped = $responses->groupBy(fn ($r) => $r->survey?->title ?? 'Unknown survey');
@endphp

<div class="space-y-6">
    @foreach ($grouped as $surveyTitle => $surveyResponses)
        <div class="divide-y divide-gray-200 overflow-hidden rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:divide-white/10 dark:bg-gray-900 dark:ring-white/10">
            <div class="flex items-center justify-between px-4 py-3">
                <h3 class="text-base font-semibold text-gray-950 dark:text-white">{{ $surveyTitle }}</h3>
                <span class="text-sm text-gray-500 dark:text-gray-400">{{ $surveyResponses->count() }} responses</span>
            </div>
            <div class="divide-y divide-gray-100 dark:divide-white/5">
                @foreach ($surveyResponses as $response)
                    <div class="px-4 py-3">
                        <div class="flex items-start justify-between gap-4">
                            <div class="min-w-0 flex-1">
                                <p class="text-sm font-medium text-gray-700 dark:text-white">
                                    {{ $response->question?->question ?? 'Unknown question' }}
                                </p>
                                <p class="mt-1 text-sm font-semibold text-primary-600 dark:text-primary-400">
                                    {{ $response->survey_response }}
                                </p>
                            </div>
                            <span class="shrink-0 text-xs text-gray-500 dark:text-gray-400">
                                {{ $response->created_at?->format('M d, Y H:i') }}
                            </span>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    @endforeach

    @if ($grouped->isEmpty())
        <div class="rounded-xl border border-gray-200 bg-white p-8 text-center shadow-sm dark:border-gray-800 dark:bg-gray-900">
            <p class="text-gray-700 dark:text-white">No survey responses found for {{ $msisdn }}.</p>
        </div>
    @endif
</div>