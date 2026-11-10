<?php

use App\Enums\MatchStatus;
use App\Enums\Side;
use App\Models\BadmintonMatch;
use App\Models\User;
use App\Support\Live\LiveScore;
use App\Support\Live\LiveScoreStore;
use App\Support\Live\LiveScoringException;
use App\Support\Live\ScoreConflict;
use App\Support\Live\Umpire;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;

/*
 | Umpire mode (live scoring) for one match: admins, or the match's own players while it is not
 | finished (BadmintonMatchPolicy::score), checked again inside every action.
 |
 | The rally logic lives in App\Support\Live (LiveScore = the rules, Umpire = saving through
 | SaveMatch after every rally, LiveScoreStore = the rally log in the cache under
 | "live-score:{id}", so a reload restores it and undo works across reloads).
 */
new class extends Component {
    /** The match being scored (from the route, never from the client). */
    #[Locked]
    public int $matchId;

    /** Fingerprint of the scoresheet on screen: a tap made against an older one is refused. */
    #[Locked]
    public ?string $version = null;

    /** Side A is drawn on the right-hand end (the players changed ends). Display only. */
    public bool $swapped = false;

    /** First server picked on the start screen: 'a' or 'b'. */
    public string $firstServer = '';

    public function mount(BadmintonMatch $match): void
    {
        $this->authorize('score', $match);

        $this->matchId = $match->id;
        $this->swapped = (bool) session($this->swapKey(), false);
    }

    public function render()
    {
        $this->version = $this->state?->version();

        return $this->view()->title(__('Umpire mode · :matchup', ['matchup' => $this->match->matchup()]));
    }

    #[Computed]
    public function match(): BadmintonMatch
    {
        return BadmintonMatch::query()
            ->with(['participants.user', 'games', 'tournament', 'entryA.player', 'entryA.partner', 'entryB.player', 'entryB.partner'])
            ->findOrFail($this->matchId);
    }

    /**
     * The live state: the cached rally log while it still matches the saved games, else the
     * saved games picked up afresh. Null unless the match is in progress (or when its saved
     * games cannot be continued).
     */
    #[Computed]
    public function state(): ?LiveScore
    {
        try {
            return LiveScoreStore::load($this->match);
        } catch (\InvalidArgumentException) {
            return null;
        }
    }

    /**
     * What the console shows: start · lineup · serve · rally · decided · finished · cancelled · unreadable.
     */
    #[Computed]
    public function phase(): string
    {
        $match = $this->match;

        return match (true) {
            $match->isFinished() => 'finished',
            $match->status === MatchStatus::Cancelled => 'cancelled',
            $match->status === MatchStatus::Scheduled => Umpire::lineUpKnown($match) ? 'start' : 'lineup',
            $this->state === null => 'unreadable',
            $this->state->isDecided() => 'decided',
            $this->state->needsServer() => 'serve',
            default => 'rally',
        };
    }

    // ------------------------------------------------------------------
    // Actions (each one authorises again: Livewire actions can be called directly)
    // ------------------------------------------------------------------

    /**
     * Start a scheduled match with the chosen first server.
     */
    public function start(): void
    {
        $match = $this->authorised();

        $this->validate(
            ['firstServer' => ['required', 'in:a,b']],
            ['firstServer.required' => __('Choose the side that serves first.'), 'firstServer.in' => __('Choose the side that serves first.')],
        );

        $server = Side::from($this->firstServer);

        $this->attempt(function () use ($match, $server): void {
            Umpire::start($match, $server, $this->user());
            $this->firstServer = '';

            Flux::toast(variant: 'success', heading: __('Match started'), text: __(':name to serve. Play well, both sides.', ['name' => $match->sideLabel($server)]));
        });
    }

    /**
     * Say who serves next (a match picked up from the saved games).
     */
    public function serve(string $side): void
    {
        $match = $this->authorised();
        $server = $this->side($side);

        $this->attempt(fn () => Umpire::serve($match, $server, $this->user(), $this->version));
    }

    /**
     * $side won the rally.
     */
    public function point(string $side): void
    {
        $match = $this->authorised();
        $winner = $this->side($side);

        $this->attempt(function () use ($match, $winner): void {
            $state = Umpire::point($match, $winner, $this->user(), $this->version);

            if ($state->isDecided()) {
                Flux::modal('confirm-result')->show();
            }
        });
    }

    /**
     * Forget the last rally (or the server choice).
     */
    public function undo(): void
    {
        $match = $this->authorised();

        $this->attempt(fn () => Umpire::undo($match, $this->user(), $this->version));
    }

    /**
     * Confirm the result of a decided match: saved as completed (ratings recalculated, the
     * winner moves on in a knockout), then on to the match page.
     */
    public function finish(): void
    {
        $match = $this->authorised();
        $saved = null;

        $this->attempt(function () use ($match, &$saved): void {
            $saved = Umpire::finish($match, $this->user(), $this->version);
        });

        if (! $saved instanceof BadmintonMatch || $saved->winning_side === null) {
            Flux::modal('confirm-result')->close();

            return;
        }

        $winner = $saved->winning_side;

        Flux::toast(
            variant: 'success',
            heading: __('Result saved'),
            text: __(':name won :score.', [
                'name' => $saved->sideLabel($winner),
                'score' => $saved->gamesWon($winner).'–'.$saved->gamesWon($winner->opposite()),
            ]).' '.($saved->is_rated ? __('Ratings are updated.') : __('This match was unrated, so ratings stay as they were.')),
        );

        $this->redirectRoute('matches.show', ['match' => $saved->id], navigate: true);
    }

    /**
     * Mirror the console when the players change ends (remembered for this match on this device).
     */
    public function swap(): void
    {
        $this->swapped = ! $this->swapped;
        session()->put($this->swapKey(), $this->swapped);
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /**
     * The match fresh from the database, after checking the viewer may still score it.
     */
    private function authorised(): BadmintonMatch
    {
        $this->forgetComputed();
        $this->authorize('score', $this->match);

        return $this->match;
    }

    /**
     * Run an umpire action, turning its refusals into a toast for the umpire.
     */
    private function attempt(\Closure $action): void
    {
        try {
            $action();
        } catch (ScoreConflict $exception) {
            Flux::toast(variant: 'warning', heading: __('Score updated elsewhere'), text: $exception->getMessage());
        } catch (LiveScoringException $exception) {
            Flux::toast(variant: 'danger', text: $exception->getMessage());
        } finally {
            $this->forgetComputed();
        }
    }

    private function side(string $value): Side
    {
        $this->resetErrorBag('side');

        return Side::tryFrom($value) ?? throw ValidationException::withMessages([
            'side' => __('Choose side A or side B.'),
        ]);
    }

    private function user(): User
    {
        $user = Auth::user();

        abort_unless($user instanceof User, 403);

        return $user;
    }

    private function forgetComputed(): void
    {
        unset($this->match, $this->state, $this->phase);
    }

    private function swapKey(): string
    {
        return 'live-score.'.$this->matchId.'.swapped';
    }
}; ?>

<div>
    <x-live.header :match="$this->match" />

    <x-live.console
        :match="$this->match"
        :state="$this->state"
        :phase="$this->phase"
        :swapped="$swapped"
        :can-manage="auth()->user()?->can('manage') ?? false"
    />

    <x-live.details :match="$this->match" :state="$this->state" :phase="$this->phase" />

    {{-- Outside the navy console, so it keeps the page's own colours. --}}
    <x-live.confirm :match="$this->match" :state="$this->phase === 'decided' ? $this->state : null" />
</div>
