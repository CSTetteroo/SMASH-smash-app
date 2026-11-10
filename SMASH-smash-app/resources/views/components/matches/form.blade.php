@props([
    /*
     | x-matches.form — the match form shared by pages::matches.create and pages::matches.edit.
     | Rendered inside the page's Livewire component: wire:model / wire:click bind to it, the state
     | lives in App\Livewire\Forms\MatchForm ($form).
     |
     | form         App\Livewire\Forms\MatchForm
     | actor        The signed-in user (members are fixed on side A).
     | match        The match being edited (null when logging a new one).
     | people       Players who can be picked, keyed by id.
     | statuses     Status choices (value => label) for this actor.
     | check        MatchForm::scoreCheck()
     | stakes       MatchForm::stakes() (null when unrated or the line-up is incomplete).
     | preview      MatchForm::preview(): an unsaved match for the preview card.
     | projected    Show projected rating changes in the preview (off when editing a finished match).
     | Slots: actions (right: Cancel, Save), secondary (left: Delete).
     */
    'form',
    'actor',
    'match' => null,
    'people',
    'statuses' => [],
    'check' => [],
    'stakes' => null,
    'preview' => null,
    'projected' => true,
])

@php
    $isAdmin = $actor->isAdmin();
    $isTournament = $match !== null && $match->tournament_id !== null;
    $discipline = $form->disciplineEnum();
    $status = $form->statusEnum();
    $perSide = $discipline->playersPerSide();
    $chosen = $form->chosenIds();
    $target = $form->pointsNumber();
    $cap = \App\Support\Badminton\Scoring::cap($target);
    $bestOf = $form->bestOfNumber();
    $finishedBefore = $match !== null && $match->exists && $match->isFinished();

    // Who is on each side right now (selected players, or the draw's players for tournament matches).
    $sideUsers = function (\App\Enums\Side $side) use ($form, $people, $isTournament, $match) {
        if ($isTournament) {
            $entry = $match->entryOn($side);

            return $entry !== null ? $entry->members() : $match->playersOn($side);
        }

        return collect($form->playerIds($side))->map(fn ($id) => $people->get($id))->filter()->values();
    };
    $users = ['a' => $sideUsers(\App\Enums\Side::A), 'b' => $sideUsers(\App\Enums\Side::B)];

    $shortName = fn ($list, string $fallback): string => collect($list)->isEmpty()
        ? $fallback
        : collect($list)->map(fn ($user) => \Illuminate\Support\Str::ucfirst($user->lastName()))->join(' & ');
    $fullName = fn ($list, string $fallback): string => collect($list)->isEmpty()
        ? $fallback
        : collect($list)->pluck('name')->join(' & ');

    $short = ['a' => $shortName($users['a'], __('Side A')), 'b' => $shortName($users['b'], __('Side B'))];
    $full = ['a' => $fullName($users['a'], __('Side A')), 'b' => $fullName($users['b'], __('Side B'))];

    $winner = $check['winner'] ?? null;
    $scores = $check['scores'] ?? [];
    $tally = \App\Support\Badminton\Scoring::tally($scores, $target);

    $bestOfOptions = collect([1, 3])->when($bestOf === 5, fn ($options) => $options->push(5))->all();
    $courts = range(1, max((int) config('smash.club.courts', 4), (int) $form->court));

    // Every games.* error (from the rules or from SaveMatch) is listed under the score rows.
    $gameErrors = collect($errors->getMessages())
        ->filter(fn ($messages, $key) => $key === 'form.games' || str_starts_with($key, 'form.games.'))
        ->flatten()
        ->unique()
        ->values();

    $statusLabel = $status?->value === 'cancelled' ? __('Was due to start') : __('Starts at');
@endphp

<form wire:submit="save" {{ $attributes->class('min-w-0') }} novalidate>
    @if ($errors->any())
        <x-ui.alert tone="danger" :title="__('Not saved yet')" class="mb-8">
            {{ __('Some details need another look: check the fields marked in red.') }}
        </x-ui.alert>
    @endif

    {{-- ============================================================== PLAYERS --}}
    <x-ui.form-section
        :title="__('Players')"
        :description="$isTournament
            ? __('Tournament matches take their players from the draw.')
            : ($isAdmin
                ? __('Singles or doubles, and who played on each side. Doubles needs two players per side.')
                : __('You\'re on side A. Pick who you played against, and your partner for doubles.'))"
    >
        @if ($isTournament)
            <x-ui.alert tone="info">
                {{ __('Part of :tournament (:round). The players come from the draw, and the winner moves on automatically when you save.', [
                    'tournament' => $match->tournament?->name,
                    'round' => $match->roundLabel() ?? __('round :n', ['n' => $match->round]),
                ]) }}
            </x-ui.alert>
            <div class="grid gap-6 sm:grid-cols-2">
                @foreach (['a' => __('Side A'), 'b' => __('Side B')] as $key => $label)
                    <div class="min-w-0">
                        <p class="label-caps text-ink-soft">{{ $label }}</p>
                        <div class="mt-2 flex min-h-12 items-center border border-line bg-surface-muted px-3 py-2">
                            <x-smash.player :match="$match" :side="$key" size="sm" />
                        </div>
                    </div>
                @endforeach
            </div>
        @else
            <flux:radio.group wire:model.live="form.discipline" :label="__('Discipline')" variant="segmented" class="max-w-xs">
                <flux:radio value="singles" :label="__('Singles')" />
                <flux:radio value="doubles" :label="__('Doubles')" />
            </flux:radio.group>

            <div class="grid gap-6 sm:grid-cols-2">
                @foreach (['a' => 'sideA', 'b' => 'sideB'] as $key => $property)
                    @php
                        $legend = match (true) {
                            $isAdmin => $key === 'a' ? __('Side A') : __('Side B'),
                            $key === 'a' => $perSide === 1 ? __('Side A · you') : __('Side A · you and your partner'),
                            default => $perSide === 1 ? __('Side B · your opponent') : __('Side B · your opponents'),
                        };
                    @endphp
                    <fieldset class="min-w-0 space-y-2.5" data-test="side-{{ $key }}">
                        <legend class="mb-2 label-caps text-ink-soft">{{ $legend }}</legend>

                        @for ($slot = 0; $slot < $perSide; $slot++)
                            @php
                                $current = (int) ($form->{$property}[$slot] ?? 0);
                                $fixed = ! $isAdmin && $key === 'a' && $slot === 0;
                                $slotLabel = $perSide === 1
                                    ? ($key === 'a' ? __('Side A player') : __('Side B player'))
                                    : __(':side, player :n', ['side' => $key === 'a' ? __('Side A') : __('Side B'), 'n' => $slot + 1]);
                            @endphp

                            @if ($fixed)
                                <div class="flex h-10 items-center justify-between gap-3 border border-line bg-surface-muted px-3">
                                    <x-smash.player :user="$actor" size="xs" :meta="false" :href="false" />
                                    <x-ui.tag tone="navy" size="sm">{{ __('You') }}</x-ui.tag>
                                </div>
                                <flux:error name="form.sideA.0" />
                            @else
                                <div wire:key="slot-{{ $key }}-{{ $slot }}">
                                    <flux:select
                                        wire:model.live="form.{{ $property }}.{{ $slot }}"
                                        :placeholder="$slot === 0 || $isAdmin ? __('Choose a player') : __('Choose your partner')"
                                        :aria-label="$slotLabel"
                                    >
                                        @foreach ($people as $person)
                                            <option
                                                value="{{ $person->id }}"
                                                @disabled(in_array($person->id, $chosen, true) && $person->id !== $current)
                                            >{{ $person->name }}{{ $person->is_active ? '' : ' '.__('(inactive)') }}</option>
                                        @endforeach
                                    </flux:select>
                                    <flux:error name="form.{{ $property }}.{{ $slot }}" />
                                </div>
                            @endif
                        @endfor

                        <flux:error name="form.{{ $property }}" />
                    </fieldset>
                @endforeach
            </div>
        @endif
    </x-ui.form-section>

    {{-- ============================================================== RESULT --}}
    <x-ui.form-section
        :title="__('Result')"
        :description="__('Enter every game, side A\'s points first. Games go to :target with a two-point lead, and :cap–:capMinus at most.', ['target' => $target, 'cap' => $cap, 'capMinus' => $cap - 1])"
    >
        <div class="grid gap-6 sm:grid-cols-2">
            @if (count($statuses) <= 2)
                <flux:radio.group wire:model.live="form.status" :label="__('Status')" variant="segmented">
                    @foreach ($statuses as $value => $label)
                        <flux:radio :value="$value" :label="$label" />
                    @endforeach
                </flux:radio.group>
            @else
                <flux:select wire:model.live="form.status" :label="__('Status')">
                    @foreach ($statuses as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </flux:select>
            @endif

            @if ($status?->isFinished())
                <flux:input
                    type="datetime-local"
                    wire:model.live.blur="form.playedAt"
                    :label="__('Played on')"
                    :max="now()->format(\App\Livewire\Forms\MatchForm::DATE_FORMAT)"
                    :loading="false"
                />
            @else
                <flux:input
                    type="datetime-local"
                    wire:model.live.blur="form.scheduledAt"
                    :label="$statusLabel"
                    :description="$status?->value === 'scheduled' && ! $match ? null : __('Optional')"
                    :loading="false"
                />
            @endif
        </div>

        @if ($status?->value === 'walkover')
            <flux:radio.group wire:model.live="form.walkoverWinner" :label="__('Walkover awarded to')" variant="segmented">
                <flux:radio value="a" :label="$short['a']" />
                <flux:radio value="b" :label="$short['b']" />
            </flux:radio.group>
            <p class="-mt-3 text-sm text-ink-muted">{{ __('No games are recorded for a walkover, and it never changes ratings.') }}</p>
        @endif

        @if ($form->takesScores())
            <div class="grid gap-6 sm:grid-cols-2">
                <flux:radio.group wire:model.live="form.bestOf" :label="__('Best of')" variant="segmented">
                    @foreach ($bestOfOptions as $option)
                        <flux:radio :value="(string) $option" :label="trans_choice(':n game|:n games', $option, ['n' => $option])" />
                    @endforeach
                </flux:radio.group>
                <flux:radio.group wire:model.live="form.pointsPerGame" :label="__('Points per game')" variant="segmented">
                    @foreach ([21, 15, 11] as $option)
                        <flux:radio :value="(string) $option" :label="(string) $option" />
                    @endforeach
                </flux:radio.group>
            </div>

            {{-- Games editor --}}
            <div class="min-w-0" data-test="games-editor">
                <div class="grid grid-cols-[3.75rem_minmax(0,4.75rem)_0.75rem_minmax(0,4.75rem)_minmax(0,1fr)_2.25rem] items-end gap-x-2 sm:grid-cols-[4.5rem_5.5rem_1rem_5.5rem_minmax(0,1fr)_2.25rem] sm:gap-x-3">
                    <span class="label-caps text-ink-soft">{{ __('Games') }}</span>
                    <span class="truncate text-center text-xs font-semibold text-ink" title="{{ $full['a'] }}">{{ $short['a'] }}</span>
                    <span></span>
                    <span class="truncate text-center text-xs font-semibold text-ink" title="{{ $full['b'] }}">{{ $short['b'] }}</span>
                    <span></span>
                    <span></span>
                </div>

                <ol class="mt-2 space-y-2" role="list">
                    @foreach ($form->games as $i => $row)
                        @php
                            $a = is_numeric($row['a'] ?? null) ? (int) $row['a'] : null;
                            $b = is_numeric($row['b'] ?? null) ? (int) $row['b'] : null;
                            $done = $a !== null && $b !== null && \App\Support\Badminton\Scoring::isCompleteGame($a, $b, $target);
                            $gameWinner = $done ? ($a > $b ? 'a' : 'b') : null;
                        @endphp
                        <li
                            class="grid grid-cols-[3.75rem_minmax(0,4.75rem)_0.75rem_minmax(0,4.75rem)_minmax(0,1fr)_2.25rem] items-center gap-x-2 sm:grid-cols-[4.5rem_5.5rem_1rem_5.5rem_minmax(0,1fr)_2.25rem] sm:gap-x-3"
                            wire:key="game-row-{{ $i }}"
                        >
                            <span class="text-sm font-medium text-ink-soft">{{ __('Game :n', ['n' => $i + 1]) }}</span>
                            <flux:input
                                type="number"
                                inputmode="numeric"
                                min="0"
                                :max="$cap"
                                wire:model.live.blur="form.games.{{ $i }}.a"
                                :aria-label="__('Game :n, :side points', ['n' => $i + 1, 'side' => $full['a']])"
                                :loading="false"
                                class:input="num text-center !text-lg font-bold"
                            />
                            <span class="text-center font-medium text-ink-muted" aria-hidden="true">–</span>
                            <flux:input
                                type="number"
                                inputmode="numeric"
                                min="0"
                                :max="$cap"
                                wire:model.live.blur="form.games.{{ $i }}.b"
                                :aria-label="__('Game :n, :side points', ['n' => $i + 1, 'side' => $full['b']])"
                                :loading="false"
                                class:input="num text-center !text-lg font-bold"
                            />
                            <span class="min-w-0 truncate text-sm">
                                @if ($gameWinner)
                                    <span class="inline-flex min-w-0 items-center gap-1 font-semibold text-win">
                                        <flux:icon.check variant="micro" class="size-4 shrink-0" />
                                        <span class="truncate">{{ $short[$gameWinner] }}</span>
                                    </span>
                                @endif
                            </span>
                            @if (count($form->games) > 1)
                                <x-ui.button
                                    variant="ghost"
                                    size="sm"
                                    square
                                    icon="x-mark"
                                    wire:click="removeGame({{ $i }})"
                                    :aria-label="__('Remove game :n', ['n' => $i + 1])"
                                />
                            @else
                                <span></span>
                            @endif
                        </li>
                    @endforeach
                </ol>

                <div class="mt-3 flex flex-wrap items-center gap-3">
                    @if (count($form->games) < $bestOf)
                        <x-ui.button variant="outline" size="sm" icon="plus" wire:click="addGame">{{ __('Add game') }}</x-ui.button>
                    @endif
                    <p class="text-sm text-ink-muted">{{ __('Leave a row empty if it wasn\'t played.') }}</p>
                </div>

                {{-- Live score check --}}
                <div class="mt-5 space-y-3" aria-live="polite" data-test="score-check">
                    @if ($gameErrors->isNotEmpty() || ! empty($check['problems']))
                        <x-ui.alert tone="warning" :title="__('Check the score')">
                            <ul class="space-y-1">
                                @foreach ($gameErrors->merge($check['problems'] ?? [])->unique() as $message)
                                    <li>{{ $message }}</li>
                                @endforeach
                            </ul>
                        </x-ui.alert>
                    @elseif ($winner)
                        <div class="tone-green flex items-start gap-3 border-s-4 border-[var(--tone)] bg-[var(--tone-soft)] px-4 py-3.5" data-test="winner-preview">
                            <flux:icon.trophy variant="mini" class="mt-0.5 size-5 shrink-0 text-[var(--tone-ink)]" />
                            <p class="text-[15px] text-ink-soft">
                                <span class="font-semibold text-ink">{{ __(':name :verb :score', [
                                    'name' => $full[$winner->value],
                                    'verb' => collect($users[$winner->value])->count() > 1 ? __('win') : __('wins'),
                                    'score' => $tally[$winner->value].'–'.$tally[$winner->opposite()->value],
                                ]) }}</span>
                                <span class="num ms-1 text-[17px] font-semibold text-ink">({{ collect($scores)->map(fn ($g) => $g['a'].'–'.$g['b'])->join(', ') }})</span>
                                @if ($stakes && $form->isRated && $projected)
                                    <span class="mt-1 block text-sm">
                                        {{ __('If this result stands:') }}
                                        @foreach ([...$users['a'], ...$users['b']] as $player)
                                            <span class="whitespace-nowrap"><span class="font-medium text-ink">{{ $player->firstName() }}</span>
                                                <x-smash.rating-delta :value="$stakes[$winner === \App\Enums\Side::A ? 'a_wins' : 'b_wins'][$player->id] ?? null" size="sm" /></span>@if (! $loop->last)<span class="text-ink-muted" aria-hidden="true"> · </span>@endif
                                        @endforeach
                                    </span>
                                @endif
                            </p>
                        </div>
                    @elseif (! empty($check['hint']))
                        <p class="flex items-start gap-2 text-sm text-ink-soft">
                            <flux:icon.information-circle variant="mini" class="mt-px size-5 shrink-0 text-navy-600 dark:text-navy-300" />
                            <span>{{ $check['hint'] }}</span>
                        </p>
                    @endif
                </div>
            </div>
        @endif
    </x-ui.form-section>

    {{-- ============================================================== DETAILS --}}
    <x-ui.form-section :title="__('Details')" :description="__('Where it was played, whether it counts for the rankings, and anything worth remembering.')">
        <div class="grid gap-6 sm:grid-cols-[10rem_minmax(0,1fr)]">
            <flux:select wire:model="form.court" :label="__('Court')">
                <option value="">{{ __('Not set') }}</option>
                @foreach ($courts as $court)
                    <option value="{{ $court }}">{{ __('Court :n', ['n' => $court]) }}</option>
                @endforeach
            </flux:select>
            <flux:input wire:model="form.venue" :label="__('Venue')" :placeholder="config('smash.club.venue')" maxlength="120" />
        </div>

        <div class="border border-line bg-surface-muted px-4 py-3.5">
            <flux:switch
                wire:model.live="form.isRated"
                :label="$form->isRated ? __('Rated match: counts for the rankings') : __('Practice match — doesn\'t count for ELO')"
                :description="$form->isRated
                    ? __('Turn this off for warm-ups, coaching games and matches with house rules.')
                    : __('The result is kept, but nobody\'s rating changes.')"
                align="left"
            />
        </div>

        <flux:textarea
            wire:model="form.notes"
            :label="__('Notes')"
            rows="3"
            maxlength="2000"
            :placeholder="__('Anything worth remembering? A comeback, a 30–29 game, a borrowed racket…')"
        />
    </x-ui.form-section>

    {{-- ============================================================== PREVIEW --}}
    <x-ui.form-section
        :title="__('Preview')"
        :description="$finishedBefore
            ? __('How the match will look once saved. Ratings are recalculated for everyone when you save.')
            : __('How the match will look once saved, and what it means for the rankings.')"
    >
        @if ($preview && ($form->sidesComplete() || $isTournament))
            <x-smash.match-card :match="$preview" :href="false" :ratings="$projected" :footer="false" data-test="match-preview" />
        @else
            <x-ui.empty :title="__('Pick the players first')" variant="bordered" class="!py-8">
                {{ __('The preview appears as soon as both sides are chosen.') }}
            </x-ui.empty>
        @endif

        @if (! $form->isRated)
            <x-ui.alert tone="grey" icon="academic-cap" :title="__('Practice match — doesn\'t count for ELO')">
                {{ __('The result shows up in results and on profiles, but the rankings stay as they are.') }}
            </x-ui.alert>
        @elseif ($stakes && ! $finishedBefore)
            @php
                $allPlayers = collect([...$users['a'], ...$users['b']]);
                $sideOfPlayer = fn ($player) => $users['a']->contains(fn ($u) => $u->id === $player->id) ? 'a' : 'b';
            @endphp
            <x-ui.table dense :hover="false" data-test="rating-stakes">
                <x-slot:header>
                    <p class="font-serif text-h5 text-heading">{{ __('Rating stakes') }}</p>
                    <p class="text-sm text-ink-muted">{{ __('From current ratings; the exact change depends on the score.') }}</p>
                </x-slot:header>
                <table>
                    <thead>
                        <tr>
                            <th>{{ __('Player') }}</th>
                            <th class="text-end">{{ __('Rating') }}</th>
                            <th @class(['text-end', 'text-ink' => $winner === \App\Enums\Side::A])>{{ __('If :name wins', ['name' => $short['a']]) }}</th>
                            <th @class(['text-end', 'text-ink' => $winner === \App\Enums\Side::B])>{{ __('If :name wins', ['name' => $short['b']]) }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($allPlayers as $player)
                            <tr wire:key="stake-{{ $player->id }}">
                                <td><x-smash.player :user="$player" size="sm" :meta="false" :href="false" /></td>
                                <td class="text-end"><x-smash.rating :user="$player" :discipline="$isTournament ? $match->discipline : $discipline" size="sm" muted /></td>
                                <td @class(['text-end', 'bg-[var(--color-surface-muted)]' => $winner === \App\Enums\Side::A])>
                                    <x-smash.rating-delta :value="$stakes['a_wins'][$player->id] ?? null" size="sm" />
                                </td>
                                <td @class(['text-end', 'bg-[var(--color-surface-muted)]' => $winner === \App\Enums\Side::B])>
                                    <x-smash.rating-delta :value="$stakes['b_wins'][$player->id] ?? null" size="sm" />
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </x-ui.table>
        @endif
    </x-ui.form-section>

    <x-ui.form-actions>
        @isset($secondary)
            <x-slot:secondary>{{ $secondary }}</x-slot:secondary>
        @endisset
        {{ $actions ?? '' }}
    </x-ui.form-actions>
</form>
