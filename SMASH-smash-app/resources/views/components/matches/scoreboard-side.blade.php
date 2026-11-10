@props([
    /*
     | x-matches.scoreboard-side — one side of x-matches.scoreboard (sm and up).
     | match     App\Models\BadmintonMatch
     | side      ['players' => list of ['user', 'rating', 'provisional'], 'won' => ?bool, …]
     | keyName   'a' | 'b'
     | align     end (left-hand side) · start
     | size      x-smash.player size
     | walkover  The match was decided by a walkover.
     */
    'match',
    'side',
    'keyName' => 'a',
    'align' => 'start',
    'size' => 'lg',
    'walkover' => false,
])

<div {{ $attributes->class(['flex min-w-0 flex-col gap-3', 'items-end' => $align === 'end', 'items-start' => $align !== 'end']) }}>
    @forelse ($side['players'] as $line)
        <x-smash.player
            :user="$line['user']"
            :size="$size"
            :align="$align"
            :rating="$line['rating'] ?? false"
            :provisional="$line['provisional']"
            :winner="$side['won'] ? true : null"
        />
    @empty
        <x-smash.player :match="$match" :side="$keyName" :size="$size" :align="$align" />
    @endforelse
    @if ($side['won'])
        <span class="inline-flex items-center gap-1.5 label-caps text-win">
            <flux:icon.trophy variant="micro" class="size-4" />
            {{ $walkover ? __('Awarded the walkover') : __('Winner') }}
        </span>
    @endif
</div>
