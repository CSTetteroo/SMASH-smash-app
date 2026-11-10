@props([
    /*
     | x-tournaments.form — the tournament fields for pages::tournaments.create / .edit. The inputs
     | bind to the page's public $form array (wire:model="form.name" …); the page validates.
     |
     | locked   Field name => reason, for fields that can no longer change (discipline once
     |          players registered, format and scoring once a draw exists). Locked fields render
     |          disabled, and the page keeps the stored value whatever is submitted.
     */
    'locked' => [],
])

@use('App\Enums\Discipline')
@use('App\Enums\TournamentFormat')

@php
    $isLocked = fn (string $field): bool => array_key_exists($field, $locked);
    $scoringLocked = $isLocked('best_of');
    // One note for the whole section once the draw fixes format and scoring.
    $sectionNote = $isLocked('format') ? $locked['format'] : null;
@endphp

<div {{ $attributes->class('space-y-0') }} data-tournament-form>
    <x-ui.form-section :title="__('The basics')" :description="__('The name is what members see on the tournament list, in the header of the draw and on their profile.')">
        <flux:input wire:model="form.name" :label="__('Name')" :placeholder="__('Winter Doubles Showdown')" required maxlength="100" />
        <flux:textarea
            wire:model="form.description"
            :label="__('Description')"
            :description="__('Two or three sentences: who it is for, how it runs, anything special.')"
            rows="4"
            maxlength="2000"
            :placeholder="__('Doubles knockout before the Christmas break. Up to 16 pairs, seeded on the average doubles rating of each pair.')"
        />
        <flux:input wire:model="form.venue" :label="__('Venue')" :placeholder="config('smash.club.venue')" maxlength="120" />
    </x-ui.form-section>

    <x-ui.form-section :title="__('Format')" :description="__('Singles or doubles, knockout or round robin, and how matches are scored.')">
        @if ($sectionNote)
            <x-ui.alert tone="info" icon="lock-closed">{{ $sectionNote }}</x-ui.alert>
        @endif
        <div class="grid gap-6 sm:grid-cols-2">
            <div>
                <flux:radio.group wire:model.live="form.discipline" :label="__('Discipline')" variant="segmented" :disabled="$isLocked('discipline')">
                    @foreach (Discipline::cases() as $discipline)
                        <flux:radio :value="$discipline->value" :label="$discipline->label()" :icon="$discipline->icon()" wire:key="d-{{ $discipline->value }}" />
                    @endforeach
                </flux:radio.group>
                @if ($isLocked('discipline') && ! $sectionNote)
                    <p class="mt-2 text-sm text-ink-muted">{{ $locked['discipline'] }}</p>
                @endif
            </div>
            <div>
                <flux:radio.group wire:model.live="form.format" :label="__('Format')" variant="segmented" :disabled="$isLocked('format')">
                    @foreach (TournamentFormat::cases() as $format)
                        <flux:radio :value="$format->value" :label="$format->label()" wire:key="f-{{ $format->value }}" />
                    @endforeach
                </flux:radio.group>
            </div>
        </div>

        <div class="grid gap-6 sm:grid-cols-2">
            <flux:select wire:model="form.best_of" :label="__('Match length')" :disabled="$scoringLocked">
                <option value="1">{{ __('One game') }}</option>
                <option value="3">{{ __('Best of three games') }}</option>
                <option value="5">{{ __('Best of five games') }}</option>
            </flux:select>
            <flux:select wire:model="form.points_per_game" :label="__('Points per game')" :disabled="$scoringLocked">
                <option value="21">{{ __('21 (cap at 30)') }}</option>
                <option value="15">{{ __('15 (cap at 21)') }}</option>
                <option value="11">{{ __('11 (cap at 15)') }}</option>
            </flux:select>
        </div>

        <flux:switch
            wire:model="form.is_rated"
            :label="__('Rated tournament')"
            :description="__('Results count towards the rankings, weighted a little more than friendlies. Switch off for fun formats.')"
            :disabled="$isLocked('is_rated')"
            align="left"
        />
    </x-ui.form-section>

    <x-ui.form-section :title="__('Dates')" :description="__('Club times are Breda time. Without a deadline, registration stays open until the first serve.')">
        <div class="grid gap-6 sm:grid-cols-2">
            <flux:input type="datetime-local" wire:model="form.starts_at" :label="__('First serve')" required />
            <flux:input type="datetime-local" wire:model="form.ends_at" :label="__('Expected finish')" />
        </div>
        <flux:input type="datetime-local" wire:model="form.registration_closes_at" :label="__('Registration closes')" :description="__('Has to be before the first serve. Optional, like the finish time.')" class="sm:max-w-[calc(50%-0.75rem)]" />
    </x-ui.form-section>

    <x-ui.form-section :title="__('Entries')" :description="__('Knockouts work best with 8, 16 or 32 entries; other numbers give the top seeds a bye. Round robins get long beyond 8.')">
        <flux:input
            type="number"
            wire:model="form.max_entries"
            :label="__('Maximum entries')"
            :description="__('Leave empty for no limit. Counts pairs for doubles.')"
            min="2"
            max="128"
            class="sm:max-w-48"
        />
    </x-ui.form-section>

    {{ $slot }}
</div>
