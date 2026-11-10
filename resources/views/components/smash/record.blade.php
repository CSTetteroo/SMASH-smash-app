@props([
    /*
     | x-smash.record — a win–loss record ("18–7") with optional win rate and split bar.
     |
     | wins/losses  Numbers. Or read them from:
     | user         App\Models\User + discipline (rated matches only).
     | discipline   Discipline|string (default singles).
     | size         sm · md · lg
     | label        Caption (default "W–L"); false hides it.
     | rate         Show the win rate next to the caption.
     | bar          Green / red split bar under the numbers.
     */
    'wins' => null,
    'losses' => null,
    'user' => null,
    'discipline' => null,
    'size' => 'md',
    'label' => null,
    'rate' => true,
    'bar' => false,
])

@php
    $discipline = $discipline instanceof \App\Enums\Discipline
        ? $discipline
        : (\App\Enums\Discipline::tryFrom((string) $discipline) ?? \App\Enums\Discipline::Singles);

    if ($user instanceof \App\Models\User) {
        $wins ??= $user->wins($discipline);
        $losses ??= $user->losses($discipline);
    }
    $wins = (int) $wins;
    $losses = (int) $losses;
    $played = $wins + $losses;
    $winRate = $played > 0 ? (int) round($wins / $played * 100) : null;

    $label ??= __('W–L');

    $numberSize = match ($size) {
        'sm' => 'text-[17px]',
        'lg' => 'text-[2.5rem]',
        default => 'text-[1.625rem]',
    };
@endphp

<span {{ $attributes->class(['inline-flex min-w-0 flex-col', 'w-full' => $bar]) }} data-smash-record>
    <span class="sr-only">{{ __(':w wins, :l losses', ['w' => $wins, 'l' => $losses]) }}{{ $winRate !== null ? ', '.__(':n percent won', ['n' => $winRate]) : '' }}</span>
    <span class="num font-extrabold leading-none text-ink {{ $numberSize }}" aria-hidden="true">
        {{ $wins }}<span class="px-[0.08em] font-semibold text-ink-muted">–</span>{{ $losses }}
    </span>
    @if ($label !== false || ($rate && $winRate !== null))
        <span class="mt-1.5 flex items-center gap-2 label-caps text-ink-muted" aria-hidden="true">
            @if ($label !== false)
                <span>{{ $label }}</span>
            @endif
            @if ($rate && $winRate !== null)
                <span class="num text-[13px] font-bold tracking-normal text-ink-soft">{{ $winRate }}%</span>
            @endif
        </span>
    @endif
    @if ($bar)
        <span class="mt-2 flex h-1.5 w-full gap-0.5" aria-hidden="true">
            @if ($played > 0)
                <span class="h-full bg-win" style="width: {{ round($wins / $played * 100, 2) }}%"></span>
                <span class="h-full flex-1 bg-loss"></span>
            @else
                <span class="h-full flex-1 bg-zinc-200 dark:bg-white/10"></span>
            @endif
        </span>
    @endif
</span>
