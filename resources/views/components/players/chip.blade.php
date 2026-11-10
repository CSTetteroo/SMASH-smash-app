@props([
    // Selected state (solid navy, aria-pressed).
    'active' => false,
    // Optional number after the label ("Games 12").
    'count' => null,
])

{{--
    Filter chip as a button: the x-ui.tag (size lg) look for Livewire state instead of a link.
    Usage: <x-players.chip wire:click="$set('programme', 'games')" :active="$programme === 'games'" :count="12">Games</x-players.chip>
--}}
<button
    type="button"
    aria-pressed="{{ $active ? 'true' : 'false' }}"
    {{ $attributes->class([
        'tone-navy inline-flex min-h-9 max-w-full shrink-0 items-center gap-2 whitespace-nowrap px-3 py-1.5 text-xs font-semibold uppercase leading-none tracking-label transition-colors duration-150 ease-brand',
        'border border-transparent bg-[var(--tone-solid)] text-[var(--tone-on)]' => $active,
        'border border-[var(--tone)] text-[var(--tone-ink)] hover:bg-[var(--tone-soft)]' => ! $active,
    ]) }}
>
    <span class="truncate">{{ $slot }}</span>
    @if ($count !== null)
        <span @class(['num text-[13px] font-bold tracking-normal', 'opacity-80' => $active, 'text-ink-muted' => ! $active])>{{ $count }}</span>
    @endif
</button>
