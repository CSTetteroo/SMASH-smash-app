@props([
    /*
     | x-smash.rating — a rating number in Barlow Condensed with the provisional "P" marker
     | (renders x-ui.rating).
     |
     | value        Rating (int|float, rounded for display). null = taken from user; still null = "–".
     | user         App\Models\User: value and provisional state come from it.
     | discipline   Discipline|string (default singles) when reading from a user.
     | provisional  null = automatic from the user (fewer than smash.rating.provisional_matches).
     | size         xs 15px · sm 17px · md 22px · lg 32px · xl stat size
     | label        Caption under the number ("Singles").
     | delta        Optional rating change shown after the number (see x-smash.rating-delta).
     | muted        Softer colour, e.g. for pre-match ratings next to a name.
     */
    'value' => null,
    'user' => null,
    'discipline' => null,
    'provisional' => null,
    'size' => 'md',
    'label' => null,
    'delta' => null,
    'muted' => false,
])

@php
    $discipline = $discipline instanceof \App\Enums\Discipline
        ? $discipline
        : (\App\Enums\Discipline::tryFrom((string) $discipline) ?? \App\Enums\Discipline::Singles);

    if ($value === null && $user instanceof \App\Models\User) {
        $value = $user->ratingExact($discipline);
    }
    $provisional ??= $user instanceof \App\Models\User && $user->isProvisional($discipline);

    $deltaSize = match ($size) {
        'lg', 'xl' => 'md',
        default => 'sm',
    };
@endphp

<x-ui.rating :value="$value" :provisional="(bool) $provisional" :size="$size" :label="$label" :muted="$muted" {{ $attributes }} data-smash-rating>
    @if ($delta !== null)
        <x-smash.rating-delta :value="$delta" :size="$deltaSize" class="ms-1 self-center" />
    @endif
</x-ui.rating>
