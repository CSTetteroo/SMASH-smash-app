<?php

use App\Actions\Tournaments\DeleteTournament;
use App\Enums\Discipline;
use App\Enums\TournamentFormat;
use App\Models\Tournament;
use Flux\Flux;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;

new class extends Component {
    #[Locked]
    public Tournament $tournament;

    /**
     * The fields of x-tournaments.form.
     *
     * @var array<string, mixed>
     */
    public array $form = [];

    public function mount(Tournament $tournament): void
    {
        $this->authorize('update', $tournament);

        $this->tournament = $tournament;
        $this->form = [
            'name' => $tournament->name,
            'description' => (string) $tournament->description,
            'discipline' => $tournament->discipline->value,
            'format' => $tournament->format->value,
            'venue' => (string) $tournament->venue,
            'starts_at' => $tournament->starts_at->format('Y-m-d\TH:i'),
            'ends_at' => $tournament->ends_at?->format('Y-m-d\TH:i') ?? '',
            'registration_closes_at' => $tournament->registration_closes_at?->format('Y-m-d\TH:i') ?? '',
            'max_entries' => $tournament->max_entries === null ? '' : (string) $tournament->max_entries,
            'best_of' => (string) $tournament->best_of,
            'points_per_game' => (string) $tournament->points_per_game,
            'is_rated' => $tournament->is_rated,
        ];
    }

    public function render()
    {
        return $this->view()->title(__('Edit :name', ['name' => $this->tournament->name]));
    }

    #[Computed]
    public function entryCount(): int
    {
        return $this->tournament->entries()->count();
    }

    #[Computed]
    public function hasDraw(): bool
    {
        return $this->tournament->matches()->exists();
    }

    /**
     * Fields that can no longer change, with the reason shown under them.
     *
     * @return array<string, string>
     */
    #[Computed]
    public function locked(): array
    {
        $locked = [];

        if ($this->entryCount > 0) {
            $locked['discipline'] = __('Players have registered, so the discipline is fixed.');
        }

        if ($this->hasDraw) {
            $reason = __('The draw is made. Reset the draw to change the format or scoring.');
            $locked['discipline'] = $reason;
            $locked['format'] = $reason;
            $locked['best_of'] = $reason;
            $locked['points_per_game'] = $reason;
            $locked['is_rated'] = $reason;
        }

        return $locked;
    }

    public function save(): void
    {
        $this->authorize('update', $this->tournament);

        // Locked fields keep their stored value, whatever was submitted.
        foreach (array_keys($this->locked) as $field) {
            $this->form[$field] = match ($field) {
                'discipline' => $this->tournament->discipline->value,
                'format' => $this->tournament->format->value,
                'is_rated' => $this->tournament->is_rated,
                default => (string) $this->tournament->{$field},
            };
        }

        $minimum = max(2, $this->entryCount);

        $data = $this->validate(
            [
                'form.name' => ['required', 'string', 'max:100'],
                'form.description' => ['nullable', 'string', 'max:2000'],
                'form.discipline' => ['required', Rule::enum(Discipline::class)],
                'form.format' => ['required', Rule::enum(TournamentFormat::class)],
                'form.venue' => ['nullable', 'string', 'max:120'],
                'form.starts_at' => ['required', 'date'],
                'form.ends_at' => ['nullable', 'date', 'after:form.starts_at'],
                'form.registration_closes_at' => ['nullable', 'date', 'before_or_equal:form.starts_at'],
                'form.max_entries' => ['nullable', 'integer', 'min:'.$minimum, 'max:128'],
                'form.best_of' => ['required', 'integer', Rule::in([1, 3, 5])],
                'form.points_per_game' => ['required', 'integer', Rule::in([11, 15, 21])],
                'form.is_rated' => ['boolean'],
            ],
            [
                'form.ends_at.after' => __('The tournament has to finish after the first serve.'),
                'form.registration_closes_at.before_or_equal' => __('Registration has to close before the first serve.'),
                'form.max_entries.min' => $minimum > 2
                    ? __(':n entries are already in, so the maximum cannot be lower than that.', ['n' => $minimum])
                    : __('Leave room for at least two entries.'),
            ],
            [
                'form.name' => __('name'),
                'form.description' => __('description'),
                'form.discipline' => __('discipline'),
                'form.format' => __('format'),
                'form.venue' => __('venue'),
                'form.starts_at' => __('first serve'),
                'form.ends_at' => __('expected finish'),
                'form.registration_closes_at' => __('registration deadline'),
                'form.max_entries' => __('maximum entries'),
                'form.best_of' => __('match length'),
                'form.points_per_game' => __('points per game'),
            ],
        )['form'];

        $this->tournament->update([
            'name' => trim($data['name']),
            'description' => filled($data['description'] ?? null) ? trim($data['description']) : null,
            'discipline' => $data['discipline'],
            'format' => $data['format'],
            'venue' => filled($data['venue'] ?? null) ? trim($data['venue']) : null,
            'starts_at' => $data['starts_at'],
            'ends_at' => filled($data['ends_at'] ?? null) ? $data['ends_at'] : null,
            'registration_closes_at' => filled($data['registration_closes_at'] ?? null) ? $data['registration_closes_at'] : null,
            'max_entries' => filled($data['max_entries'] ?? null) ? (int) $data['max_entries'] : null,
            'best_of' => (int) $data['best_of'],
            'points_per_game' => (int) $data['points_per_game'],
            'is_rated' => (bool) ($data['is_rated'] ?? false),
        ]);

        Flux::toast(variant: 'success', text: __('Changes to :name saved.', ['name' => $this->tournament->name]));

        $this->redirectRoute('tournaments.show', ['tournament' => $this->tournament], navigate: true);
    }

    public function delete(): void
    {
        $this->authorize('delete', $this->tournament);

        $name = $this->tournament->name;
        DeleteTournament::handle($this->tournament);

        Flux::toast(text: __(':name has been deleted.', ['name' => $name]));

        $this->redirectRoute('tournaments.index', navigate: true);
    }
}; ?>

<div>
    <x-ui.page-hero
        tone="white"
        :breadcrumbs="[['Tournaments', route('tournaments.index')], [$tournament->name, route('tournaments.show', $tournament)], 'Edit']"
        kicker="Committee"
        :title="__('Edit :name', ['name' => $tournament->name])"
        :intro="$this->hasDraw
            ? __('The draw is made, so the format and scoring are fixed. Names, dates, venue and the description can still change.')
            : __('Changes show up straight away on the tournament page.')"
    >
        <x-slot:meta>
            <x-smash.status-badge :status="$tournament->status" />
            <x-ui.tag tone="grey">{{ trans_choice(':n entry|:n entries', $this->entryCount, ['n' => $this->entryCount]) }}</x-ui.tag>
        </x-slot:meta>
    </x-ui.page-hero>

    <x-ui.section width="narrow" spacing="md">
        <form wire:submit="save">
            <x-tournaments.form :locked="$this->locked" />

            <x-ui.form-actions>
                <x-slot:secondary>
                    <flux:modal.trigger name="delete-tournament">
                        <x-ui.button variant="danger-outline" size="sm" icon="trash" data-test="delete-tournament">{{ __('Delete') }}</x-ui.button>
                    </flux:modal.trigger>
                </x-slot:secondary>
                <x-ui.button variant="ghost" :href="route('tournaments.show', $tournament)">{{ __('Cancel') }}</x-ui.button>
                <x-ui.button variant="cta" type="submit">{{ __('Save changes') }}</x-ui.button>
            </x-ui.form-actions>
        </form>
    </x-ui.section>

    <flux:modal name="delete-tournament" class="md:w-[30rem]">
        <div class="space-y-6">
            <div>
                <flux:heading size="lg">{{ __('Delete :name?', ['name' => $tournament->name]) }}</flux:heading>
                <flux:text class="mt-2">
                    @if ($this->hasDraw)
                        {{ trans_choice('This deletes the tournament, its :n entry and every match in the draw with their results and predictions. Ratings are recalculated without them. This cannot be undone.|This deletes the tournament, its :n entries and every match in the draw with their results and predictions. Ratings are recalculated without them. This cannot be undone.', $this->entryCount, ['n' => $this->entryCount]) }}
                    @else
                        {{ trans_choice('This deletes the tournament and its :n entry. This cannot be undone.|This deletes the tournament and its :n entries. This cannot be undone.', $this->entryCount, ['n' => $this->entryCount]) }}
                    @endif
                </flux:text>
            </div>
            <div class="flex justify-end gap-2">
                <flux:modal.close><x-ui.button variant="ghost" size="sm">{{ __('Keep it') }}</x-ui.button></flux:modal.close>
                <x-ui.button variant="danger" size="sm" icon="trash" wire:click="delete">{{ __('Delete tournament') }}</x-ui.button>
            </div>
        </div>
    </flux:modal>
</div>
