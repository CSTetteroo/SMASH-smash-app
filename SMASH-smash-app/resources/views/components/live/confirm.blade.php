@props([
    /*
     | x-live.confirm — the "save the result" confirmation for a decided match (Flux modal
     | "confirm-result"). Place it outside the navy console so it keeps the page colours.
     |
     | match   BadmintonMatch
     | state   ?App\Support\Live\LiveScore — a decided state, or null (nothing to confirm)
     */
    'match',
    'state' => null,
])

<flux:modal name="confirm-result" class="md:w-[30rem]">
    @if (($result = $state)?->winner() !== null)
        @php
            $winnerSide = $result->winner();
            $won = $result->gamesWon();
            $nextRound = $match->isKnockout() && $match->round !== null && $match->tournament !== null && $match->round < $match->tournament->totalRounds();
        @endphp
        <div class="space-y-6">
            <div>
                <p class="kicker">{{ __('Confirm result') }}</p>
                <flux:heading size="lg" class="mt-1">
                    {{ __(':name won :won–:lost', ['name' => $match->sideLabel($winnerSide), 'won' => $won[$winnerSide->value], 'lost' => $won[$winnerSide->opposite()->value]]) }}
                </flux:heading>
                <x-smash.games class="mt-3" :games="$result->games()" :side="$winnerSide" :target="$match->points_per_game" :live="false" size="lg" />
                <flux:text class="mt-4">
                    {{ __('Saving finishes the match.') }}
                    @if ($match->is_rated)
                        {{ __('Ratings for every player are recalculated.') }}
                    @endif
                    @if ($nextRound)
                        {{ __('The winner moves on to the next round of :tournament.', ['tournament' => $match->tournament->name]) }}
                    @endif
                    {{ __('Only the committee can change it afterwards.') }}
                </flux:text>
            </div>
            <div class="flex flex-wrap justify-end gap-2">
                <flux:modal.close>
                    <x-ui.button variant="ghost" size="sm">{{ __('Keep scoring') }}</x-ui.button>
                </flux:modal.close>
                <x-ui.button variant="cta" size="sm" wire:click="finish" data-test="save-result">{{ __('Save result') }}</x-ui.button>
            </div>
        </div>
    @else
        <div class="space-y-6">
            <div>
                <flux:heading size="lg">{{ __('Nothing to confirm yet') }}</flux:heading>
                <flux:text class="mt-2">{{ __('The result can be saved once a side has won the match.') }}</flux:text>
            </div>
            <div class="flex justify-end">
                <flux:modal.close>
                    <x-ui.button variant="ghost" size="sm">{{ __('Back to the court') }}</x-ui.button>
                </flux:modal.close>
            </div>
        </div>
    @endif
</flux:modal>
