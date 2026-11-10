@props([
    /*
     | x-club-nights.attendees — overlapping avatars of who's going, plus a sentence
     | ("Sam, Lotte and 16 others are going").
     |
     | users     The going members as Users or ClubNightRsvps with user loaded (first ones are shown),
     |           e.g. RSVPs ordered first come first served.
     | total     How many are going (defaults to the number of users).
     | max       Avatars to show before the "+n" bubble.
     | size      Avatar size: xs · sm
     | sentence  Show the sentence next to the avatars.
     | past      Word the sentence for a night that is over ("went").
     */
    'users' => [],
    'total' => null,
    'max' => 6,
    'size' => 'sm',
    'sentence' => true,
    'past' => false,
])

@php
    // Accepts users or RSVPs (with their user loaded).
    $users = collect($users)
        ->map(fn ($item) => $item instanceof \App\Models\ClubNightRsvp ? $item->user : $item)
        ->filter(fn ($user) => $user instanceof \App\Models\User)
        ->values();
    $total = (int) ($total ?? $users->count());
    $shown = $users->take($max);
    $more = max(0, $total - $shown->count());

    $names = $users->take(2)->map(fn ($user) => $user->firstName())->all();
    $verb = $past ? __('went') : ($total === 1 ? __('is going') : __('are going'));

    $text = match (true) {
        $total === 0 => $past ? __('Nobody RSVP\'d for this night.') : __('Nobody yet. Be the first to RSVP.'),
        $total === 1 => ($names[0] ?? __('One member')).' '.$verb,
        $total === 2 && count($names) === 2 => $names[0].' '.__('and').' '.$names[1].' '.$verb,
        count($names) === 2 => $names[0].', '.$names[1].' '.__('and').' '.trans_choice(':count other|:count others', $total - 2).' '.$verb,
        default => trans_choice(':count member|:count members', $total).' '.$verb,
    };

    $bubble = $size === 'xs' ? 'size-6 text-[11px]' : 'size-8 text-[13px]';
    $overlap = $size === 'xs' ? '-ms-1.5' : '-ms-2';
@endphp

<div {{ $attributes->class('flex min-w-0 flex-wrap items-center gap-x-3 gap-y-2') }} data-club-night-attendees>
    @if ($total > 0)
        <div class="flex shrink-0 items-center" aria-hidden="true">
            @foreach ($shown as $user)
                <span @class(['rounded-full ring-2 ring-surface', $overlap => ! $loop->first]) wire:key="att-{{ $user->id }}">
                    <x-smash.avatar :user="$user" :size="$size" />
                </span>
            @endforeach
            @if ($more > 0)
                <span class="{{ $overlap }} {{ $bubble }} num inline-flex items-center justify-center rounded-full bg-canvas-muted font-bold text-ink-soft ring-2 ring-surface">+{{ $more }}</span>
            @endif
        </div>
    @endif

    @if ($sentence)
        <p class="min-w-[9.5rem] flex-1 text-sm leading-snug text-ink-soft">{{ $text }}</p>
    @endif
</div>
