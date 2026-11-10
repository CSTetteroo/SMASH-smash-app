@props([
    /*
     | x-smash.avatar — a player's initials badge (renders x-ui.avatar).
     |
     | user         App\Models\User. Name, initials and tone come from it.
     | name         Fallback name when there is no user.
     | size         xs 24 · sm 32 · md 40 · lg 56 · xl 80 · 2xl 112 (px)
     | tone         navy · sky · orange · green · grey. null = the stable tone x-ui.avatar picks
     |              from the name (so the header avatar matches), grey for inactive players.
     | square       Square corners instead of a circle.
     | ring         Accent ring: false (default) · 'auto' (admins navy, rank 1 SMASH red) ·
     |              'admin' · 'top' · true (navy).
     | rank         Current position, so ring="auto" can mark #1.
     | placeholder  Dashed empty circle for open draw slots (TBD) and byes.
     */
    'user' => null,
    'name' => null,
    'size' => 'md',
    'tone' => null,
    'square' => false,
    'ring' => false,
    'rank' => null,
    'placeholder' => false,
])

@php
    $name = trim((string) ($name ?? $user?->name ?? ''));
    $initials = $user instanceof \App\Models\User ? $user->initials() : null;

    if ($tone === null && $user instanceof \App\Models\User && $user->is_active === false) {
        $tone = 'grey';
    }

    if ($ring === 'auto') {
        $ring = match (true) {
            (int) $rank === 1 => 'top',
            $user instanceof \App\Models\User && $user->isAdmin() => 'admin',
            default => false,
        };
    }

    $ringClass = match ($ring) {
        'top' => 'outline-2 outline-offset-2 outline-smash-500',
        'admin', true => 'outline-2 outline-offset-2 outline-navy-800 dark:outline-navy-300 in-[.theme-navy]:outline-white',
        default => null,
    };

    $box = match ($size) {
        'xs' => 'size-6',
        'sm' => 'size-8',
        'lg' => 'size-14',
        'xl' => 'size-20',
        '2xl' => 'size-28',
        default => 'size-10',
    };
@endphp

@if ($placeholder)
    <span
        {{ $attributes->class([
            'inline-flex shrink-0 border border-dashed border-line-strong',
            $square ? 'rounded-sm' : 'rounded-full',
            $box,
        ]) }}
        aria-hidden="true"
        data-smash-avatar
    ></span>
@else
    <x-ui.avatar
        :name="$name"
        :initials="$initials"
        :size="$size"
        :tone="$tone"
        :square="$square"
        {{ $attributes->class([$ringClass]) }}
        data-smash-avatar
    />
@endif
