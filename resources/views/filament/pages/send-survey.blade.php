<x-filament::page>
    <div class="space-y-6">
        <form wire:submit.prevent class="space-y-6">
            {{ $this->form }}
        </form>

        @if($previewData ?? null)
            <x-filament::section>
                <x-slot name="heading">
                    Preview – What Will Happen
                </x-slot>

                <div class="space-y-6">
                    {{-- Summary --}}
                    <div class="rounded-lg bg-gray-50 dark:bg-gray-800/50 p-4 space-y-2">
                        <p class="text-sm font-medium text-gray-700 dark:text-white">Summary</p>
                        <ul class="text-sm text-gray-600 dark:text-gray-300 space-y-1">
                            <li>• Survey: <span class="font-medium">{{ $previewData['survey_title'] }}</span></li>
                            <li>• Channel: {{ $previewData['channel'] }}</li>
                            <li>• Mode: {{ $previewData['is_automated'] ? 'Scheduled (automated)' : 'Send now (manual)' }}</li>
                            @if($previewData['is_automated'] && $previewData['starts_at'])
                                <li>• Start: {{ \Carbon\Carbon::parse($previewData['starts_at'])->format('Y-m-d H:i') }}</li>
                            @endif
                            @if($previewData['limit'])
                                <li>• Recipient limit: {{ number_format($previewData['limit']) }}</li>
                            @endif
                            <li>• Groups: {{ $previewData['group_count'] }}</li>
                            <li>• Total active members: {{ number_format($previewData['total_active']) }}</li>
                            @if($previewData['total_skipped_no_phone'] > 0)
                                <li>• Skipped (no phone): {{ number_format($previewData['total_skipped_no_phone']) }}</li>
                            @endif
                            @if($previewData['total_skipped_completed'] > 0)
                                <li>• Skipped (already completed): {{ number_format($previewData['total_skipped_completed']) }}</li>
                            @endif
                            @if($previewData['participant_uniqueness'] && $previewData['total_skipped_incomplete'] > 0)
                                <li>• Skipped (incomplete, uniqueness ON): {{ number_format($previewData['total_skipped_incomplete']) }}</li>
                            @endif
                            <li>• <span class="font-semibold">Will receive: {{ number_format($previewData['to_send']) }}</span></li>
                            <li>• Estimated SMS credits: <span class="font-semibold">{{ number_format($previewData['estimated_credits']) }}</span></li>
                        </ul>
                    </div>

                    {{-- Group breakdown --}}
                    <div>
                        <p class="text-sm font-medium text-gray-700 dark:text-white mb-2">Breakdown by group</p>
                        <div class="overflow-x-auto rounded-lg border border-gray-200 dark:border-gray-700">
                            <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700 text-sm">
                                <thead class="bg-gray-50 dark:bg-gray-800">
                                    <tr>
                                        <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">Group</th>
                                        <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">Active</th>
                                        <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">With phone</th>
                                        <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">Completed</th>
                                        @if($previewData['participant_uniqueness'])
                                            <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">Incomplete</th>
                                        @endif
                                        <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">To send</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                                    @foreach($previewData['group_breakdown'] as $row)
                                        <tr class="bg-white dark:bg-gray-900">
                                            <td class="px-3 py-2 text-gray-700 dark:text-white">{{ $row['name'] }}</td>
                                            <td class="px-3 py-2 text-gray-700 dark:text-white">{{ $row['active'] }}</td>
                                            <td class="px-3 py-2 text-gray-700 dark:text-white">{{ $row['with_phone'] }}</td>
                                            <td class="px-3 py-2 text-gray-700 dark:text-white">{{ $row['completed'] }}</td>
                                            @if($previewData['participant_uniqueness'])
                                                <td class="px-3 py-2 text-gray-700 dark:text-white">{{ $row['incomplete_skipped'] }}</td>
                                            @endif
                                            <td class="px-3 py-2 text-gray-700 dark:text-white font-medium">{{ $row['to_send'] }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    </div>

                    {{-- Sample recipients --}}
                    @if(!empty($previewData['sample_rows']))
                        <div>
                            <p class="text-sm font-medium text-gray-700 dark:text-white mb-2">Sample recipients (first 20)</p>
                            <div class="overflow-x-auto rounded-lg border border-gray-200 dark:border-gray-700">
                                <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700 text-sm">
                                    <thead class="bg-gray-50 dark:bg-gray-800">
                                        <tr>
                                            <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">Member</th>
                                            <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">Phone</th>
                                            <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">Group</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                                        @foreach($previewData['sample_rows'] as $row)
                                            <tr class="bg-white dark:bg-gray-900">
                                                <td class="px-3 py-2 text-gray-700 dark:text-white">{{ $row['member'] }}</td>
                                                <td class="px-3 py-2 text-gray-700 dark:text-white">{{ $row['phone'] }}</td>
                                                <td class="px-3 py-2 text-gray-700 dark:text-white">{{ $row['group'] }}</td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                            @if(($previewData['more_count'] ?? 0) > 0)
                                <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">... and {{ number_format($previewData['more_count']) }} more</p>
                            @endif
                        </div>
                    @endif

                    {{-- Sample message --}}
                    @if($previewData['sample_message'] ?? null)
                        <div class="rounded-lg bg-gray-50 dark:bg-gray-800/50 p-4">
                            <p class="text-sm font-medium text-gray-700 dark:text-white mb-2">Sample message</p>
                            <p class="text-sm text-gray-600 dark:text-gray-300 font-mono whitespace-pre-wrap break-words">{{ Str::limit($previewData['sample_message'], 300) }}</p>
                            <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">{{ strlen($previewData['sample_message']) }} chars ≈ {{ (int) ceil(strlen($previewData['sample_message']) / 160) }} SMS credit(s)</p>
                        </div>
                    @endif
                </div>
            </x-filament::section>
        @endif
    </div>
</x-filament::page>
