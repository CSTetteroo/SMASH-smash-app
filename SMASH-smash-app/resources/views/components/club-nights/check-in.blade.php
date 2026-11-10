@props([
    /*
     | x-club-nights.check-in — admin toggle to mark an attendee present at the door.
     | Calls toggleCheckIn(rsvpId) on the surrounding Livewire page (which authorises it).
     |
     | rsvp  ClubNightRsvp with its user loaded.
     */
    'rsvp',
])

@php
    $in = $rsvp->isCheckedIn();
    $action = 'toggleCheckIn('.$rsvp->id.')';
@endphp

<button
    type="button"
    wire:click="{{ $action }}"
    wire:loading.attr="disabled"
    wire:target="{{ $action }}"
    aria-pressed="{{ $in ? 'true' : 'false' }}"
    aria-label="{{ __('Checked in: :name', ['name' => $rsvp->user->name]) }}"
    @if ($in) title="{{ __('Checked in at :time. Click to undo.', ['time' => $rsvp->checked_in_at->format('H:i')]) }}" @endif
    {{ $attributes->class([
        'inline-flex min-h-9 shrink-0 items-center gap-2 border px-3 text-[11px] font-semibold uppercase leading-none tracking-label whitespace-nowrap transition-colors duration-150 ease-brand max-sm:min-h-11 disabled:opacity-60',
        'tone-green border-[var(--tone)] bg-[var(--tone-soft)] text-[var(--tone-ink)] hover:bg-transparent' => $in,
        'border-line-strong text-navy-800 hover:border-navy-800 hover:bg-navy-50 dark:text-navy-100 dark:hover:border-navy-300 dark:hover:bg-white/6' => ! $in,
    ]) }}
    data-check-in="{{ $in ? 'in' : 'out' }}"
>
    @if ($in)
        <flux:icon.check variant="micro" class="size-4 shrink-0" />
        <span>{{ __('In') }}</span>
        <span class="num text-[13px] font-semibold tracking-normal opacity-80">{{ $rsvp->checked_in_at->format('H:i') }}</span>
    @else
        <span class="size-3.5 shrink-0 border border-current" aria-hidden="true"></span>
        <span>{{ __('Check in') }}</span>
    @endif
</button>
