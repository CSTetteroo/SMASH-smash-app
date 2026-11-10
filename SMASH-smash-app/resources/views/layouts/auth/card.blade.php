{{-- Kept for compatibility with starter-kit references; every auth page uses the SMASH split layout. --}}
<x-layouts::auth.split :title="$title ?? null">
    {{ $slot }}
</x-layouts::auth.split>
