@props([
    /*
     | x-club-nights.rsvp — the RSVP control for a club night, for the signed-in member.
     | States: guest ("Log in to RSVP") · open ("I'm coming") · full ("Join the waitlist") ·
     | going ("You're going" + Leave) · waitlist ("On the waitlist · #2" + Leave) · past.
     |
     | The surrounding Livewire page must implement join(int $id), leave(int $id) and
     | confirmLeave(int $id) (asks first when giving up a place others are waiting for).
     |
     | night      ClubNight, ideally with rsvps loaded (then this renders without queries).
     | rsvp       The member's ClubNightRsvp; looked up from the night when omitted.
     | prominent  Green CTA buttons and larger chips (hero cards); default navy/outline.
     | explain    A sentence under the control explaining the state and the waitlist rule.
     */
    'night',
    'rsvp' => null,
    'prominent' => false,
    'explain' => false,
])

@php
    $user = auth()->user();
    $rsvp ??= $user !== null ? $night->rsvpFor($user) : null;

    $waiting = $night->waitlistCount();
    $spots = $night->spotsLeft();

    $state = match (true) {
        $night->isPast() => 'past',
        $user === null => 'guest',
        $rsvp?->isGoing() === true => 'going',
        $rsvp?->isWaitlisted() === true => 'waitlist',
        $night->isFull() => 'full',
        default => 'open',
    };

    $position = $state === 'waitlist' ? $night->waitlistPosition($user) : null;
    $leaveAction = $state === 'going' && $waiting > 0 ? 'confirmLeave('.$night->id.')' : 'leave('.$night->id.')';

    $buttonSize = $prominent ? 'md' : 'sm';
    $chipSize = $prominent ? 'lg' : 'md';
@endphp

<div {{ $attributes->class('min-w-0') }} data-rsvp-state="{{ $state }}">
    <div class="flex flex-wrap items-center gap-x-3 gap-y-2">
        @switch($state)
            @case('past')
                @if ($rsvp?->isCheckedIn())
                    <x-ui.tag tone="green" variant="soft" icon="check" :size="$chipSize">{{ __('You were there') }}</x-ui.tag>
                @elseif ($rsvp?->isGoing())
                    <x-ui.tag tone="grey" icon="check" :size="$chipSize">{{ __('You were going') }}</x-ui.tag>
                @else
                    <x-ui.tag tone="grey" :size="$chipSize" icon="flag">{{ __('Finished') }}</x-ui.tag>
                @endif
                @break

            @case('guest')
                <x-ui.button :variant="$prominent ? 'cta' : 'outline'" :size="$buttonSize" icon="arrow-right-end-on-rectangle" :arrow="false" class="max-sm:min-h-11" wire:click="join({{ $night->id }})">
                    {{ $night->isFull() ? __('Log in to join the waitlist') : __('Log in to RSVP') }}
                </x-ui.button>
                @break

            @case('going')
                <x-ui.tag tone="green" variant="soft" icon="check" :size="$chipSize">{{ __('You\'re going') }}</x-ui.tag>
                <x-ui.button variant="ghost" size="sm" class="max-sm:min-h-11" wire:click="{{ $leaveAction }}">{{ __('Leave') }}</x-ui.button>
                @break

            @case('waitlist')
                <x-ui.tag tone="orange" variant="soft" icon="clock" :size="$chipSize">{{ __('On the waitlist') }}@if ($position) · #{{ $position }}@endif</x-ui.tag>
                <x-ui.button variant="ghost" size="sm" class="max-sm:min-h-11" wire:click="leave({{ $night->id }})">{{ __('Leave waitlist') }}</x-ui.button>
                @break

            @case('full')
                <x-ui.button :variant="$prominent ? 'cta' : 'outline'" :size="$buttonSize" class="max-sm:min-h-11" wire:click="join({{ $night->id }})">{{ __('Join the waitlist') }}</x-ui.button>
                @break

            @default
                <x-ui.button :variant="$prominent ? 'cta' : 'primary'" :size="$buttonSize" class="max-sm:min-h-11" wire:click="join({{ $night->id }})">{{ __('I\'m coming') }}</x-ui.button>
        @endswitch
    </div>

    @if ($explain)
        <p class="mt-3 text-sm leading-relaxed text-ink-soft">
            @switch($state)
                @case('past')
                    {{ __('This club night is over. RSVPs are closed.') }}
                    @break
                @case('guest')
                    {{ __('Log in with your SMASH account to RSVP.') }}
                    @if (Route::has('register'))
                        <a href="{{ route('register') }}" class="link">{{ __('New here? Create an account') }}</a>.
                    @endif
                    @break
                @case('going')
                    @if ($waiting > 0)
                        {{ __('Can\'t make it after all? Leave, and the first person on the waitlist gets your place straight away.') }}
                    @else
                        {{ __('Can\'t make it after all? Leave so someone else can have your place.') }}
                    @endif
                    @break
                @case('waitlist')
                    {{ __('You\'re #:position in line. When someone who\'s going cancels, the first person on the waitlist moves up automatically.', ['position' => $position ?? 1]) }}
                    {{ __('Check back here before the night.') }}
                    @break
                @case('full')
                    {{ __('All :capacity places are taken.', ['capacity' => $night->capacity]) }}
                    {{ __('Join the waitlist: when someone cancels, the first person waiting gets their place automatically.') }}
                    @break
                @default
                    {{ trans_choice(':count place left.|:count places left.', $spots) }}
                    {{ __('RSVP so the committee knows how many courts to set up.') }}
            @endswitch
        </p>
    @endif
</div>
