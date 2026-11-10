@props([
    // Accessible name for the tab row ("Discipline", "Tournament sections" …).
    'label' => null,
    // underline (BUas: uppercase, navy bar under the current tab) · pills (compact segmented filter)
    'variant' => 'underline',
])

{{--
    Tab row. Items are links (<x-ui.tabs.item :href>) for navigation between URLs/query strings, or
    buttons (<x-ui.tabs.item wire:click>) for Livewire state. Scrolls horizontally on small screens.
--}}
<nav
    @if ($label) aria-label="{{ $label }}" @endif
    {{ $attributes->class([
        'ui-tabs relative -mx-[var(--spacing-gutter)] overflow-x-auto scrollbar-none px-[var(--spacing-gutter)] sm:mx-0 sm:px-0',
        'border-b border-line' => $variant === 'underline',
    ]) }}
    data-variant="{{ $variant }}"
>
    <ul @class([
        'flex w-max min-w-full items-stretch',
        'gap-x-7 lg:gap-x-9' => $variant === 'underline',
        'gap-1 p-1 bg-canvas-muted w-max min-w-0' => $variant === 'pills',
    ]) role="list">
        {{ $slot }}
    </ul>
</nav>
