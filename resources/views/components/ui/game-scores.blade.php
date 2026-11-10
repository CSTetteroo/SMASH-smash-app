@props([
    // [[21, 17], [19, 21], [21, 15]] or [['a' => 21, 'b' => 17], …] — side A first.
    // MatchGame models work too (side_a_points / side_b_points).
    'games' => [],
    // inline ("21–17 · 19–21 · 21–15") · boxes (one box per game, A over B)
    'layout' => 'inline',
    // sm · md · lg
    'size' => 'md',
])

{{-- Kept for older markup: renders x-smash.games, the canonical game-score component (it also knows live games and perspectives). --}}
<x-smash.games :games="$games" :layout="$layout" :size="$size" {{ $attributes }} />
