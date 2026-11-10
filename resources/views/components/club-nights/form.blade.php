@props([
    /*
     | x-club-nights.form — the create / edit form for a club night. The surrounding Livewire page
     | holds the fields (title, kind, level, description, date, start_time, end_time, venue, courts,
     | capacity) and implements save().
     |
     | submit   Label of the green submit button.
     | cancel   URL of the Cancel button.
     | going    Members already going (edit): explains what changing the places does.
     | waiting  Members on the waitlist (edit).
     | Slot "secondary": actions on the left of the footer (e.g. Delete).
     */
    'submit' => 'Save club night',
    'cancel' => null,
    'going' => 0,
    'waiting' => 0,
])

@php
    $cancel ??= route('club-nights.index');
@endphp

<form wire:submit="save" novalidate {{ $attributes }} data-test="club-night-form">
    <x-ui.form-section :title="__('The night')" :description="__('What kind of evening it is and who it suits. The title is what members see in the schedule and in their calendar.')">
        <flux:input wire:model="title" :label="__('Title')" :placeholder="__('Tuesday social')" maxlength="120" autocomplete="off" />

        <flux:radio.group wire:model.live="kind" :label="__('Kind of night')" variant="cards" class="grid gap-3 sm:grid-cols-2">
            @foreach (\App\Enums\ClubNightKind::cases() as $option)
                <flux:radio :value="$option->value" :label="$option->label()" :description="$option->description()" wire:key="kind-{{ $option->value }}" />
            @endforeach
        </flux:radio.group>

        <flux:select wire:model="level" :label="__('Recommended level')" :description="__('A guide, not a rule. Leave it on all levels for social play.')">
            <option value="">{{ __('All levels welcome') }}</option>
            @foreach (\App\Enums\SkillLevel::cases() as $option)
                <option value="{{ $option->value }}" wire:key="level-{{ $option->value }}">{{ $option->label() }}</option>
            @endforeach
        </flux:select>

        <flux:textarea wire:model="description" :label="__('Description')" rows="4" maxlength="2000" :placeholder="__('What\'s the focus tonight? Anything people should know or bring?')" :description="__('Optional. Shown on the club night page and in the calendar feed.')" />
    </x-ui.form-section>

    <x-ui.form-section :title="__('When and where')" :description="__('Tuesdays usually run 19:30 – 22:00 and Thursdays 20:00 – 22:30. Times are Breda time.')">
        <div class="grid grid-cols-2 gap-6 sm:grid-cols-3">
            <flux:input type="date" wire:model="date" :label="__('Date')" class="col-span-2 sm:col-span-1" />
            <flux:input type="time" wire:model="start_time" :label="__('Starts')" step="300" />
            <flux:input type="time" wire:model="end_time" :label="__('Ends')" step="300" />
        </div>

        <flux:input wire:model="venue" :label="__('Venue')" maxlength="120" :description="__('The usual hall is :venue.', ['venue' => config('smash.club.venue')])" />
    </x-ui.form-section>

    <x-ui.form-section :title="__('Courts and places')" :description="__('About six players per court keeps everyone playing. Once every place is taken, new RSVPs join the waitlist.')">
        <div class="grid gap-6 sm:grid-cols-2">
            <flux:input type="number" wire:model="courts" :label="__('Courts')" min="1" max="12" inputmode="numeric" />
            <flux:input type="number" wire:model="capacity" :label="__('Places')" min="1" max="200" inputmode="numeric" />
        </div>

        @if ($going > 0 || $waiting > 0)
            <x-ui.alert tone="info" :title="trans_choice(':count member is going|:count members are going', $going).($waiting > 0 ? ' · '.__(':count on the waitlist', ['count' => $waiting]) : '')">
                {{ __('More places move people up from the waitlist straight away, first come first served. Fewer places never removes anyone who is already going; new RSVPs simply join the waitlist.') }}
            </x-ui.alert>
        @endif
    </x-ui.form-section>

    <x-ui.form-actions>
        @isset($secondary)
            <x-slot:secondary>{{ $secondary }}</x-slot:secondary>
        @endisset
        <x-ui.button variant="ghost" :href="$cancel">{{ __('Cancel') }}</x-ui.button>
        <x-ui.button variant="cta" type="submit">{{ $submit }}</x-ui.button>
    </x-ui.form-actions>
</form>
