<?php

use App\Livewire\Forms\MatchForm;
use App\Models\BadmintonMatch;
use App\Models\User;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Log a match')] class extends Component {
    public MatchForm $form;

    public function mount(): void
    {
        Gate::authorize('create', BadmintonMatch::class);

        $this->form->start($this->actor());
    }

    public function addGame(): void
    {
        $this->form->addGame();
    }

    public function removeGame(int $index): void
    {
        $this->form->removeGame($index);
    }

    /**
     * Members log matches they played in (enforced by MatchForm's rules and by SaveMatch);
     * admins can enter any match.
     */
    public function save(): void
    {
        Gate::authorize('create', BadmintonMatch::class);

        $match = $this->form->save($this->actor());

        Flux::toast(
            variant: 'success',
            text: $match->isFinished() && $match->is_rated
                ? __('Result saved. Ratings have been updated.')
                : __('Match saved.'),
        );

        $this->redirectRoute('matches.show', ['match' => $match->id], navigate: true);
    }

    /**
     * Players who can be picked, keyed by id.
     *
     * @return Collection<int, User>
     */
    #[Computed]
    public function people(): Collection
    {
        return MatchForm::eligiblePlayers()->keyBy('id');
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
    $stakes = $form->stakes();
@endphp

<div>
    <x-ui.page-hero
        tone="white"
        :breadcrumbs="[[__('Matches'), route('matches.index')], __('Log a match')]"
        :kicker="__('Matches')"
        :title="__('Log a match')"
        :intro="$actor->isAdmin()
            ? __('Add a friendly result or a fixture for any members. Tournament matches are entered from their draw.')
            : __('Played a friendly at club night? Enter the score and the rankings update straight away. Planning a challenge? Add it as a scheduled match.')"
    />

    <x-ui.section width="narrow" spacing="md">
        <x-matches.form
            :form="$form"
            :actor="$actor"
            :people="$this->people"
            :statuses="\App\Livewire\Forms\MatchForm::statusOptions($actor)"
            :check="$form->scoreCheck()"
            :stakes="$stakes"
            :preview="$form->preview($this->people, null, $stakes)"
        >
            <x-slot:actions>
                <x-ui.button variant="ghost" :href="route('matches.index')">{{ __('Cancel') }}</x-ui.button>
                <x-ui.button variant="cta" type="submit" data-test="save-match">{{ __('Save match') }}</x-ui.button>
            </x-slot:actions>
        </x-matches.form>
    </x-ui.section>
</div>
