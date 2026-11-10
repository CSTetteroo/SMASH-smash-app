@props([
    /*
     | x-smash.player — avatar + name (+ programme and rating), linked to players.show.
     | The canonical player label (x-ui.player with a user renders this component).
     |
     | user         App\Models\User.
     | users        A doubles pair (any iterable of users, e.g. TournamentEntry::members()):
     |              overlapping avatars and "A & B".
     | match + side Or one side of a BadmintonMatch (Side enum, 'a' or 'b'): the entry's players for
     |              tournament matches (even before participants are synced), else the participants;
     |              "TBD" / "Bye" when the side is not known. Discipline follows the match.
     | name         Fallback label when there is no user ("TBD", "Bye", a guest name).
     | discipline   Discipline|string for the rating. Default: singles, doubles for pairs.
     | rating       false (default) · true (current rating; the pair mean for doubles) · a number
     |              (e.g. a pre-match rating from match_players.rating_before).
     | provisional  null = automatic from the user(s) when rating is true.
     | meta         Line under the name. null = the programme label; false = none; or a string.
     | href         null = players.show; false = no link; or a URL (single player only).
     | size         xs · sm · md · lg
     | variant      default (avatar left, meta line) · compact (one line) · stacked (avatar on top)
     | align        start · end (mirrored, for the right-hand side of a versus row)
     | avatar       Show the avatar(s).
     | ring         Avatar accent ring, see x-smash.avatar (e.g. 'auto').
     | short        Family names only ("Jansen & de Vries") for tight spaces.
     | winner       true = bold (winner) · false = dimmed (loser) · null = normal.
     | placeholder  Dashed avatar + muted name (open draw slot).
     | Slot: trailing content after the name block (badges, deltas).
     */
    'user' => null,
    'users' => null,
    'match' => null,
    'side' => null,
    'name' => null,
    'discipline' => null,
    'rating' => false,
    'provisional' => null,
    'meta' => null,
    'href' => null,
    'size' => 'md',
    'variant' => 'default',
    'align' => 'start',
    'avatar' => true,
    'ring' => false,
    'short' => false,
    'winner' => null,
    'placeholder' => false,
])

@php
    if ($match instanceof \App\Models\BadmintonMatch && $users === null && $user === null) {
        $matchSide = $side instanceof \App\Enums\Side ? $side : (\App\Enums\Side::tryFrom((string) $side) ?? \App\Enums\Side::A);
        $entry = $match->tournament_id !== null ? $match->entryOn($matchSide) : null;
        $users = $entry !== null ? $entry->members() : $match->playersOn($matchSide);
        $name ??= $match->sideLabel($matchSide);
        $discipline ??= $match->discipline;
    }

    $members = collect($users ?? ($user !== null ? [$user] : []))
        ->filter(fn ($member) => $member instanceof \App\Models\User)
        ->values();
    $isPair = $members->count() > 1;
    $first = $members->first();

    $discipline = $discipline instanceof \App\Enums\Discipline
        ? $discipline
        : (\App\Enums\Discipline::tryFrom((string) $discipline) ?? ($isPair ? \App\Enums\Discipline::Doubles : \App\Enums\Discipline::Singles));

    // Rating: explicit number, or the current rating (pair mean for doubles).
    $ratingValue = null;
    if (is_numeric($rating)) {
        $ratingValue = (float) $rating;
        $provisional = (bool) $provisional;
    } elseif ($rating === true && $members->isNotEmpty()) {
        $ratingValue = (float) $members->avg(fn ($member) => $member->ratingExact($discipline));
        $provisional ??= $members->contains(fn ($member) => $member->isProvisional($discipline));
    }

    if ($meta === null) {
        $meta = ! $isPair && $first?->programme instanceof \App\Enums\Programme ? $first->programme->label() : null;
    }
    $meta = $meta === false ? null : $meta;

    $placeholder = $placeholder || $members->isEmpty();
    $fallback = $name ?? __('TBD');

    $canLink = Route::has('players.show');
    $linkFor = function ($member) use ($href, $isPair, $canLink): ?string {
        if ($href === false) {
            return null;
        }
        if (is_string($href) && ! $isPair) {
            return $href;
        }

        return $canLink && $member->getKey() !== null ? route('players.show', $member) : null;
    };
    $labelFor = fn ($member): string => $short ? \Illuminate\Support\Str::ucfirst($member->lastName()) : $member->name;

    $compact = $variant === 'compact';
    $stacked = $variant === 'stacked';
    $end = $align === 'end' && ! $stacked;

    $avatarSize = match (true) {
        $stacked => match ($size) { 'xs', 'sm' => 'md', 'lg' => 'xl', default => 'lg' },
        $compact => match ($size) { 'lg' => 'sm', default => 'xs' },
        default => match ($size) { 'xs' => 'xs', 'sm' => 'sm', 'lg' => 'lg', default => 'md' },
    };

    $overlap = match ($avatarSize) {
        'xs' => '-ms-1.5',
        'sm' => '-ms-2',
        'lg' => '-ms-4',
        'xl' => '-ms-5',
        default => '-ms-2.5',
    };

    $nameSize = match ($size) {
        'xs' => 'text-[13px]',
        'sm' => 'text-sm',
        'lg' => $stacked ? 'text-xl' : 'text-lg',
        default => 'text-[15px]',
    };

    $nameTone = match ($winner) {
        true => 'font-bold text-ink',
        false => 'font-medium text-ink-soft',
        default => 'font-semibold text-ink',
    };

    $ratingSize = match ($size) {
        'lg' => 'sm',
        default => 'xs',
    };

    $gap = match (true) {
        $stacked => 'gap-2.5',
        $compact || $size === 'xs' => 'gap-2',
        default => 'gap-3',
    };
@endphp

<span
    {{ $attributes->class([
        'min-w-0',
        $gap,
        'flex flex-col items-center text-center' => $stacked,
        'inline-flex max-w-full items-center' => $compact,
        'flex items-center' => ! $stacked && ! $compact,
        'flex-row-reverse text-end' => $end,
    ]) }}
    data-smash-player
>
    @if ($avatar)
        <span @class(['flex shrink-0 items-center', 'flex-row-reverse' => $end])>
            @if ($placeholder)
                <x-smash.avatar placeholder :size="$avatarSize" />
            @else
                @foreach ($members as $member)
                    <span @class(['rounded-full', $overlap.' ring-2 ring-surface' => ! $loop->first && ! $end, '-me-2.5 ring-2 ring-surface' => ! $loop->first && $end])>
                        <x-smash.avatar :user="$member" :size="$avatarSize" :ring="$ring" />
                    </span>
                @endforeach
            @endif
        </span>
    @endif

    <span @class([
        'min-w-0 leading-tight',
        'flex items-baseline gap-2' => $compact,
        'flex-row-reverse' => $compact && $end,
        'flex flex-col items-center' => $stacked,
    ])>
        <span @class(['block min-w-0 truncate', $nameSize, $placeholder ? 'font-medium italic text-ink-muted' : $nameTone])>
            @if ($placeholder)
                {{ $fallback }}
            @else
                @foreach ($members as $member)
                    @php $memberHref = $linkFor($member); @endphp
                    @if ($memberHref)
                        <a href="{{ $memberHref }}" wire:navigate class="underline-offset-[3px] decoration-1 hover:text-link hover:underline" data-smash-player-link>{{ $labelFor($member) }}</a>@else<span>{{ $labelFor($member) }}</span>@endif
                    @if (! $loop->last)<span class="font-normal text-ink-muted"> &amp; </span>@endif
                @endforeach
            @endif
        </span>

        @if ($compact)
            @if ($ratingValue !== null)
                <x-smash.rating :value="$ratingValue" :provisional="(bool) $provisional" :size="$ratingSize" muted class="shrink-0" />
            @endif
        @elseif ($meta || $ratingValue !== null)
            <span @class([
                'mt-1 flex min-w-0 items-center gap-1.5 text-xs leading-4 text-ink-muted',
                'flex-row-reverse' => $end,
                'justify-center' => $stacked,
            ])>
                @if ($meta)
                    <span class="truncate">{{ $meta }}</span>
                @endif
                @if ($meta && $ratingValue !== null)
                    <span aria-hidden="true">·</span>
                @endif
                @if ($ratingValue !== null)
                    <x-smash.rating :value="$ratingValue" :provisional="(bool) $provisional" size="xs" muted class="shrink-0" />
                @endif
            </span>
        @endif
    </span>

    {{ $slot }}
</span>
