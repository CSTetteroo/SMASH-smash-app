@props([
    // Full name; initials and the tone are derived from it.
    'name' => null,
    'initials' => null,
    // Optional photo URL. Falls back to initials.
    'src' => null,
    // xs 24 · sm 32 · md 40 · lg 56 · xl 80 · 2xl 112
    'size' => 'md',
    // navy | sky | orange | green | grey — null picks a stable tone from the name (never grey:
    // grey is kept for inactive players, see x-smash.avatar).
    'tone' => null,
    'square' => false,
])

{{-- Low-level initials badge. For a User, use x-smash.avatar (same tone rule, plus rings and inactive grey). --}}
@php
    $name = trim((string) $name);

    if (blank($initials)) {
        $parts = preg_split('/\s+/u', $name, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $first = $parts[0] ?? '';
        $last = count($parts) > 1 ? $parts[count($parts) - 1] : '';
        $initials = mb_strtoupper(mb_substr($first, 0, 1).mb_substr($last, 0, 1));
    }

    $tones = ['navy', 'sky', 'orange', 'green'];
    $tone ??= $tones[crc32($name) % count($tones)];

    $sizes = [
        'xs' => 'size-6 text-[11px]',
        'sm' => 'size-8 text-[13px]',
        'md' => 'size-10 text-[15px]',
        'lg' => 'size-14 text-xl',
        'xl' => 'size-20 text-[28px]',
        '2xl' => 'size-28 text-[40px]',
    ];
@endphp

<span
    {{ $attributes->class([
        'tone-'.$tone,
        'relative inline-flex shrink-0 select-none items-center justify-center overflow-hidden',
        'bg-[var(--tone-soft)] font-display font-bold uppercase leading-none tracking-[0.03em] text-[var(--tone-ink)]',
        'ring-1 ring-inset ring-[color-mix(in_oklab,var(--tone)_28%,transparent)]',
        $square ? 'rounded-sm' : 'rounded-full',
        $sizes[$size] ?? $sizes['md'],
    ]) }}
    @if ($name !== '') title="{{ $name }}" @endif
    aria-hidden="true"
    data-ui-avatar
>
    @if ($src)
        <img src="{{ $src }}" alt="" class="size-full object-cover" loading="lazy" />
    @else
        {{ $initials }}
    @endif
</span>
