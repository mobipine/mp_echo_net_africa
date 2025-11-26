<div class="space-y-4">
    <table class="w-full text-left text-sm">
        <thead class="border-b font-semibold">
            <tr>
                <th class="py-2">Member</th>
                <th class="py-2">Position</th>
                <th class="py-2">From</th>
                <th class="py-2">To</th>
            </tr>
        </thead>

        <tbody class="divide-y">
            @forelse ($pastOfficials as $official)
                <tr>
                    <td class="py-2">{{ $official->member->name }}</td>
                    <td class="py-2">{{ $official->position->position_name }}</td>
                    <td class="py-2">
                        {{ $official->created_at ? $official->created_at->format('d M Y') : 'N/A' }}
                    </td>
                    <td class="py-2">
                        {{ $official->left_at ? $official->left_at->format('d M Y') : 'Present' }}
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="4" class="py-4 text-center text-gray-500">
                        No past officials found.
                    </td>
                </tr>
            @endforelse
        </tbody>
    </table>
</div>
