@props([
    /*
     | x-rankings.contender — one side of the head-to-head face-off: big avatar, linked name,
     | programme, and the current rating + rank in both disciplines.
     |
     | user         App\Models\User.
     | ranks        ['singles' => ['positions' => [userId => index], 'total' => n], 'doubles' => …]
     |              (index is 0-based; missing = not ranked).
     | align        start · end (mirrored from md, for the left-hand side of the face-off).
     |              Phones always centre both sides, so the two columns line up.
     | leading      true when this player leads the series (a tag on the avatar).
     */
    'user',
    'ranks' => [],
    'align' => 'start',
    'leading' => false,
])

@php
    $end = $align === 'end';
    $singlesIndex = $ranks['singles']['positions'][$user->id] ?? null;
    $rank = $singlesIndex === null ? null : $singlesIndex + 1;
@endphp

<div {{ $attributes->class([
    'flex min-w-0 flex-col items-center gap-4 text-center',
    'md:items-end md:text-end' => $end,
    'md:items-start md:text-start' => ! $end,
]) }} data-rankings-contender>
    <div class="relative">
        <x-smash.avatar :user="$user" size="xl" ring="auto" :rank="$rank" class="md:hidden" />
        <x-smash.avatar :user="$user" size="2xl" ring="auto" :rank="$rank" class="max-md:hidden" />
        @if ($leading)
            <x-ui.tag tone="orange" size="sm" variant="solid" class="absolute -bottom-2 left-1/2 -translate-x-1/2 whitespace-nowrap">{{ __('Leads') }}</x-ui.tag>
        @endif
    </div>

    <div class="min-w-0 max-w-full">
        <p class="font-serif text-h3 leading-tight text-balance text-heading">
            <a href="{{ route('players.show', $user) }}" wire:navigate class="decoration-1 underline-offset-4 hover:underline">{{ $user->name }}</a>
        </p>
        <p @class(['mt-1.5 flex flex-wrap items-center justify-center gap-2 text-sm text-ink-muted', 'md:justify-end' => $end, 'md:justify-start' => ! $end])>
            @if ($user->programme)
                <span>{{ $user->programme->label() }}</span>
            @endif
            @if (! $user->is_active)
                <x-ui.tag tone="grey" size="sm">{{ __('Former member') }}</x-ui.tag>
            @endif
        </p>
    </div>

    <dl @class(['flex flex-wrap justify-center gap-x-7 gap-y-3', 'md:justify-end' => $end, 'md:justify-start' => ! $end])>
        @foreach (\App\Enums\Discipline::cases() as $discipline)
            @php
                $index = $ranks[$discipline->value]['positions'][$user->id] ?? null;
                $played = $user->played($discipline);
            @endphp
            <div @class(['flex flex-col items-center', 'md:items-end' => $end, 'md:items-start' => ! $end])>
                <dt class="label-caps text-ink-muted">{{ $discipline->label() }}</dt>
                <dd @class(['mt-1.5 flex items-baseline gap-2', 'md:flex-row-reverse' => $end])>
                    @if ($played > 0)
                        <x-smash.rating :user="$user" :discipline="$discipline" size="lg" />
                        @if ($index !== null)
                            <span class="num text-[15px] font-bold text-ink-muted"><span class="sr-only">{{ __('rank') }}</span>#{{ $index + 1 }}</span>
                        @endif
                    @else
                        <span class="num text-[2rem] leading-none font-bold text-ink-muted" aria-hidden="true">–</span>
                        <span class="sr-only">{{ __('Not rated yet') }}</span>
                    @endif
                </dd>
            </div>
        @endforeach
    </dl>
</div>
