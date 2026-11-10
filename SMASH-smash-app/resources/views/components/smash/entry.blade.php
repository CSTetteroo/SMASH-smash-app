@props([
    /*
     | x-smash.entry — a tournament entry: seed, player or pair, entry rating.
     | Eager-load entries with player and partner (the discipline comes from a loaded tournament,
     | else from whether there is a partner — no query either way).
     |
     | entry        App\Models\TournamentEntry (required).
     | seed         Override the seed (default: $entry->seed; null shows "–"); false = no seed box.
     | rating       Show the entry rating (singles rating, or the pair's mean doubles rating).
     | size         sm · md (default) · lg
     | place        1 (champion: trophy) · 2 (runner-up) · null
     | for          A User: highlights the entry they play in ("You").
     | href         Player links: null = players.show, false = none.
     | Slot: trailing actions (e.g. a withdraw button), shown after the rating.
     */
    'entry',
    'seed' => null,
    'rating' => true,
    'size' => 'md',
    'place' => null,
    'for' => null,
    'href' => null,
])

@php
    $showSeed = $seed !== false;
    $seed = $showSeed ? ($seed ?? $entry->seed) : null;
    $members = $entry->members();
    $discipline = $entry->discipline();
    $entryRating = $entry->rating();
    $provisional = $members->contains(fn ($member) => $member->isProvisional($discipline));
    $isYou = $for instanceof \App\Models\User && $entry->hasPlayer($for);

    $seedBox = match ($size) {
        'sm' => 'size-6 text-[13px]',
        'lg' => 'size-9 text-lg',
        default => 'size-7 text-[15px]',
    };
@endphp

<div
    {{ $attributes->class([
        'flex min-w-0 items-center gap-3',
        'bg-navy-50 shadow-[inset_3px_0_0_var(--color-navy-800)] dark:bg-navy-400/8 dark:shadow-[inset_3px_0_0_var(--color-navy-400)]' => $isYou,
    ]) }}
    data-smash-entry
>
    @if ($showSeed)
        <span @class([
            'num inline-flex shrink-0 items-center justify-center font-bold leading-none',
            $seedBox,
            'bg-heading text-canvas' => (int) $seed === 1,
            'border border-line-strong text-ink' => $seed !== null && (int) $seed !== 1,
            'text-ink-muted' => $seed === null,
        ]) title="{{ $seed !== null ? __('Seed :n', ['n' => $seed]) : __('Unseeded') }}">
            <span class="sr-only">{{ $seed !== null ? __('Seed') : __('Unseeded') }}</span>
            <span @if ($seed === null) aria-hidden="true" @endif>{{ $seed ?? '–' }}</span>
        </span>
    @endif

    <x-smash.player :users="$members" :discipline="$discipline" :size="$size === 'lg' ? 'md' : ($size === 'sm' ? 'xs' : 'sm')" :meta="$members->count() > 1 ? false : null" :href="$href" class="min-w-0 flex-1" />

    @if ($place === 1)
        <span class="tone-orange inline-flex shrink-0 items-center gap-1 text-[var(--tone-ink)]" title="{{ __('Champion') }}">
            <flux:icon.trophy variant="mini" class="size-4" />
            <span class="label-caps max-sm:sr-only">{{ __('Champion') }}</span>
        </span>
    @elseif ($place === 2)
        <span class="label-caps shrink-0 text-ink-muted">{{ __('Runner-up') }}</span>
    @endif

    @if ($isYou)
        <x-ui.tag tone="navy" variant="solid" size="sm" class="shrink-0">{{ __('You') }}</x-ui.tag>
    @endif

    @if ($rating)
        <x-smash.rating :value="$entryRating" :provisional="$provisional" :size="$size === 'lg' ? 'md' : 'sm'" class="shrink-0" />
    @endif

    {{ $slot }}
</div>
