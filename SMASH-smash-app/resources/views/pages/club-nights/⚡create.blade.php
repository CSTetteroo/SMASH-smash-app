<?php

use App\Enums\ClubNightKind;
use App\Enums\SkillLevel;
use App\Models\ClubNight;
use Carbon\CarbonImmutable;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('New club night')] class extends Component {
    /** The night this form was prefilled from via ?duplicate=<id> (for the hero copy). */
    #[Locked]
    public ?string $duplicatedFrom = null;

    public string $title = '';

    public string $kind = 'social';

    public string $level = '';

    public string $description = '';

    public string $date = '';

    public string $start_time = '19:30';

    public string $end_time = '22:00';

    public string $venue = '';

    public int|string $courts = 4;

    public int|string $capacity = 24;

    /** The title we last filled in for the admin, so we only replace our own suggestion. */
    #[Locked]
    public string $suggestedTitle = '';

    public function mount(): void
    {
        Gate::authorize('create', ClubNight::class);

        $this->venue = (string) config('smash.club.venue');
        $this->courts = (int) config('smash.club.courts', 4);

        // ?duplicate=<id>: prefill from that night, one week later.
        $duplicate = request()->query('duplicate');
        $source = is_string($duplicate) && ctype_digit($duplicate) ? ClubNight::query()->find((int) $duplicate) : null;

        if ($source !== null) {
            $starts = $source->starts_at->addWeek();
            $ends = $source->ends_at->addWeek();

            $this->title = $source->title;
            $this->kind = $source->kind->value;
            $this->level = $source->level?->value ?? '';
            $this->description = (string) $source->description;
            $this->venue = $source->venue;
            $this->courts = $source->courts;
            $this->capacity = $source->capacity;
            $this->date = $starts->toDateString();
            $this->start_time = $starts->format('H:i');
            $this->end_time = $ends->format('H:i');
            $this->duplicatedFrom = $source->title.' · '.$source->starts_at->format('D j M');

            return;
        }

        // Default to the next Tuesday evening.
        $this->date = CarbonImmutable::now()->next(CarbonImmutable::TUESDAY)->toDateString();
        $this->title = $this->suggestedTitle = $this->suggestTitle();
    }

    public function updatedKind(): void
    {
        $this->refreshSuggestedTitle();
    }

    public function updatedDate(): void
    {
        $this->refreshSuggestedTitle();
    }

    public function save(): void
    {
        Gate::authorize('create', ClubNight::class);

        $data = $this->validate($this->rules(), $this->messages());

        $night = ClubNight::query()->create([
            'title' => trim($data['title']),
            'kind' => $data['kind'],
            'level' => $data['level'] === '' || $data['level'] === null ? null : $data['level'],
            'description' => trim((string) $data['description']) ?: null,
            'venue' => trim($data['venue']),
            'starts_at' => CarbonImmutable::createFromFormat('Y-m-d H:i', $data['date'].' '.$data['start_time'])->startOfMinute(),
            'ends_at' => CarbonImmutable::createFromFormat('Y-m-d H:i', $data['date'].' '.$data['end_time'])->startOfMinute(),
            'courts' => (int) $data['courts'],
            'capacity' => (int) $data['capacity'],
            'created_by' => Auth::id(),
        ]);

        Flux::toast(variant: 'success', heading: __('Club night added'), text: __(':title on :date is in the schedule and open for RSVPs.', [
            'title' => $night->title,
            'date' => $night->starts_at->format('D j M'),
        ]));

        $this->redirectRoute('club-nights.show', ['clubNight' => $night->id], navigate: true);
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

    private function refreshSuggestedTitle(): void
    {
        if (trim($this->title) !== '' && $this->title !== $this->suggestedTitle) {
            return;
        }

        $this->title = $this->suggestedTitle = $this->suggestTitle();
    }

    /**
     * "Tuesday social", "Thursday training", "Beginners' clinic", "Ladder night".
     */
    private function suggestTitle(): string
    {
        $day = rescue(fn () => CarbonImmutable::createFromFormat('Y-m-d', $this->date)->format('l'), 'Club', report: false);

        return match (ClubNightKind::tryFrom($this->kind)) {
            ClubNightKind::Training => $day.' training',
            ClubNightKind::Clinic => "Beginners' clinic",
            ClubNightKind::Ladder => 'Ladder night',
            default => $day.' social',
        };
    }
}; ?>

<div>
    <x-ui.page-hero
        tone="white"
        :breadcrumbs="[[__('Club nights'), route('club-nights.index')], __('New club night')]"
        :kicker="__('Committee')"
        :title="__('New club night')"
        :intro="$duplicatedFrom
            ? __('A copy of :night, one week later. Check the details and save to open it for RSVPs.', ['night' => $duplicatedFrom])
            : __('Add a session to the schedule. It opens for RSVPs as soon as you save, and appears in everyone\'s calendar feed.')"
    />

    <x-ui.section width="narrow" spacing="md">
        <x-club-nights.form :submit="__('Add club night')" :cancel="route('club-nights.index')" />
    </x-ui.section>
</div>
