<x-filament::page>
    <div class="space-y-6">
        {{ $this->form }}

        @if($previewData)
            <x-filament::section>
                <x-slot name="heading">
                    Preview – Who Will Receive Reminders
                </x-slot>

                <div class="space-y-6">
                    {{-- Summary --}}
                    <div class="rounded-lg bg-gray-50 dark:bg-gray-800/50 p-4 space-y-2">
                        <p class="text-sm font-medium text-gray-700 dark:text-white">Summary</p>
                        <ul class="text-sm text-gray-600 dark:text-gray-300 space-y-1">
                            <li>• Group: <span class="font-medium">{{ $previewData['group_name'] }}</span></li>
                            <li>• Survey: <span class="font-medium">{{ $previewData['survey_title'] }}</span></li>
                            <li>• Total incomplete in DB: {{ number_format($previewData['total_incomplete']) }}</li>
                            <li>• Matching filter: {{ number_format($previewData['matching_count']) }}</li>
                            <li>• Reminders to be sent: <span class="font-semibold">{{ number_format($previewData['to_send']) }}</span></li>
                            <li>• Unique members: {{ number_format($previewData['unique_members']) }}</li>
                        </ul>
                    </div>

                    {{-- SMS Credits --}}
                    <div class="rounded-lg bg-gray-50 dark:bg-gray-800/50 p-4 space-y-2">
                        <p class="text-sm font-medium text-gray-700 dark:text-white">SMS Credits</p>
                        <ul class="text-sm text-gray-600 dark:text-gray-300 space-y-1">
                            <li>• Total messages: {{ $previewData['total_messages'] }}</li>
                            <li>• Total credits needed: <span class="font-semibold">{{ $previewData['total_credits'] }}</span></li>
                            <li>• Average per message: {{ $previewData['avg_credits'] }}</li>
                        </ul>
                        @if(!empty($previewData['credit_breakdown']))
                            <div class="mt-2 pt-2 border-t border-gray-200 dark:border-gray-700">
                                <p class="text-xs font-medium text-gray-500 dark:text-gray-400 mb-1">Credit distribution</p>
                                @foreach($previewData['credit_breakdown'] as $credits => $count)
                                    @php $pct = $previewData['total_messages'] > 0 ? round(($count / $previewData['total_messages']) * 100, 1) : 0; @endphp
                                    <span class="text-xs text-gray-600 dark:text-gray-300">{{ $credits }} credit(s): {{ $count }} ({{ $pct }}%)</span>
                                    @if(!$loop->last) · @endif
                                @endforeach
                            </div>
                        @endif
                    </div>

                    {{-- Sample recipients (first 20) --}}
                    <div>
                        <p class="text-sm font-medium text-gray-700 dark:text-white mb-2">Sample recipients (first 20)</p>
                        <div class="overflow-x-auto rounded-lg border border-gray-200 dark:border-gray-700">
                            <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700 text-sm">
                                <thead class="bg-gray-50 dark:bg-gray-800">
                                    <tr>
                                        <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">ID</th>
                                        <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">Member</th>
                                        <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">Phone</th>
                                        <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">Reminders</th>
                                        <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">Created</th>
                                        <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">Days old</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                                    @foreach($previewData['sample_rows'] as $row)
                                        <tr class="bg-white dark:bg-gray-900">
                                            <td class="px-3 py-2 text-gray-700 dark:text-white">{{ $row['id'] }}</td>
                                            <td class="px-3 py-2 text-gray-700 dark:text-white">{{ $row['member'] }}</td>
                                            <td class="px-3 py-2 text-gray-700 dark:text-white">{{ $row['phone'] }}</td>
                                            <td class="px-3 py-2 text-gray-700 dark:text-white">{{ $row['reminders'] }}</td>
                                            <td class="px-3 py-2 text-gray-700 dark:text-white">{{ $row['created'] }}</td>
                                            <td class="px-3 py-2 text-gray-700 dark:text-white">{{ $row['days_old'] }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                        @if($previewData['more_count'] > 0)
                            <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">... and {{ number_format($previewData['more_count']) }} more</p>
                        @endif
                    </div>

                    {{-- Reminders breakdown --}}
                    @if(!empty($previewData['reminders_breakdown']))
                        <div class="rounded-lg bg-gray-50 dark:bg-gray-800/50 p-4">
                            <p class="text-sm font-medium text-gray-700 dark:text-white mb-2">Breakdown by reminders received</p>
                            <ul class="text-sm text-gray-600 dark:text-gray-300 space-y-1">
                                @foreach($previewData['reminders_breakdown'] as $label => $count)
                                    <li>• {{ $label }} reminder(s): {{ $count }} progress records</li>
                                @endforeach
                            </ul>
                        </div>
                    @endif

                    {{-- Sample message --}}
                    @if($previewData['sample_message'])
                        <div class="rounded-lg bg-gray-50 dark:bg-gray-800/50 p-4">
                            <p class="text-sm font-medium text-gray-700 dark:text-white mb-2">Sample reminder message</p>
                            <p class="text-sm text-gray-600 dark:text-gray-300 font-mono whitespace-pre-wrap break-words">{{ Str::limit($previewData['sample_message'], 300) }}</p>
                            <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">{{ $previewData['sample_message_length'] }} chars ≈ {{ (int) ceil($previewData['sample_message_length'] / 160) }} SMS credit(s)</p>
                        </div>
                    @endif
                </div>
            </x-filament::section>
        @endif
    </div>
</x-filament::page>
