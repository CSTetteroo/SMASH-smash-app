@props([
    'title',
    // Collection of App\Services\Rating\LeaderboardRow (already limited, e.g. the top five).
    'rows',
    // Link to the full table.
    'href' => null,
    // Highlight this user's row (data-current).
    'for' => null,
    'empty' => null,
])

{{-- A short leaderboard (rank + movement, player, rating, form) in a table card with a serif title. --}}
<x-ui.table {{ $attributes }}>
    <x-slot:header>
        <h3 class="font-serif text-h4 font-normal text-heading">{{ $title }}</h3>
        @if ($href)
            <x-ui.link :href="$href">{{ __('Full table') }}</x-ui.link>
        @endif
    </x-slot:header>

    @if (collect($rows)->isEmpty())
        <p class="px-5 py-10 text-center text-[15px] text-ink-soft">{{ $empty ?? __('Nobody is ranked yet. The table fills up after the first rated match.') }}</p>
    @else
        <table>
            <thead>
                <tr>
                    <th class="w-24">#</th>
                    <th>{{ __('Player') }}</th>
                    <th class="text-end">{{ __('Rating') }}</th>
                    <th class="max-sm:hidden">{{ __('Form') }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($rows as $row)
                    <tr wire:key="rank-{{ $title }}-{{ $row->user->id }}" @if ($for && $row->user->is($for)) data-current @endif>
                        <td><x-smash.rank :position="$row->position" :movement="$row->movement" size="sm" /></td>
                        <td class="min-w-0"><x-smash.player :user="$row->user" size="sm" /></td>
                        <td class="text-end"><x-smash.rating :value="$row->rating" :provisional="$row->provisional" size="sm" /></td>
                        <td class="max-sm:hidden"><x-smash.form :results="$row->form" size="sm" /></td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif
</x-ui.table>
