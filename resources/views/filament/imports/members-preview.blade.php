@php
    /** @var array $preview */
    $total = $preview['total'] ?? 0;
    $create = $preview['create'] ?? 0;
    $update = $preview['update'] ?? 0;
    $errors = $preview['errors'] ?? 0;
    $withWarnings = $preview['with_warnings'] ?? 0;
    $newGroups = $preview['new_groups'] ?? [];
    $flagged = $preview['flagged_rows'] ?? [];
    $errorRows = $preview['error_rows'] ?? [];
@endphp

<div class="space-y-4 text-sm">
    @if ($total === 0)
        <div class="rounded-lg bg-warning-50 dark:bg-warning-500/10 p-4 text-warning-700 dark:text-warning-400">
            @if (!empty($preview['unreadable']))
                Could not read the uploaded file yet. Try re-selecting the file, then move to this step again.
            @else
                No data rows were found on the first sheet. Check that the first row contains the column headers
                (<code>group_name</code>, <code>national_id</code>, <code>name_of_participant</code>, <code>phone_no</code>, <code>gender</code>, <code>year</code>)
                and that your data is on the first sheet.
            @endif
        </div>

        @php $diag = $preview['diagnostics'] ?? null; @endphp
        @if ($diag)
            <div class="rounded-lg border border-gray-200 dark:border-white/10 p-3 text-xs">
                @if (!empty($diag['error']))
                    <div class="text-danger-700 dark:text-danger-400">Could not read the file: {{ $diag['error'] }}</div>
                @else
                    <div class="mb-2 text-gray-600 dark:text-gray-300">
                        The file has <span class="font-semibold">{{ $diag['sheet_count'] }}</span> sheet(s).
                        Here are the first rows of the first sheet — the importer expects your headers to be the very first row:
                    </div>
                    <div class="overflow-x-auto">
                        <table class="w-full text-left">
                            <tbody class="divide-y divide-gray-200 dark:divide-white/5">
                                @foreach ($diag['first_rows'] as $i => $cells)
                                    <tr>
                                        <td class="px-2 py-1 font-mono text-gray-400">{{ $i + 1 }}</td>
                                        @foreach (array_slice((array) $cells, 0, 10) as $cell)
                                            <td class="px-2 py-1 text-gray-700 dark:text-gray-300 whitespace-nowrap">{{ \Illuminate\Support\Str::limit((string) $cell, 24) }}</td>
                                        @endforeach
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>
        @endif
    @else
        {{-- Summary cards --}}
        <div class="grid grid-cols-2 gap-3 sm:grid-cols-4">
            <div class="rounded-lg border border-gray-200 dark:border-white/10 p-3">
                <div class="text-2xl font-bold text-gray-950 dark:text-white">{{ number_format($total) }}</div>
                <div class="text-gray-500 dark:text-gray-400">Rows in file</div>
            </div>
            <div class="rounded-lg border border-success-200 dark:border-success-500/30 bg-success-50 dark:bg-success-500/10 p-3">
                <div class="text-2xl font-bold text-success-700 dark:text-success-400">{{ number_format($create) }}</div>
                <div class="text-success-700 dark:text-success-400">New members</div>
            </div>
            <div class="rounded-lg border border-info-200 dark:border-info-500/30 bg-info-50 dark:bg-info-500/10 p-3">
                <div class="text-2xl font-bold text-info-700 dark:text-info-400">{{ number_format($update) }}</div>
                <div class="text-info-700 dark:text-info-400">Existing updated</div>
            </div>
            <div class="rounded-lg border {{ $errors > 0 ? 'border-danger-200 dark:border-danger-500/30 bg-danger-50 dark:bg-danger-500/10' : 'border-gray-200 dark:border-white/10' }} p-3">
                <div class="text-2xl font-bold {{ $errors > 0 ? 'text-danger-700 dark:text-danger-400' : 'text-gray-950 dark:text-white' }}">{{ number_format($errors) }}</div>
                <div class="{{ $errors > 0 ? 'text-danger-700 dark:text-danger-400' : 'text-gray-500 dark:text-gray-400' }}">Rows skipped (errors)</div>
            </div>
        </div>

        {{-- New groups --}}
        @if (!empty($newGroups))
            <div class="rounded-lg bg-info-50 dark:bg-info-500/10 p-3 text-info-700 dark:text-info-400">
                <span class="font-semibold">{{ count($newGroups) }} new group(s) will be created:</span>
                {{ implode(', ', array_map(fn ($g) => '“' . $g . '”', $newGroups)) }}
            </div>
        @endif

        {{-- Ignored columns notice --}}
        <div class="text-xs text-gray-500 dark:text-gray-400">
            Note: any <code>PWD</code>, <code>IDP</code> and <code>county_name</code> columns are read but not stored by the importer.
        </div>

        {{-- Error rows --}}
        @if (!empty($errorRows))
            <div>
                <div class="mb-1 font-semibold text-danger-700 dark:text-danger-400">Rows that will be skipped</div>
                <div class="overflow-hidden rounded-lg border border-danger-200 dark:border-danger-500/30">
                    <table class="w-full text-left">
                        <thead class="bg-danger-50 dark:bg-danger-500/10 text-danger-700 dark:text-danger-400">
                            <tr>
                                <th class="px-3 py-2 font-medium">Row</th>
                                <th class="px-3 py-2 font-medium">Error</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200 dark:divide-white/5">
                            @foreach ($errorRows as $er)
                                <tr>
                                    <td class="px-3 py-2 text-gray-500 dark:text-gray-400">{{ $er['row'] }}</td>
                                    <td class="px-3 py-2 text-gray-700 dark:text-gray-300">{{ $er['message'] }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        @endif

        {{-- Flagged rows --}}
        @if (!empty($flagged))
            <div>
                <div class="mb-1 font-semibold text-warning-700 dark:text-warning-400">
                    {{ number_format($withWarnings) }} row(s) need attention
                    @if (!empty($preview['flagged_truncated']))
                        <span class="font-normal text-gray-500 dark:text-gray-400">(showing first {{ count($flagged) }})</span>
                    @endif
                </div>
                <div class="overflow-x-auto rounded-lg border border-gray-200 dark:border-white/10">
                    <table class="w-full text-left">
                        <thead class="bg-gray-50 dark:bg-white/5 text-gray-600 dark:text-gray-300">
                            <tr>
                                <th class="px-3 py-2 font-medium">Row</th>
                                <th class="px-3 py-2 font-medium">Name</th>
                                <th class="px-3 py-2 font-medium">National ID</th>
                                <th class="px-3 py-2 font-medium">Action</th>
                                <th class="px-3 py-2 font-medium">Notes</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200 dark:divide-white/5">
                            @foreach ($flagged as $r)
                                <tr>
                                    <td class="px-3 py-2 text-gray-500 dark:text-gray-400">{{ $r['row'] }}</td>
                                    <td class="px-3 py-2 text-gray-950 dark:text-white">{{ $r['name'] }}</td>
                                    <td class="px-3 py-2 text-gray-700 dark:text-gray-300">{{ $r['national_id'] }}</td>
                                    <td class="px-3 py-2">
                                        <span class="inline-flex items-center rounded-md px-2 py-0.5 text-xs font-medium {{ $r['action'] === 'create' ? 'bg-success-100 text-success-700 dark:bg-success-500/10 dark:text-success-400' : 'bg-info-100 text-info-700 dark:bg-info-500/10 dark:text-info-400' }}">
                                            {{ $r['action'] === 'create' ? 'New' : 'Update' }}
                                        </span>
                                    </td>
                                    <td class="px-3 py-2 text-gray-700 dark:text-gray-300">
                                        <ul class="list-disc space-y-0.5 pl-4">
                                            @foreach ($r['warnings'] as $w)
                                                <li>{{ $w }}</li>
                                            @endforeach
                                        </ul>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        @elseif ($errors === 0)
            <div class="rounded-lg bg-success-50 dark:bg-success-500/10 p-3 text-success-700 dark:text-success-400">
                All rows look clean. Nothing flagged.
            </div>
        @endif

        <div class="text-xs text-gray-500 dark:text-gray-400">
            This is a preview only — nothing has been saved yet. Click <span class="font-medium">Import</span> to apply.
        </div>
    @endif
</div>
