@props([
    /*
     | x-tournaments.schedule — the tournament day(s) at a glance. With a draw: one row per round
     | (knockout) or matchday (round robin) with its first start time, courts and state. Without
     | one: the key dates (registration deadline, draw, first serve, finish).
     |
     | tournament   App\Models\Tournament.
     | rounds       App\Services\Tournament\Bracket::for($tournament) (empty before the draw).
     */
    'tournament',
    'rounds' => [],
])

@use('App\Enums\MatchStatus')

@php
    $t = $tournament;
    $day = fn ($date): string => $date->format($date->isCurrentYear() ? 'D j M' : 'D j M Y');

    $rows = [];

    if ($rounds !== []) {
        $nextFound = false;

        foreach ($rounds as $round) {
            $matches = collect($round['matches'])->reject(fn ($m) => $m->isBye() || $m->status === MatchStatus::Cancelled);
            $byes = collect($round['matches'])->filter(fn ($m) => $m->isBye())->count();
            $first = $matches->filter(fn ($m) => $m->scheduled_at !== null)->min('scheduled_at');
            $last = $matches->filter(fn ($m) => $m->scheduled_at !== null)->max('scheduled_at');
            $courts = $matches->pluck('court')->filter()->unique()->sort()->values();
            $finished = $matches->filter(fn ($m) => $m->isFinished())->count();
            $live = $matches->contains(fn ($m) => $m->isLive());

            $state = match (true) {
                $matches->isEmpty() => ['done', __('Byes'), 'grey'],
                $live => ['live', __('Live'), 'smash'],
                $finished === $matches->count() => ['done', __('Played'), 'green'],
                $finished > 0 => ['now', __('Under way'), 'orange'],
                ! $nextFound => ['next', __('Next'), 'navy'],
                default => ['later', __('To come'), 'grey'],
            };

            if (in_array($state[0], ['live', 'now', 'next'], true)) {
                $nextFound = true;
            }

            $details = collect([
                trans_choice(':n match|:n matches', $matches->count(), ['n' => $matches->count()]),
                $byes > 0 ? trans_choice(':n bye|:n byes', $byes, ['n' => $byes]) : null,
                $first && $last && ! $first->eq($last) ? $first->format('H:i').' – '.$last->format('H:i') : $first?->format('H:i'),
                $courts->count() > 1 ? __('Courts :from–:to', ['from' => $courts->first(), 'to' => $courts->last()]) : ($courts->count() === 1 ? __('Court :n', ['n' => $courts->first()]) : null),
            ])->filter()->join(' · ');

            $rows[] = [
                'when' => $first ? $day($first) : __('TBC'),
                'label' => $round['label'],
                'details' => $details,
                'state' => $state,
            ];
        }
    } else {
        $deadline = $t->registrationDeadline();
        $closed = $deadline !== null && $deadline->isPast();

        if ($deadline !== null && ! $deadline->eq($t->starts_at)) {
            $rows[] = [
                'when' => $day($deadline),
                'label' => __('Registration closes'),
                'details' => $deadline->format('H:i').' · '.__('withdraw any time before the draw'),
                'state' => $closed ? ['done', __('Closed'), 'grey'] : ['next', __('Open'), 'green'],
            ];
        }

        $rows[] = [
            'when' => __('Before'),
            'label' => __('The draw'),
            'details' => __('Seeded by rating, published here and in the Draw tab'),
            'state' => ['later', __('To come'), 'grey'],
        ];

        $rows[] = [
            'when' => $day($t->starts_at),
            'label' => __('First serve'),
            'details' => collect([$t->starts_at->format('H:i'), $t->venue ?: config('smash.club.venue')])->filter()->join(' · '),
            'state' => ['later', __('To come'), 'grey'],
        ];

        if ($t->ends_at !== null) {
            $rows[] = [
                'when' => $day($t->ends_at),
                'label' => $t->isKnockout() ? __('Final') : __('Last matchday'),
                'details' => $t->ends_at->isSameDay($t->starts_at) ? __('Expected to finish around :time', ['time' => $t->ends_at->format('H:i')]) : $t->ends_at->format('H:i'),
                'state' => ['later', __('To come'), 'grey'],
            ];
        }
    }
@endphp

<ol {{ $attributes->class('divide-y divide-line border-y border-line') }} role="list" data-tournament-schedule>
    @foreach ($rows as $row)
        <li @class([
            'grid grid-cols-[5.25rem_minmax(0,1fr)] items-center gap-x-4 gap-y-2 py-3.5 sm:grid-cols-[6.5rem_minmax(0,1fr)_auto]',
            'bg-navy-50 shadow-[inset_3px_0_0_var(--color-navy-800)] ps-3 dark:bg-navy-400/8 dark:shadow-[inset_3px_0_0_var(--color-navy-400)]' => in_array($row['state'][0], ['next', 'now', 'live'], true),
        ]) wire:key="schedule-{{ $loop->index }}">
            <span class="label-caps text-ink-muted">{{ $row['when'] }}</span>
            <div class="min-w-0">
                <p class="font-medium text-ink">{{ $row['label'] }}</p>
                @if ($row['details'] !== '')
                    <p class="mt-0.5 text-sm text-ink-muted">{{ $row['details'] }}</p>
                @endif
            </div>
            <div class="col-start-2 sm:col-start-auto sm:pe-3">
                @if ($row['state'][0] === 'live')
                    <x-ui.live />
                @else
                    <x-ui.tag :tone="$row['state'][2]" size="sm" :variant="$row['state'][0] === 'done' ? 'soft' : 'outline'">{{ $row['state'][1] }}</x-ui.tag>
                @endif
            </div>
        </li>
    @endforeach
</ol>
