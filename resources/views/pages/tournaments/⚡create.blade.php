<?php

use App\Enums\Discipline;
use App\Enums\TournamentFormat;
use App\Enums\TournamentStatus;
use App\Models\Tournament;
use Carbon\CarbonInterface;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('New tournament')] class extends Component {
    /**
     * The fields of x-tournaments.form.
     *
     * @var array<string, mixed>
     */
    public array $form = [];

    /** Publish straight away (status Open) instead of saving a draft. */
    public bool $openNow = false;

    public function mount(): void
    {
        $this->authorize('create', Tournament::class);

        // A sensible default: a Saturday about a month out, 10:00 – 16:00, closing two days before.
        $start = now()->addWeeks(4)->next(CarbonInterface::SATURDAY)->setTime(10, 0);

        $this->form = [
            'name' => '',
            'description' => '',
            'discipline' => Discipline::Singles->value,
            'format' => TournamentFormat::SingleElimination->value,
            'venue' => (string) config('smash.club.venue'),
            'starts_at' => $start->format('Y-m-d\TH:i'),
            'ends_at' => $start->setTime(16, 0)->format('Y-m-d\TH:i'),
            'registration_closes_at' => $start->subDays(2)->setTime(23, 0)->format('Y-m-d\TH:i'),
            'max_entries' => '16',
            'best_of' => '3',
            'points_per_game' => '21',
            'is_rated' => true,
        ];
    }

    public function save(): void
    {
        $this->authorize('create', Tournament::class);

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
                'form.max_entries' => ['nullable', 'integer', 'min:2', 'max:128'],
                'form.best_of' => ['required', 'integer', Rule::in([1, 3, 5])],
                'form.points_per_game' => ['required', 'integer', Rule::in([11, 15, 21])],
                'form.is_rated' => ['boolean'],
            ],
            [
                'form.ends_at.after' => __('The tournament has to finish after the first serve.'),
                'form.registration_closes_at.before_or_equal' => __('Registration has to close before the first serve.'),
                'form.max_entries.min' => __('Leave room for at least two entries.'),
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

        $tournament = Tournament::create([
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
            'status' => $this->openNow ? TournamentStatus::Open : TournamentStatus::Draft,
            'created_by' => Auth::id(),
        ]);

        Flux::toast(
            variant: 'success',
            text: $this->openNow
                ? __(':name is live and open for registration.', ['name' => $tournament->name])
                : __(':name is saved as a draft. Open registration from the committee desk when you are ready.', ['name' => $tournament->name]),
        );

        $this->redirectRoute('tournaments.show', ['tournament' => $tournament], navigate: true);
    }
}; ?>

<div>
    <x-ui.page-hero
        tone="white"
        :breadcrumbs="[['Tournaments', route('tournaments.index')], 'New tournament']"
        kicker="Committee"
        title="New tournament"
        intro="Set it up as a draft first: only admins see it until you open registration."
    />

    <x-ui.section width="narrow" spacing="md">
        <form wire:submit="save">
            <x-tournaments.form>
                <x-ui.form-section :title="__('Publishing')" :description="__('Drafts are hidden from members. You can open registration later from the tournament page.')">
                    <flux:checkbox wire:model="openNow" :label="__('Open registration straight away')" :description="__('Members see the tournament and can sign up as soon as you save.')" />
                </x-ui.form-section>
            </x-tournaments.form>

            <x-ui.form-actions>
                <x-ui.button variant="ghost" :href="route('tournaments.index')">{{ __('Cancel') }}</x-ui.button>
                <x-ui.button variant="cta" type="submit">{{ __('Create tournament') }}</x-ui.button>
            </x-ui.form-actions>
        </form>
    </x-ui.section>
</div>
