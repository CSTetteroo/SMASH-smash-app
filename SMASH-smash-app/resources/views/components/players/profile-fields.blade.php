@props([
    // Copy perspective: true = the member edits their own profile ("you"), false = an admin edits somebody.
    'self' => false,
    // Maximum bio length (keep in sync with the page's validation rule).
    'bioMax' => 500,
])

{{--
    The public player-profile fields (programme, level, handedness, bio), shared by the admin player
    form and the member's own settings page. Binds to the Livewire properties programme, level,
    handedness and bio of the page that renders it.
--}}
@use('App\Enums\Handedness')
@use('App\Enums\Programme')
@use('App\Enums\SkillLevel')

<div {{ $attributes->class('grid gap-6') }}>
    <flux:select
        wire:model="programme"
        :label="__('Programme')"
        :description="$self ? __('Your BUas study programme, or BUas staff.') : __('Their BUas study programme, or BUas staff.')"
        data-test="field-programme"
    >
        <option value="">{{ __('Not set') }}</option>
        @foreach (Programme::cases() as $option)
            <option value="{{ $option->value }}">{{ $option->label() }}</option>
        @endforeach
    </flux:select>

    <flux:radio.group
        wire:model="level"
        variant="cards"
        :label="__('Level')"
        :description="$self ? __('Helps us plan club nights and pair you with the right opponents. Your rating is separate and comes from your results.') : __('Self-assessed playing level. The rating is separate and comes from results.')"
        class="flex-col"
        data-test="field-level"
    >
        @foreach (SkillLevel::cases() as $option)
            <flux:radio :value="$option->value" :label="$option->label()" :description="$option->description()" />
        @endforeach
    </flux:radio.group>

    <flux:radio.group wire:model="handedness" variant="segmented" :label="__('Plays')" data-test="field-handedness">
        @foreach (Handedness::cases() as $option)
            <flux:radio :value="$option->value" :label="$option->label()" />
        @endforeach
    </flux:radio.group>

    <flux:textarea
        wire:model="bio"
        :label="__('Bio')"
        :description="__('A line or two for the public profile. Up to :max characters.', ['max' => $bioMax])"
        :placeholder="$self ? __('Games student, left-hander, still chasing the perfect jump smash.') : __('A line or two about their game.')"
        rows="3"
        resize="vertical"
        maxlength="{{ $bioMax }}"
        data-test="field-bio"
    />
</div>
