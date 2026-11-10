<?php

use App\Actions\Matches\DeleteMatch;
use App\Livewire\Forms\MatchForm;
use App\Models\BadmintonMatch;
use App\Models\User;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;

new class extends Component {
    public const RELATIONS = [
        'participants.user',
        'games',
        'tournament',
        'entryA.player',
        'entryA.partner',
        'entryB.player',
        'entryB.partner',
    ];

    #[Locked]
    public BadmintonMatch $match;

    public MatchForm $form;

    public function mount(BadmintonMatch $match): void
    {
        Gate::authorize('update', $match);

        $this->match = $match->load(self::RELATIONS);
        $this->form->fillFrom($this->match);
    }

    public function render()
    {
        $this->match->loadMissing(self::RELATIONS);

        return $this->view()->title(__('Edit match · :matchup', ['matchup' => $this->match->matchup()]));
    }

    public function addGame(): void
    {
        $this->form->addGame();
    }

    public function removeGame(int $index): void
    {
        $this->form->removeGame($index);
    }

    public function save(): void
    {
        Gate::authorize('update', $this->match);

        $match = $this->form->save($this->actor(), $this->match);

        Flux::toast(
            variant: 'success',
            text: $match->isFinished() ? __('Match updated. Ratings have been recalculated.') : __('Match updated.'),
        );

        $this->redirectRoute('matches.show', ['match' => $match->id], navigate: true);
    }

    /**
     * Friendlies only; tournament matches belong to the draw.
     */
    public function delete(): void
    {
        Gate::authorize('delete', $this->match);

        try {
            DeleteMatch::handle($this->match);
        } catch (ValidationException $exception) {
            Flux::modal('delete-match')->close();
            Flux::toast(variant: 'danger', text: collect($exception->errors())->flatten()->first() ?? __('This match cannot be deleted.'));

            return;
        }

        Flux::toast(variant: 'success', text: __('Match deleted. Ratings have been recalculated.'));

        $this->redirectRoute('matches.index', navigate: true);
    }

    /**
     * @return Collection<int, User>
     */
    #[Computed]
    public function people(): Collection
    {
        return MatchForm::eligiblePlayers($this->match)->keyBy('id');
    }

    private function actor(): User
    {
        /** @var User $user */
        $user = Auth::user();

        return $user;
    }
}; ?>

@php
    $actor = auth()->user();
    $isTournament = $match->tournament_id !== null;
    $finished = $match->isFinished();
    $stakes = $form->stakes($isTournament);
@endphp

<div>
    <x-ui.page-hero
        tone="white"
        :breadcrumbs="[[__('Matches'), route('matches.index')], [$match->matchup(), route('matches.show', $match)], __('Edit')]"
        :kicker="$isTournament ? collect([$match->tournament?->name, $match->roundLabel()])->filter()->join(' · ') : __('Friendly').' · '.$match->discipline->label()"
        :title="__('Edit match')"
        :intro="$isTournament
            ? __('Correct the score, time or court. The players come from the draw, and the winner moves on when you save.')
            : __('Correct the players, score or details. Saving recalculates everyone\'s ratings from the start of the season.')"
    >
        <x-slot:meta>
            <x-smash.status-badge :match="$match" />
            <x-smash.discipline-badge :discipline="$match->discipline" size="md" />
        </x-slot:meta>
    </x-ui.page-hero>

    <x-ui.section width="narrow" spacing="md">
        @if ($isTournament && ($match->entry_a_id === null || $match->entry_b_id === null) && ! $match->isBye())
            <x-ui.alert tone="info" class="mb-8">{{ __('Results can be entered once both sides of this tournament match are known. Until then you can change the time and court, or cancel it.') }}</x-ui.alert>
        @endif

        <x-matches.form
            :form="$form"
            :actor="$actor"
            :match="$match"
            :people="$this->people"
            :statuses="\App\Livewire\Forms\MatchForm::statusOptions($actor, $match)"
            :check="$form->scoreCheck()"
            :stakes="$stakes"
            :preview="$form->preview($this->people, $match, $finished ? null : $stakes)"
            :projected="! $finished"
        >
            <x-slot:secondary>
                @can('delete', $match)
                    <flux:modal.trigger name="delete-match">
                        <x-ui.button variant="danger-outline" size="sm" icon="trash" data-test="delete-button">{{ __('Delete') }}</x-ui.button>
                    </flux:modal.trigger>
                @elseif ($isTournament && $match->tournament)
                    <p class="flex items-center gap-2 text-sm text-ink-soft" data-test="managed-note">
                        <flux:icon.lock-closed variant="micro" class="size-4 shrink-0 text-ink-muted" />
                        <span>{{ __('Managed by the tournament:') }} <a href="{{ route('tournaments.show', $match->tournament) }}" class="link" wire:navigate>{{ $match->tournament->name }}</a></span>
                    </p>
                @endcan
            </x-slot:secondary>
            <x-slot:actions>
                <x-ui.button variant="ghost" :href="route('matches.show', $match)">{{ __('Cancel') }}</x-ui.button>
                <x-ui.button variant="cta" type="submit" data-test="save-match">{{ __('Save changes') }}</x-ui.button>
            </x-slot:actions>
        </x-matches.form>
    </x-ui.section>

    @can('delete', $match)
        <flux:modal name="delete-match" class="md:w-[28rem]">
            <div class="space-y-6">
                <div>
                    <flux:heading size="lg">{{ __('Delete this match?') }}</flux:heading>
                    <flux:text class="mt-2">{{ __('The result disappears from results and profiles, and everyone\'s ratings are recalculated. This cannot be undone.') }}</flux:text>
                </div>
                <div class="flex justify-end gap-2">
                    <flux:modal.close><x-ui.button variant="ghost" size="sm">{{ __('Cancel') }}</x-ui.button></flux:modal.close>
                    <x-ui.button variant="danger" size="sm" wire:click="delete" data-test="confirm-delete">{{ __('Delete match') }}</x-ui.button>
                </div>
            </div>
        </flux:modal>
    @endcan
</div>
