@props([
    /*
     | x-rankings.podium — the top three of a ranking as scaled-up rank boxes: a tall block with a
     | big Barlow numeral (#1 SMASH red, #2–3 navy, the colours of x-ui.rank), then the player,
     | rating with the 30-day change, record and form.
     |
     | rows         LeaderboardRow list, best first (only the first three are shown).
     | discipline   Discipline, for the rating label and screen readers.
     | for          ?User whose card gets a "You" tag.
     */
    'rows' => [],
    'discipline' => null,
    'for' => null,
])

@php
    $rows = collect($rows)->take(3)->values();
    $discipline = $discipline instanceof \App\Enums\Discipline ? $discipline : (\App\Enums\Discipline::tryFrom((string) $discipline) ?? \App\Enums\Discipline::Singles);
@endphp

<ol {{ $attributes->class('grid gap-4 lg:grid-cols-3 lg:gap-6') }} data-rankings-podium>
    @foreach ($rows as $row)
        @php
            $first = $row->position === 1;
            $you = $for instanceof \App\Models\User && $row->user->is($for);
        @endphp
        <li wire:key="podium-{{ $discipline->value }}-{{ $row->user->id }}" class="flex min-w-0 border border-line bg-surface shadow-card dark:shadow-none">
            {{-- The numeral block: a giant version of the rank box in the table. --}}
            <div
                @class([
                    'relative isolate flex w-[4.5rem] shrink-0 justify-center overflow-hidden pt-2.5 sm:w-24 sm:pt-3',
                    'bg-smash-500 text-white' => $first,
                    'bg-navy-800 text-white dark:bg-navy-600' => ! $first,
                ])
                aria-hidden="true"
            >
                {{-- A corner of a badminton court, the only decoration we use. --}}
                <svg class="absolute inset-x-0 bottom-0 -z-10 h-16 w-full text-white/20" viewBox="0 0 96 64" fill="none" preserveAspectRatio="none">
                    <path d="M0 28h96M0 44h96M30 28v36M66 28v36" stroke="currentColor" stroke-width="1.5" vector-effect="non-scaling-stroke" />
                </svg>
                <span class="num text-[4.75rem] font-extrabold leading-[0.82] sm:text-[6.25rem]">{{ $row->position }}</span>
            </div>

            <div class="flex min-w-0 flex-1 flex-col p-4 sm:p-5">
                <p class="sr-only">{{ __('Number :n in :discipline', ['n' => $row->position, 'discipline' => mb_strtolower($discipline->label())]) }}</p>

                <div class="flex min-w-0 items-start justify-between gap-3">
                    <x-smash.player :user="$row->user" size="lg" :ring="$first ? 'top' : false" class="min-w-0" />
                    @if ($you)
                        <x-ui.tag tone="navy" size="sm" variant="solid" class="mt-1">{{ __('You') }}</x-ui.tag>
                    @endif
                </div>

                <div class="mt-auto border-t border-line pt-4 max-sm:mt-4 sm:mt-5">
                    <x-smash.rating :value="$row->rating" :provisional="$row->provisional" size="lg" :delta="$row->change30d" />
                    <p class="mt-1 label-caps text-ink-muted">
                        {{ __(':discipline rating', ['discipline' => $discipline->label()]) }}
                        <span class="normal-case tracking-normal">· {{ __('peak :n', ['n' => $row->peak]) }}</span>
                    </p>

                    <div class="mt-4 flex flex-wrap items-center justify-between gap-x-4 gap-y-2">
                        <x-smash.form :results="$row->form" size="sm" />
                        <p class="flex items-baseline gap-2 whitespace-nowrap">
                            <span class="sr-only">{{ __(':w wins, :l losses', ['w' => $row->wins, 'l' => $row->losses]) }}</span>
                            <span class="num text-[19px] leading-none font-bold text-ink" aria-hidden="true">{{ $row->wins }}<span class="text-ink-muted">–</span>{{ $row->losses }}</span>
                            @if ($row->winRate !== null)
                                <span class="label-caps text-ink-muted">{{ __(':n% won', ['n' => (int) round($row->winRate * 100)]) }}</span>
                            @endif
                        </p>
                    </div>
                </div>
            </div>
        </li>
    @endforeach
</ol>
