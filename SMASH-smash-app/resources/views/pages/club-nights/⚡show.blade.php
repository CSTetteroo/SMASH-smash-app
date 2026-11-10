<?php

use App\Actions\ClubNights\CheckInAttendee;
use App\Actions\ClubNights\JoinClubNight;
use App\Actions\ClubNights\LeaveClubNight;
use App\Enums\MatchStatus;
use App\Models\BadmintonMatch;
use App\Models\ClubNight;
use App\Models\ClubNightRsvp;
use App\Models\User;
use Flux\Flux;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;

new class extends Component {
    #[Locked]
    public ClubNight $clubNight;

    /** Order of the "who's going" list: name · singles · doubles. */
    #[Url(except: 'name')]
    public string $sort = 'name';

    public function mount(ClubNight $clubNight): void
    {
        $this->clubNight = $clubNight;
        $this->normaliseSort();
    }

    public function updatedSort(): void
    {
        $this->normaliseSort();
    }

    public function render()
    {
        $night = $this->clubNight;

        return $this->view()
            ->title($night->title.' · '.$night->starts_at->format('j M'))
            ->layoutData(['description' => __(':title on :date, :time at the :venue. :kind for :level.', [
                'title' => $night->title,
                'date' => $night->starts_at->format('l j F'),
                'time' => $night->starts_at->format('H:i').'–'.$night->ends_at->format('H:i'),
                'venue' => $night->venue,
                'kind' => $night->kind->label(),
                'level' => $night->level !== null ? Str::lower($night->level->label()).' players' : 'every level',
            ])]);
    }

    /**
     * The night with every RSVP (first come, first served) and its member.
     */
    #[Computed]
    public function night(): ClubNight
    {
        return ClubNight::query()
            ->withRsvpCounts()
            ->with([
                'rsvps' => fn ($query) => $query->orderBy('created_at')->orderBy('id'),
                'rsvps.user',
            ])
            ->findOrFail($this->clubNight->id);
    }

    /**
     * Going RSVPs in the chosen order.
     *
     * @return \Illuminate\Support\Collection<int, ClubNightRsvp>
     */
    #[Computed]
    public function going(): \Illuminate\Support\Collection
    {
        $going = $this->night->rsvps->filter(fn (ClubNightRsvp $rsvp) => $rsvp->isGoing());

        $byName = fn (ClubNightRsvp $a, ClubNightRsvp $b) => strcmp(Str::lower(Str::ascii($a->user->name)), Str::lower(Str::ascii($b->user->name)));

        $sorted = match ($this->sort) {
            'singles' => $going->sortBy([fn (ClubNightRsvp $a, ClubNightRsvp $b) => $b->user->singles_rating <=> $a->user->singles_rating, $byName]),
            'doubles' => $going->sortBy([fn (ClubNightRsvp $a, ClubNightRsvp $b) => $b->user->doubles_rating <=> $a->user->doubles_rating, $byName]),
            default => $going->sortBy([$byName]),
        };

        return $sorted->values();
    }

    /**
     * The waitlist, first come first served.
     *
     * @return \Illuminate\Support\Collection<int, ClubNightRsvp>
     */
    #[Computed]
    public function waitlist(): \Illuminate\Support\Collection
    {
        return $this->night->rsvps->filter(fn (ClubNightRsvp $rsvp) => $rsvp->isWaitlisted())->values();
    }

    /**
     * Friendlies played during the night (played_at inside the session), plus friendlies scheduled
     * for it or still being played. Live matches first, then the latest.
     *
     * @return Collection<int, BadmintonMatch>
     */
    #[Computed]
    public function matches(): Collection
    {
        $night = $this->clubNight;
        $window = [$night->starts_at, $night->ends_at];

        return BadmintonMatch::query()
            ->withDetails()
            ->friendly()
            ->where(function (Builder $query) use ($night, $window) {
                $query->whereBetween('matches.played_at', $window);

                // Fixtures for the night (and matches still on court) only while it isn't over.
                if (! $night->isPast()) {
                    $query->orWhere(fn (Builder $query) => $query
                        ->whereNull('matches.played_at')
                        ->whereIn('matches.status', [MatchStatus::Scheduled->value, MatchStatus::InProgress->value])
                        ->whereBetween('matches.scheduled_at', $window));
                }
            })
            ->orderByRaw("case when matches.status = 'in_progress' then 0 else 1 end")
            ->latestFirst()
            ->limit(60)
            ->get();
    }

    /**
     * Admins can check people in from the day of the night onwards.
     */
    #[Computed]
    public function checkInOpen(): bool
    {
        return now()->greaterThanOrEqualTo($this->clubNight->starts_at->startOfDay());
    }

    public function join(?int $nightId = null): void
    {
        $night = $this->clubNight;
        $user = Auth::user();

        if (! $user instanceof User) {
            redirect()->setIntendedUrl(route('club-nights.show', $night));
            $this->redirectRoute('login');

            return;
        }

        if (! $user->hasVerifiedEmail()) {
            Flux::toast(variant: 'warning', heading: __('Verify your email first'), text: __('Use the link we emailed you, then RSVP. You can request a new link on the verification page.'));

            return;
        }

        if (Gate::forUser($user)->denies('rsvp', $night)) {
            Flux::toast(variant: 'danger', text: __('This club night has already finished, so RSVPs are closed.'));

            return;
        }

        try {
            $rsvp = JoinClubNight::handle($night, $user);
        } catch (ValidationException $exception) {
            Flux::toast(variant: 'danger', text: (string) collect($exception->errors())->flatten()->first());

            return;
        }

        if ($rsvp->isGoing()) {
            Flux::toast(variant: 'success', heading: __('You\'re going'), text: __('See you on :date. Can\'t make it after all? Leave here so someone else can have your place.', ['date' => $night->starts_at->format('l j F \a\t H:i')]));
        } else {
            Flux::toast(variant: 'warning', heading: __('You\'re on the waitlist'), text: __('You\'re #:position in line and move up automatically when a place frees up.', ['position' => $night->waitlistPosition($user) ?? 1]));
        }

        $this->refreshNight();
    }

    /**
     * Ask before giving up a place that others are waiting for.
     */
    public function confirmLeave(?int $nightId = null): void
    {
        Flux::modal('leave-night')->show();
    }

    public function leave(?int $nightId = null): void
    {
        $night = $this->clubNight;
        $user = Auth::user();

        Flux::modal('leave-night')->close();

        if (! $user instanceof User) {
            return;
        }

        if (Gate::forUser($user)->denies('rsvp', $night)) {
            Flux::toast(variant: 'danger', text: __('This club night has already finished, so RSVPs are closed.'));

            return;
        }

        $rsvp = $night->rsvps()->where('user_id', $user->id)->first();

        if ($rsvp === null) {
            Flux::toast(text: __('You hadn\'t RSVP\'d for this club night.'));

            return;
        }

        $handedOn = $rsvp->isGoing() && $night->rsvps()->waitlisted()->exists();

        try {
            LeaveClubNight::handle($night, $user);
        } catch (ValidationException $exception) {
            Flux::toast(variant: 'danger', text: (string) collect($exception->errors())->flatten()->first());

            return;
        }

        Flux::toast(text: match (true) {
            $rsvp->isWaitlisted() => __('You\'re off the waitlist.'),
            $handedOn => __('You\'ve left. Your place went to the first person on the waitlist.'),
            default => __('You\'ve left. Changed your mind? RSVP again while there are places.'),
        });

        $this->refreshNight();
    }

    /**
     * Admin: mark an attendee present (or undo it).
     */
    public function toggleCheckIn(int $rsvpId): void
    {
        $this->authorize('checkIn', $this->clubNight);

        $rsvp = $this->clubNight->rsvps()->with('user')->whereKey($rsvpId)->first();

        if ($rsvp === null) {
            Flux::toast(variant: 'danger', text: __('That RSVP no longer exists. The list has been refreshed.'));
            $this->refreshNight();

            return;
        }

        if (! $this->checkInOpen) {
            Flux::toast(variant: 'warning', text: __('Check-in opens on the day of the club night.'));

            return;
        }

        CheckInAttendee::handle($rsvp, ! $rsvp->isCheckedIn());

        $this->refreshNight();
    }

    /**
     * Admin: delete the night and its RSVPs.
     */
    public function delete(): void
    {
        $this->authorize('delete', $this->clubNight);

        $title = $this->clubNight->title;
        $date = $this->clubNight->starts_at->format('D j M');

        $this->clubNight->delete();

        Flux::toast(variant: 'success', text: __(':title on :date has been deleted.', ['title' => $title, 'date' => $date]));

        $this->redirectRoute('club-nights.index', navigate: true);
    }

    private function refreshNight(): void
    {
        unset($this->night, $this->going, $this->waitlist);
    }

    private function normaliseSort(): void
    {
        if (! in_array($this->sort, ['name', 'singles', 'doubles'], true)) {
            $this->sort = 'name';
        }
    }
}; ?>

@php
    $night = $this->night;
    $user = auth()->user();
    $isAdmin = $user?->can('manage') ?? false;
    $past = $night->isPast();
    $ongoing = $night->isOngoing();
    $started = $night->starts_at->isPast();
    $goingCount = $night->goingCount();
    $waitingCount = $night->waitlistCount();
    $checkedIn = $night->rsvps->filter(fn ($rsvp) => $rsvp->isCheckedIn())->count();
    $matches = $this->matches;
    $myRsvp = $user ? $night->rsvpFor($user) : null;

    // Past nights: members see who came (check-ins) when those were recorded; admins always see the full list.
    $showCheckedInOnly = $past && ! $isAdmin && $checkedIn > 0;
    $list = $showCheckedInOnly ? $this->going->filter(fn ($rsvp) => $rsvp->isCheckedIn())->values() : $this->going;
    $listTitle = match (true) {
        $showCheckedInOnly => __('Who came'),
        $past => __('Who was going'),
        default => __('Who\'s going'),
    };

    $minutes = (int) abs($night->starts_at->diffInMinutes($night->ends_at));
    $hours = intdiv($minutes, 60);
    $durationLabel = match (true) {
        $minutes % 60 === 0 => trans_choice(':count hour|:count hours', $hours),
        $minutes % 60 === 30 && $hours > 0 => $hours.'½ '.__('hours'),
        $hours === 0 => $minutes.' '.__('min'),
        default => $hours.' h '.($minutes % 60).' '.__('min'),
    };
@endphp

<div>
    <x-ui.page-hero
        :breadcrumbs="[[__('Club nights'), route('club-nights.index')], $night->title]"
        :kicker="$night->kind->label().' · '.$night->starts_at->format('l j F')"
        :title="$night->title"
        :intro="$night->description ?: $night->kind->description()"
    >
        <x-slot:meta>
            @if ($ongoing)
                <x-ui.live variant="solid" :label="__('On now')" />
            @elseif ($past)
                <x-ui.tag tone="grey" icon="flag">{{ __('Finished') }}</x-ui.tag>
            @elseif ($night->starts_at->isToday())
                <x-ui.tag tone="navy" variant="solid">{{ __('Tonight') }}</x-ui.tag>
            @endif
            <x-smash.status-badge :status="$night->kind" />
            <x-club-nights.level :level="$night->level" />
            @if (! $past && $night->isFull())
                <x-ui.tag tone="smash">{{ __('Full') }}</x-ui.tag>
            @endif
        </x-slot:meta>

        <x-slot:actions>
            @unless ($past)
                <x-ui.button variant="outline" icon="calendar-days" :href="route('club-nights.show.ics', $night)" :navigate="false">{{ __('Add to calendar') }}</x-ui.button>
            @endunless
            @can('update', $night)
                <x-ui.button variant="outline" icon="pencil-square" :href="route('club-nights.edit', $night)">{{ __('Edit') }}</x-ui.button>
                <x-ui.button variant="ghost" icon="document-duplicate" :href="route('club-nights.create', ['duplicate' => $night->id])">{{ __('Duplicate to next week') }}</x-ui.button>
            @endcan
            @can('delete', $night)
                <flux:modal.trigger name="delete-night">
                    <x-ui.button variant="danger-outline" icon="trash">{{ __('Delete') }}</x-ui.button>
                </flux:modal.trigger>
            @endcan
        </x-slot:actions>

        <x-slot:aside>
            <div class="flex items-start gap-5">
                <x-ui.date-badge :date="$night->starts_at" weekday size="lg" />
                <div class="min-w-0">
                    <p class="kicker">{{ $past ? __('Played on') : __('When and where') }}</p>
                    <p class="mt-1 font-serif text-h4 leading-tight text-heading">{{ $night->starts_at->format('l j F') }}</p>
                    <div class="mt-3 space-y-1.5">
                        <x-ui.meta icon="clock">{{ $night->starts_at->format('H:i') }} – {{ $night->ends_at->format('H:i') }} <span class="text-ink-muted">· {{ $durationLabel }}</span></x-ui.meta>
                        <x-ui.meta icon="map-pin">{{ $night->venue }} · {{ trans_choice(':count court|:count courts', $night->courts) }}</x-ui.meta>
                    </div>
                </div>
            </div>

            @if ($past)
                <x-smash.stat-row class="mt-6" size="sm" :columns="3" :items="[
                    ['value' => $checkedIn ?: '–', 'label' => __('Came'), 'sublabel' => $checkedIn ? __('checked in') : __('not recorded')],
                    ['value' => $goingCount, 'label' => __('RSVPs'), 'sublabel' => __('of :capacity places', ['capacity' => $night->capacity])],
                    ['value' => $matches->count() ?: '–', 'label' => __('Matches'), 'sublabel' => __('logged')],
                ]" />
            @else
                <x-ui.progress
                    class="mt-6"
                    :value="$goingCount"
                    :max="$night->capacity"
                    capacity
                    :label="__('Places taken')"
                    :value-label="$night->isFull() ? ($waitingCount > 0 ? __('Full · :count waiting', ['count' => $waitingCount]) : __('Full')) : $goingCount.' / '.$night->capacity"
                />
                <x-club-nights.attendees class="mt-4" :users="$night->rsvps->filter->isGoing()" :total="$goingCount" :max="8" />
            @endif

            <div class="mt-6 border-t border-line pt-5" id="your-rsvp">
                @auth
                    <p class="label-caps mb-3 text-ink-muted">{{ __('Your RSVP') }}</p>
                @endauth
                <x-club-nights.rsvp :night="$night" :rsvp="$myRsvp" prominent explain />
            </div>
        </x-slot:aside>
    </x-ui.page-hero>

    {{-- ============================================================== WHO'S GOING --}}
    <x-ui.section id="attendees" :kicker="__(':count going', ['count' => $goingCount]).($waitingCount ? ' · '.__(':count on the waitlist', ['count' => $waitingCount]) : '')" :title="$listTitle">
        @if ($isAdmin && $this->checkInOpen && $goingCount > 0)
            <x-slot:actions>
                <x-ui.progress class="w-56 max-w-full" :value="$checkedIn" :max="max(1, $goingCount)" tone="green" size="sm" :label="__('Checked in')" :value-label="$checkedIn.' / '.$goingCount" data-test="attendance" />
            </x-slot:actions>
        @endif

        <div class="grid items-start gap-6 lg:grid-cols-[minmax(0,2fr)_minmax(0,1fr)]">
            <div class="min-w-0">
                @if ($list->count() > 1)
                    <x-ui.tabs :label="__('Sort attendees')" variant="pills" class="mb-4">
                        <x-ui.tabs.item wire:click="$set('sort', 'name')" :current="$sort === 'name'">{{ __('Name') }}</x-ui.tabs.item>
                        <x-ui.tabs.item wire:click="$set('sort', 'singles')" :current="$sort === 'singles'">{{ __('Singles rating') }}</x-ui.tabs.item>
                        <x-ui.tabs.item wire:click="$set('sort', 'doubles')" :current="$sort === 'doubles'">{{ __('Doubles rating') }}</x-ui.tabs.item>
                    </x-ui.tabs>
                @endif

                @if ($list->isEmpty())
                    <x-ui.empty :title="$past ? __('Nobody RSVP\'d for this night') : __('Nobody\'s going yet')" icon="user-group">
                        {{ $past ? __('The committee didn\'t record anyone for this club night.') : __('RSVP and be the first name on the list.') }}
                    </x-ui.empty>
                @else
                    <x-ui.table>
                        <table>
                            <thead>
                                <tr>
                                    <th class="w-12 text-end">#</th>
                                    <th>{{ __('Player') }}</th>
                                    <th class="text-end">{{ __('Singles') }}</th>
                                    <th class="text-end max-sm:hidden">{{ __('Doubles') }}</th>
                                    @if ($isAdmin && $this->checkInOpen)
                                        <th class="text-end">{{ __('Check-in') }}</th>
                                    @endif
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($list as $rsvp)
                                    <tr wire:key="going-{{ $rsvp->id }}" @if ($user && $rsvp->user_id === $user->id) data-current @endif>
                                        <td class="num text-end text-[15px] font-semibold text-ink-muted">{{ $loop->iteration }}</td>
                                        <td class="min-w-0">
                                            <x-smash.player :user="$rsvp->user" size="sm">
                                                @if ($user && $rsvp->user_id === $user->id)
                                                    <x-ui.tag tone="navy" size="sm">{{ __('You') }}</x-ui.tag>
                                                @endif
                                            </x-smash.player>
                                        </td>
                                        <td class="text-end"><x-smash.rating :user="$rsvp->user" discipline="singles" size="sm" /></td>
                                        <td class="text-end max-sm:hidden"><x-smash.rating :user="$rsvp->user" discipline="doubles" size="sm" /></td>
                                        @if ($isAdmin && $this->checkInOpen)
                                            <td class="text-end"><x-club-nights.check-in :rsvp="$rsvp" /></td>
                                        @endif
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </x-ui.table>
                @endif

                @if ($isAdmin && ! $this->checkInOpen && $goingCount > 0)
                    <p class="mt-4 text-sm text-ink-muted">{{ __('Check-in opens on :date, the day of the club night.', ['date' => $night->starts_at->format('l j F')]) }}</p>
                @endif
            </div>

            <div class="grid min-w-0 gap-6">
                <x-ui.card padding="none" as="section" aria-labelledby="waitlist-title">
                    <div class="flex items-baseline justify-between gap-3 border-b border-line px-5 py-4">
                        <h3 id="waitlist-title" class="font-serif text-h5 text-heading">{{ __('Waitlist') }}</h3>
                        <span class="num text-[19px] font-bold text-ink">{{ $waitingCount }}</span>
                    </div>
                    @if ($this->waitlist->isEmpty())
                        <p class="px-5 py-4 text-[15px] leading-relaxed text-ink-soft">
                            @if ($past)
                                {{ __('Nobody was waiting for a place.') }}
                            @elseif ($night->isFull())
                                {{ __('Nobody\'s waiting yet. Join the waitlist and you\'re first in line.') }}
                            @else
                                {{ __('Nobody\'s waiting: there are still places left.') }}
                            @endif
                        </p>
                    @else
                        <ol class="divide-y divide-line">
                            @foreach ($this->waitlist as $rsvp)
                                <li class="flex items-center gap-3 px-5 py-3" wire:key="waiting-{{ $rsvp->id }}" @if ($user && $rsvp->user_id === $user->id) data-current @endif>
                                    <span class="num w-5 shrink-0 text-end text-[17px] font-bold text-ink-muted">{{ $loop->iteration }}</span>
                                    <x-smash.player class="min-w-0 flex-1" :user="$rsvp->user" size="sm" :meta="__('Since :time', ['time' => $rsvp->created_at?->format('D j M, H:i')])" />
                                    @if ($isAdmin && $this->checkInOpen)
                                        <x-club-nights.check-in :rsvp="$rsvp" />
                                    @endif
                                </li>
                            @endforeach
                        </ol>
                    @endif
                </x-ui.card>

                <x-ui.card variant="muted" padding="md">
                    <h3 class="font-serif text-h5 text-heading">{{ __('How the waitlist works') }}</h3>
                    <p class="mt-2 text-[15px] leading-relaxed text-ink-soft">
                        {{ __('Places are first come, first served. When someone who\'s going cancels, the first person on the waitlist takes their place automatically, and everyone behind them moves up one.') }}
                    </p>
                    <p class="mt-2 text-[15px] leading-relaxed text-ink-soft">
                        {{ __('Still waiting on the night? Come anyway: when there\'s room on court, the committee can check you in at the door.') }}
                    </p>
                </x-ui.card>
            </div>
        </div>
    </x-ui.section>

    {{-- ============================================================== MATCHES / GOOD TO KNOW --}}
    @if ($started || $matches->isNotEmpty())
        <x-ui.section id="matches" tone="grey"
            :kicker="$matches->isEmpty() ? null : trans_choice(':count match|:count matches', $matches->count())"
            :title="$past ? __('Matches played') : ($started ? __('Matches tonight') : __('Matches planned'))"
            :intro="$past ? null : __('Friendlies count towards the rankings once they\'re logged.')"
        >
            @if (Route::has('matches.create') && $user && ! $past)
                <x-slot:actions>
                    <x-ui.button variant="primary" icon="plus" size="sm" :href="route('matches.create')">{{ __('Log a match') }}</x-ui.button>
                </x-slot:actions>
            @endif

            @if ($matches->isEmpty())
                <x-ui.empty :title="$past ? __('No matches were logged') : __('No matches logged yet')" variant="bordered">
                    {{ $past
                        ? __('Nobody logged a friendly at this club night. Played one? Log it with the date and time of the night and it shows up here.')
                        : __('Finished a friendly? Log the score and it shows up here and in the rankings.') }}
                </x-ui.empty>
            @else
                <div class="grid gap-6 md:grid-cols-2 xl:grid-cols-3">
                    @foreach ($matches as $match)
                        <x-smash.match-card :match="$match" :for="$user" wire:key="match-{{ $match->id }}" />
                    @endforeach
                </div>
            @endif
        </x-ui.section>
    @endif

    @unless ($started)
        <x-ui.section id="good-to-know" :tone="$matches->isNotEmpty() ? 'white' : 'grey'" :kicker="__('Before you come')" :title="__('Good to know')">
            <div class="grid gap-6 md:grid-cols-2 xl:grid-cols-4">
                <x-ui.card :highlight="$night->kind->tone()" padding="md">
                    <h3 class="font-serif text-h5 text-heading">{{ $night->kind->label() }}</h3>
                    <p class="mt-2 text-[15px] leading-relaxed text-ink-soft">{{ $night->kind->description() }}</p>
                </x-ui.card>
                <x-ui.card padding="md">
                    <h3 class="font-serif text-h5 text-heading">{{ $night->level ? __('Level: :level', ['level' => $night->level->label()]) : __('Every level welcome') }}</h3>
                    <p class="mt-2 text-[15px] leading-relaxed text-ink-soft">{{ $night->level?->description() ?? __('From first-timers to league players: rotate on and off court and mix up partners.') }}</p>
                </x-ui.card>
                <x-ui.card padding="md">
                    <h3 class="font-serif text-h5 text-heading">{{ __('What to bring') }}</h3>
                    <p class="mt-2 text-[15px] leading-relaxed text-ink-soft">{{ __('Indoor sports shoes with non-marking soles, sports clothes and a water bottle. Bring your racket if you have one; the shuttles are on us.') }}</p>
                </x-ui.card>
                <x-ui.card padding="md">
                    <h3 class="font-serif text-h5 text-heading">{{ __('Play for your rating') }}</h3>
                    <p class="mt-2 text-[15px] leading-relaxed text-ink-soft">{{ __('Friendlies you play count towards the singles and doubles rankings. Log the score afterwards and watch your rating move.') }}</p>
                    @if (Route::has('how-it-works'))
                        <x-ui.link class="mt-auto pt-4" :href="route('how-it-works')" variant="promo">{{ __('How ratings work') }}</x-ui.link>
                    @endif
                </x-ui.card>
            </div>
        </x-ui.section>
    @endunless

    {{-- ============================================================== MODALS --}}
    @auth
        <flux:modal name="leave-night" class="md:w-[30rem]">
            <div class="space-y-6">
                <div>
                    <flux:heading size="lg">{{ __('Give up your place at :title?', ['title' => $night->title]) }}</flux:heading>
                    <flux:text class="mt-2">
                        {{ trans_choice(':count person is on the waitlist and gets your place straight away.|:count people are on the waitlist. The first of them gets your place straight away.', $waitingCount) }}
                        {{ __('If you change your mind, you\'d join the back of the waitlist.') }}
                    </flux:text>
                </div>
                <div class="flex flex-wrap justify-end gap-2">
                    <flux:modal.close>
                        <x-ui.button variant="ghost" size="sm">{{ __('Keep my place') }}</x-ui.button>
                    </flux:modal.close>
                    <x-ui.button variant="danger" size="sm" wire:click="leave">{{ __('Leave') }}</x-ui.button>
                </div>
            </div>
        </flux:modal>
    @endauth

    @can('delete', $night)
        <flux:modal name="delete-night" class="md:w-[30rem]">
            <div class="space-y-6">
                <div>
                    <flux:heading size="lg">{{ __('Delete :title?', ['title' => $night->title]) }}</flux:heading>
                    <flux:text class="mt-2">
                        {{ __('The club night on :date is removed from the schedule and the calendar feed.', ['date' => $night->starts_at->format('l j F')]) }}
                        @if ($goingCount + $waitingCount > 0)
                            {{ trans_choice('Its :count RSVP is deleted with it.|All :count RSVPs and check-ins are deleted with it.', $goingCount + $waitingCount) }}
                        @endif
                        {{ __('Matches played that evening stay in the results. This cannot be undone.') }}
                    </flux:text>
                </div>
                <div class="flex flex-wrap justify-end gap-2">
                    <flux:modal.close>
                        <x-ui.button variant="ghost" size="sm">{{ __('Cancel') }}</x-ui.button>
                    </flux:modal.close>
                    <x-ui.button variant="danger" size="sm" icon="trash" wire:click="delete">{{ __('Delete club night') }}</x-ui.button>
                </div>
            </div>
        </flux:modal>
    @endcan
</div>
