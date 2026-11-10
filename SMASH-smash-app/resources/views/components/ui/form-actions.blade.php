@props([
    // Stick to the bottom of the viewport while the form scrolls (long forms).
    'sticky' => false,
])

{{-- Form footer: secondary actions left ($secondary slot, e.g. Delete), primary actions right (default slot). --}}
<div {{ $attributes->class([
    'flex flex-wrap items-center justify-between gap-3 border-t border-line pt-6',
    'sticky bottom-0 z-10 -mx-[var(--spacing-gutter)] bg-surface px-[var(--spacing-gutter)] pb-4 sm:mx-0 sm:px-0' => $sticky,
]) }}>
    <div class="flex flex-wrap items-center gap-3">{{ $secondary ?? '' }}</div>
    <div class="ms-auto flex flex-wrap items-center gap-3">{{ $slot }}</div>
</div>
