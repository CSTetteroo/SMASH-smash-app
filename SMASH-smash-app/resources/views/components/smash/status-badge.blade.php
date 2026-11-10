@props([
    /*
     | x-smash.status-badge — status chip in the enum's tone() (built on x-ui.tag / x-ui.live).
     |
     | status       MatchStatus · TournamentStatus · RsvpStatus · ClubNightKind (any enum with
     |              label() and tone()). MatchStatus::InProgress renders the pulsing LIVE badge.
     | match        BadmintonMatch instead of a status: also detects byes ("Bye").
     | size         sm · md (default) · lg
     | variant      outline (default) · soft · solid
     | live         Look of the LIVE badge: solid (red chip, default) · text (red dot + LIVE).
     | label        Override the text.
     */
    'status' => null,
    'match' => null,
    'size' => 'md',
    'variant' => 'outline',
    'live' => 'solid',
    'label' => null,
])

@php
    $status ??= $match?->status;
    $isBye = $match instanceof \App\Models\BadmintonMatch && $match->isBye();

    $tone = $status instanceof \BackedEnum && method_exists($status, 'tone') ? $status->tone() : 'grey';
    $text = $label ?? ($isBye ? __('Bye') : ($status instanceof \BackedEnum && method_exists($status, 'label') ? $status->label() : ''));

    // Dots mark states that are "happening": open registration, a tournament under way.
    $dot = in_array($status, [\App\Enums\TournamentStatus::Open, \App\Enums\TournamentStatus::InProgress], true);
@endphp

@if ($status === \App\Enums\MatchStatus::InProgress && ! $isBye)
    <x-ui.live :variant="$live" :label="$label" {{ $attributes }} data-smash-status="in_progress" />
@elseif ($text !== '')
    <x-ui.tag
        :tone="$isBye ? 'grey' : $tone"
        :size="$size"
        :variant="$variant"
        :dot="$dot"
        {{ $attributes }}
        data-smash-status="{{ $isBye ? 'bye' : ($status instanceof \BackedEnum ? $status->value : '') }}"
    >{{ $text }}</x-ui.tag>
@endif
