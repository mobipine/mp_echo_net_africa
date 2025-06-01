@props([
    'circular' => true,
    'size' => 'md',
])

@php
    $user = auth()->user();
    $profilePicture = $user && $user->profile_picture
        ? asset('storage/' . $user->profile_picture)
        : 'https://via.placeholder.com/150'; // Default placeholder image
@endphp

<img
    src="{{ $profilePicture }}"
    {{
        $attributes
            ->class([
                'fi-avatar object-cover object-center',
                'rounded-md' => ! $circular,
                'fi-circular rounded-full' => $circular,
                match ($size) {
                    'sm' => 'h-6 w-6',
                    'md' => 'h-8 w-8',
                    'lg' => 'h-10 w-10',
                    default => $size,
                },
            ])
    }}
/>