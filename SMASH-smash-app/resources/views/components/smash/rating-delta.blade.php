@props([
    /*
     | x-smash.rating-delta — signed rating change: green up, red down, "±0", "–" when unknown.
     | Built on x-ui.delta (tabular Barlow numbers, Unicode minus, triangle, spoken text).
     |
     | value        Signed change (float). Or derive it from:
     | participant  App\Models\MatchPlayer (rating_after − rating_before), or
     | before/after Two ratings.
     | size         sm · md · lg
     | variant      text (default) · chip (tinted box, for cards and lists)
     | arrow        Show the triangle.
     | precision    Decimals (0).
     | label        Spoken prefix for screen readers ("Rating change"); '' for none.
     */
    'value' => null,
    'participant' => null,
    'before' => null,
    'after' => null,
    'size' => 'md',
    'variant' => 'text',
    'arrow' => true,
    'precision' => 0,
    'label' => null,
])

@php
    if ($value === null && $participant instanceof \App\Models\MatchPlayer) {
        $value = $participant->ratingChange();
    }
    if ($value === null && is_numeric($before) && is_numeric($after)) {
        $value = (float) $after - (float) $before;
    }

    $rounded = $value === null ? null : round((float) $value, (int) $precision);
    $tone = match (true) {
        $rounded === null, $rounded == 0 => 'grey',
        $rounded > 0 => 'green',
        default => 'smash',
    };

    $label ??= __('Rating change');
@endphp

<span
    {{ $attributes->class([
        'inline-flex shrink-0 items-center leading-none',
        'tone-'.$tone.' bg-[var(--tone-soft)] px-1.5 py-1' => $variant === 'chip',
    ]) }}
    data-smash-rating-delta
>
    @if ($label !== '')
        <span class="sr-only">{{ $label }}</span>
    @endif
    <x-ui.delta :value="$value" :precision="(int) $precision" :size="$size" :arrow="$arrow" />
</span>
