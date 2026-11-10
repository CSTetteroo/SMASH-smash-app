@props([
    'label',
])

<div {{ $attributes->class('min-w-0 border-t border-line pt-3') }}>
    <dt class="label-caps text-ink-muted">{{ $label }}</dt>
    <dd class="mt-1.5 text-[15px] font-medium text-ink">{{ $slot }}</dd>
</div>
