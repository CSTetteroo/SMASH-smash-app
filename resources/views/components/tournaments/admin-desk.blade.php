@props([
    /*
     | x-tournaments.admin-desk — the committee's controls on a tournament page: where the
     | tournament is (draft → registration → draw → completed), the next step, and adding entries
     | on behalf of players. Its buttons call pages::tournaments.show actions, which authorise
     | (manage / manageDraw) and validate on the server. Render it inside @can('manage').
     |
     | tournament   App\Models\Tournament with its draw loaded (Bracket::for) or matches_count.
     | entryCount   Number of entries.
     | players      Active players not entered yet (for the add-entry selects).
     | played       Finished draw matches (byes excluded).
     | total        Draw matches (byes excluded).
     */
    'tournament',
    'entryCount' => 0,
    'players' => null,
    'played' => 0,
    'total' => 0,
])

@use('App\Enums\TournamentStatus')

@php
    $t = $tournament;
    $players = collect($players ?? []);
    $hasDraw = $t->hasDraw();
    $isDoubles = $t->isDoubles();
    $unit = fn (int $n): string => $isDoubles
        ? trans_choice(':n pair|:n pairs', $n, ['n' => $n])
        : trans_choice(':n player|:n players', $n, ['n' => $n]);

    $steps = [
        TournamentStatus::Draft->value => __('Draft'),
        TournamentStatus::Open->value => __('Sign-up'),
        TournamentStatus::InProgress->value => __('Draw'),
        TournamentStatus::Completed->value => __('Done'),
    ];
    $current = array_search($t->status->value, array_keys($steps), true);

    $deadline = $t->registrationDeadline();
    $canAdd = $t->isOpenForRegistration();
    $addBlocked = match (true) {
        $canAdd => null,
        $hasDraw => null,
        $t->status === TournamentStatus::Draft => __('Open registration first, then add entries here.'),
        $t->isFull() => __('The tournament is full. Raise the maximum on the edit page to add another entry.'),
        default => __('Registration has closed. Move the deadline on the edit page to add a late entry.'),
    };
    $byes = $t->isKnockout() && $entryCount >= 2 ? \App\Services\Tournament\DrawGenerator::bracketSize($entryCount) - $entryCount : 0;
@endphp

<section {{ $attributes->class('tone-navy border border-line border-t-4 border-t-[var(--tone)] bg-surface') }} aria-labelledby="desk-title" data-tournament-desk>
    <header class="flex flex-wrap items-start justify-between gap-4 border-b border-line px-5 py-4 sm:px-6">
        <div>
            <p class="kicker">{{ __('Committee desk') }}</p>
            <h2 id="desk-title" class="mt-1 font-serif text-h5 text-heading">{{ __('Run this tournament') }}</h2>
        </div>
        <p class="text-sm text-ink-muted">{{ __('Only admins see this panel.') }}</p>
    </header>

    <div class="grid gap-6 px-5 py-5 sm:px-6 lg:grid-cols-[minmax(0,1fr)_minmax(0,1.1fr)] lg:gap-10">
        {{-- Where we are + the next step --}}
        <div class="min-w-0">
            <ol class="grid grid-cols-4 gap-1" role="list" aria-label="{{ __('Tournament stage') }}">
                @foreach ($steps as $value => $label)
                    @php($i = $loop->index)
                    <li class="min-w-0" @if ($i === $current) aria-current="step" @endif wire:key="step-{{ $value }}">
                        <span @class([
                            'block h-1',
                            'bg-heading' => $i <= $current,
                            'bg-line' => $i > $current,
                        ])></span>
                        <span @class([
                            'mt-2 block truncate text-[11px] font-semibold uppercase tracking-label',
                            'text-heading' => $i === $current,
                            'text-ink-muted' => $i !== $current,
                        ])>{{ $label }}</span>
                    </li>
                @endforeach
            </ol>

            @error('draw')
                <x-ui.alert tone="danger" class="mt-5">{{ $message }}</x-ui.alert>
            @enderror
            @error('status')
                <x-ui.alert tone="danger" class="mt-5">{{ $message }}</x-ui.alert>
            @enderror

            <div class="mt-5 space-y-4">
                @if ($t->status === TournamentStatus::Draft)
                    <p class="text-[15px] leading-relaxed text-ink-soft">
                        {{ __('Only admins can see this tournament. Check the details, then open registration to publish it and let members sign up.') }}
                    </p>
                    <x-ui.button variant="primary" icon="megaphone" wire:click="openRegistration">{{ __('Open registration') }}</x-ui.button>
                @elseif (! $hasDraw)
                    <p class="text-[15px] leading-relaxed text-ink-soft">
                        {{ __(':entries registered.', ['entries' => $unit($entryCount)]) }}
                        @if ($deadline && $deadline->isFuture())
                            {{ __('Registration closes :day at :time.', ['day' => $deadline->format('D j M'), 'time' => $deadline->format('H:i')]) }}
                        @else
                            {{ __('Registration has closed, so the draw can be made.') }}
                        @endif
                        @if ($byes > 0)
                            {{ trans_choice('With this many entries the top seed gets a bye.|With this many entries the top :n seeds get a bye.', $byes, ['n' => $byes]) }}
                        @endif
                    </p>
                    @if ($entryCount < 2)
                        <x-ui.button variant="primary" icon="squares-2x2" disabled>{{ __('Make the draw') }}</x-ui.button>
                        <p class="text-sm text-ink-muted">{{ __('At least two entries are needed before you can make the draw.') }}</p>
                    @else
                        <flux:modal.trigger name="make-draw">
                            <x-ui.button variant="primary" icon="squares-2x2">{{ __('Make the draw') }}</x-ui.button>
                        </flux:modal.trigger>
                    @endif
                @else
                    <p class="text-[15px] leading-relaxed text-ink-soft">
                        @if ($t->status === TournamentStatus::Completed)
                            {{ __('All done: :played of :total matches played. Results can still be corrected from the draw.', ['played' => $played, 'total' => $total]) }}
                        @else
                            {{ __(':played of :total matches played. Open a box in the draw to score it live or correct a result.', ['played' => $played, 'total' => $total]) }}
                        @endif
                    </p>
                    <flux:modal.trigger name="reset-draw">
                        <x-ui.button variant="danger-outline" size="sm" icon="arrow-path">{{ __('Reset draw') }}</x-ui.button>
                    </flux:modal.trigger>
                @endif
            </div>
        </div>

        {{-- Entries on behalf of players --}}
        <div class="min-w-0 lg:border-s lg:border-line lg:ps-10">
            <p class="label-caps text-ink-muted">{{ __('Add an entry') }}</p>

            @if ($hasDraw)
                <p class="mt-2 text-sm text-ink-soft">{{ __('The draw is made, so the line-up is fixed. Reset the draw to change entries or seeds.') }}</p>
            @elseif ($addBlocked)
                <p class="mt-2 text-sm text-ink-soft">{{ $addBlocked }}</p>
            @else
                <form wire:submit="addEntry" class="mt-3 grid gap-3 sm:grid-cols-[minmax(0,1fr)_minmax(0,1fr)_auto] sm:items-end" @if (! $isDoubles) data-singles @endif>
                    <flux:select wire:model="addPlayerId" :label="__('Player')" size="sm" @class(['sm:col-span-2' => ! $isDoubles])>
                        <option value="">{{ __('Choose a player') }}</option>
                        @foreach ($players as $candidate)
                            <option value="{{ $candidate->id }}" wire:key="add-p-{{ $candidate->id }}">{{ $candidate->name }} · {{ $candidate->rating($t->discipline) }}</option>
                        @endforeach
                    </flux:select>
                    @if ($isDoubles)
                        <flux:select wire:model="addPartnerId" :label="__('Partner')" size="sm">
                            <option value="">{{ __('Choose a partner') }}</option>
                            @foreach ($players as $candidate)
                                <option value="{{ $candidate->id }}" wire:key="add-q-{{ $candidate->id }}">{{ $candidate->name }} · {{ $candidate->rating($t->discipline) }}</option>
                            @endforeach
                        </flux:select>
                    @endif
                    <x-ui.button variant="outline" size="sm" type="submit" icon="plus" class="h-10">{{ __('Add') }}</x-ui.button>
                </form>
                @foreach (['add.player', 'add.partner', 'add.tournament'] as $key)
                    @error($key)
                        <flux:error :message="$message" class="mt-2" />
                    @enderror
                @endforeach
                <p class="mt-3 text-sm text-ink-muted">{{ __('Remove entries or fix seeds in the Entries tab.') }}</p>
            @endif
        </div>
    </div>

    {{-- Confirmations --}}
    <flux:modal name="make-draw" class="md:w-[30rem]">
        <div class="space-y-6">
            <div>
                <flux:heading size="lg">{{ __('Make the draw?') }}</flux:heading>
                <flux:text class="mt-2">
                    {{ __('Registration closes and :entries are drawn. Entries without a seed are seeded by current rating.', ['entries' => $unit($entryCount)]) }}
                    @if ($t->isKnockout())
                        {{ __('Every round is created now, and byes move straight on.') }}
                    @else
                        {{ __('Everybody plays everybody, one matchday per round.') }}
                    @endif
                    {{ __('Members can no longer withdraw afterwards.') }}
                </flux:text>
            </div>
            <div class="flex justify-end gap-2">
                <flux:modal.close><x-ui.button variant="ghost" size="sm">{{ __('Not yet') }}</x-ui.button></flux:modal.close>
                <x-ui.button variant="primary" size="sm" wire:click="generateDraw">{{ __('Make the draw') }}</x-ui.button>
            </div>
        </div>
    </flux:modal>

    <flux:modal name="reset-draw" class="md:w-[30rem]">
        <div class="space-y-6">
            <div>
                <flux:heading size="lg">{{ __('Reset the draw?') }}</flux:heading>
                <flux:text class="mt-2">
                    {{ trans_choice('This deletes every match in the draw, including the :n result already played, and the pick\'em predictions on them. Ratings are recalculated without them and registration reopens.|This deletes every match in the draw, including the :n results already played, and the pick\'em predictions on them. Ratings are recalculated without them and registration reopens.', $played, ['n' => $played]) }}
                </flux:text>
            </div>
            <flux:checkbox wire:model="clearSeeds" :label="__('Also clear the seeds, so the next draw reseeds everybody by rating')" />
            <div class="flex justify-end gap-2">
                <flux:modal.close><x-ui.button variant="ghost" size="sm">{{ __('Keep the draw') }}</x-ui.button></flux:modal.close>
                <x-ui.button variant="danger" size="sm" icon="trash" wire:click="resetDraw">{{ __('Delete matches and reset') }}</x-ui.button>
            </div>
        </div>
    </flux:modal>
</section>
