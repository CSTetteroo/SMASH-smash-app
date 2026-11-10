<?php

use App\Actions\ClubNights\PromoteFromWaitlist;
use App\Enums\ClubNightKind;
use App\Enums\SkillLevel;
use App\Models\ClubNight;
use Carbon\CarbonImmutable;
use Flux\Flux;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Edit club night')] class extends Component {
    #[Locked]
    public ClubNight $clubNight;

    public string $title = '';

    public string $kind = 'social';

    public string $level = '';

    public string $description = '';

    public string $date = '';

    public string $start_time = '';

    public string $end_time = '';

    public string $venue = '';

    public int|string $courts = 4;

    public int|string $capacity = 24;

    public function mount(ClubNight $clubNight): void
    {
        $this->authorize('update', $clubNight);

        $this->clubNight = $clubNight;

        $this->title = $clubNight->title;
        $this->kind = $clubNight->kind->value;
        $this->level = $clubNight->level?->value ?? '';
        $this->description = (string) $clubNight->description;
        $this->date = $clubNight->starts_at->toDateString();
        $this->start_time = $clubNight->starts_at->format('H:i');
        $this->end_time = $clubNight->ends_at->format('H:i');
        $this->venue = $clubNight->venue;
        $this->courts = $clubNight->courts;
        $this->capacity = $clubNight->capacity;
    }

    /**
     * Current RSVP numbers, for the capacity note and the delete confirmation.
     *
     * @return array{going: int, waiting: int}
     */
    #[Computed]
    public function counts(): array
    {
        $night = ClubNight::query()->withRsvpCounts()->findOrFail($this->clubNight->id);

        return ['going' => $night->goingCount(), 'waiting' => $night->waitlistCount()];
    }

    public function save(): void
    {
        $this->authorize('update', $this->clubNight);

        $data = $this->validate($this->rules(), $this->messages());

        $night = $this->clubNight;

        $night->fill([
            'title' => trim($data['title']),
            'kind' => $data['kind'],
            'level' => $data['level'] === '' || $data['level'] === null ? null : $data['level'],
            'description' => trim((string) $data['description']) ?: null,
            'venue' => trim($data['venue']),
            'starts_at' => CarbonImmutable::createFromFormat('Y-m-d H:i', $data['date'].' '.$data['start_time'])->startOfMinute(),
            'ends_at' => CarbonImmutable::createFromFormat('Y-m-d H:i', $data['date'].' '.$data['end_time'])->startOfMinute(),
            'courts' => (int) $data['courts'],
            'capacity' => (int) $data['capacity'],
        ])->save();

        // More places: the waitlist moves up straight away (first come, first served).
        $promoted = $night->isPast() ? 0 : PromoteFromWaitlist::handle($night);

        Flux::toast(
            variant: 'success',
            heading: __('Club night updated'),
            text: $promoted > 0
                ? trans_choice('Saved. :count member moved up from the waitlist.|Saved. :count members moved up from the waitlist.', $promoted)
                : __('Your changes to :title are saved.', ['title' => $night->title]),
        );

        $this->redirectRoute('club-nights.show', ['clubNight' => $night->id], navigate: true);
    }

    public function delete(): void
    {
        $this->authorize('delete', $this->clubNight);

        $title = $this->clubNight->title;
        $date = $this->clubNight->starts_at->format('D j M');

        $this->clubNight->delete();

        Flux::toast(variant: 'success', text: __(':title on :date has been deleted.', ['title' => $title, 'date' => $date]));

        $this->redirectRoute('club-nights.index', navigate: true);
    }

    /**
     * @return array<string, mixed>
     */
    protected function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:120'],
            'kind' => ['required', 'string', Rule::enum(ClubNightKind::class)],
            'level' => ['nullable', 'string', Rule::enum(SkillLevel::class)],
            'description' => ['nullable', 'string', 'max:2000'],
            'date' => ['required', 'date_format:Y-m-d'],
            'start_time' => ['required', 'date_format:H:i'],
            'end_time' => ['required', 'date_format:H:i', 'after:start_time'],
            'venue' => ['required', 'string', 'max:120'],
            'courts' => ['required', 'integer', 'min:1', 'max:12'],
            'capacity' => ['required', 'integer', 'min:1', 'max:200'],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function messages(): array
    {
        return [
            'title.required' => __('Give the night a title, for example “Tuesday social”.'),
            'date.required' => __('Pick the date of the club night.'),
            'start_time.required' => __('When does it start?'),
            'end_time.required' => __('When does it end?'),
            'end_time.after' => __('The night has to end after it starts.'),
            'venue.required' => __('Where is it? Usually :venue.', ['venue' => config('smash.club.venue')]),
            'courts.min' => __('A club night needs at least one court.'),
            'capacity.min' => __('There has to be at least one place.'),
        ];
    }
}; ?>

@php
    $night = $this->clubNight;
    $counts = $this->counts;
    $total = $counts['going'] + $counts['waiting'];
@endphp

<div>
    <x-ui.page-hero
        tone="white"
        :breadcrumbs="[[__('Club nights'), route('club-nights.index')], [$night->title, route('club-nights.show', $night)], __('Edit')]"
        :kicker="__('Committee') . ' · ' . $night->starts_at->format('D j M')"
        :title="__('Edit club night')"
        :intro="__('Changes show up on the club night page and in everyone\'s calendar feed straight away. RSVPs are kept.')"
    />

    <x-ui.section width="narrow" spacing="md">
        @if ($night->isPast())
            <x-ui.alert tone="warning" :title="__('This club night is over')" class="mb-8">
                {{ __('You can still correct the details for the records. RSVPs and check-ins stay as they are.') }}
            </x-ui.alert>
        @endif

        <x-club-nights.form :submit="__('Save changes')" :cancel="route('club-nights.show', $night)" :going="$counts['going']" :waiting="$counts['waiting']">
            <x-slot:secondary>
                <flux:modal.trigger name="delete-night">
                    <x-ui.button variant="danger-outline" size="sm" icon="trash">{{ __('Delete') }}</x-ui.button>
                </flux:modal.trigger>
            </x-slot:secondary>
        </x-club-nights.form>
    </x-ui.section>

    <flux:modal name="delete-night" class="md:w-[30rem]">
        <div class="space-y-6">
            <div>
                <flux:heading size="lg">{{ __('Delete :title?', ['title' => $night->title]) }}</flux:heading>
                <flux:text class="mt-2">
                    {{ __('The club night on :date is removed from the schedule and the calendar feed.', ['date' => $night->starts_at->format('l j F')]) }}
                    @if ($total > 0)
                        {{ trans_choice('Its :count RSVP is deleted with it.|All :count RSVPs and check-ins are deleted with it.', $total) }}
                    @endif
                    {{ __('Matches played that evening stay in the results. This cannot be undone.') }}
                </flux:text>
            </div>
            <div class="flex flex-wrap justify-end gap-2">
                <flux:modal.close>
                    <x-ui.button variant="ghost" size="sm">{{ __('Cancel') }}</x-ui.button>
                </flux:modal.close>
                <x-ui.button variant="danger" size="sm" icon="trash" wire:click="delete">{{ __('Delete club night') }}</x-ui.button>
            </div>
        </div>
    </flux:modal>
</div>
