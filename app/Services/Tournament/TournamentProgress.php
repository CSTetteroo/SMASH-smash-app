<?php

namespace App\Services\Tournament;

use App\Enums\MatchStatus;
use App\Enums\Side;
use App\Enums\TournamentStatus;
use App\Models\BadmintonMatch;
use App\Models\MatchPlayer;
use App\Models\Tournament;
use App\Models\TournamentEntry;
use Illuminate\Support\Facades\DB;

/**
 * Keeps a tournament consistent after one of its matches changes.
 *
 * Knockout: the winner's entry moves into the next round (round + 1, position ceil(pos / 2);
 * odd positions fill side A, even side B) and that match's participants are re-synced. Reverting
 * or changing a result propagates the same way, as long as the next match has not started.
 * The tournament is Completed once the final (knockout) or every non-cancelled match (round
 * robin) is finished, and back In progress when that stops being true.
 */
final class TournamentProgress
{
    /**
     * A match in one of these states has started or finished, so its line-up is fixed.
     */
    private const LOCKED = [MatchStatus::InProgress, MatchStatus::Completed, MatchStatus::Walkover];

    /**
     * Call after saving a tournament match (SaveMatch does this for you).
     */
    public static function matchUpdated(BadmintonMatch $match): void
    {
        if ($match->tournament_id === null) {
            return;
        }

        $tournament = Tournament::query()->find($match->tournament_id);

        if ($tournament === null) {
            return;
        }

        $match->setRelation('tournament', $tournament);

        DB::transaction(static function () use ($match, $tournament): void {
            if ($tournament->isKnockout()) {
                self::advance($match);
            }

            self::refreshStatus($tournament);
        });
    }

    /**
     * Whether the result of $match may still change who goes through: true unless the knockout
     * match it feeds has already started or finished (friendlies, round robins and finals: always).
     */
    public static function canChangeWinner(BadmintonMatch $match): bool
    {
        $next = self::nextMatch($match);

        return $next === null || ! in_array($next->status, self::LOCKED, true);
    }

    /**
     * The knockout match the winner of $match moves on to (null for finals, round robins and friendlies).
     */
    public static function nextMatch(BadmintonMatch $match): ?BadmintonMatch
    {
        if ($match->tournament_id === null || $match->round === null || $match->bracket_position === null || ! $match->isKnockout()) {
            return null;
        }

        return BadmintonMatch::query()
            ->where('tournament_id', $match->tournament_id)
            ->where('round', $match->round + 1)
            ->where('bracket_position', self::nextPosition($match->bracket_position))
            ->first();
    }

    /**
     * Position in the next round: ceil(position / 2).
     */
    public static function nextPosition(int $bracketPosition): int
    {
        return intdiv($bracketPosition + 1, 2);
    }

    /**
     * The side of the next match that the winner of a match at $bracketPosition fills: odd => A, even => B.
     */
    public static function nextSide(int $bracketPosition): Side
    {
        return $bracketPosition % 2 === 1 ? Side::A : Side::B;
    }

    /**
     * The entry that goes through from $match: the winning entry once finished, else null.
     */
    public static function advancingEntryId(BadmintonMatch $match): ?int
    {
        if (! $match->isFinished() || $match->winning_side === null) {
            return null;
        }

        return $match->winning_side === Side::A ? $match->entry_a_id : $match->entry_b_id;
    }

    /**
     * Make the participants match the two entries: entry A's players on side A, entry B's on side B.
     * Until both entries are known the match has no participants.
     */
    public static function syncParticipants(BadmintonMatch $match): void
    {
        /** @var array<int, Side> $wanted user id => side */
        $wanted = [];

        if ($match->entry_a_id !== null && $match->entry_b_id !== null) {
            $entries = TournamentEntry::query()
                ->whereKey([$match->entry_a_id, $match->entry_b_id])
                ->get(['id', 'player_id', 'partner_id'])
                ->keyBy('id');

            foreach ([Side::A->value => $match->entry_a_id, Side::B->value => $match->entry_b_id] as $side => $entryId) {
                $entry = $entries->get($entryId);

                foreach (array_filter([$entry?->player_id, $entry?->partner_id]) as $userId) {
                    $wanted[$userId] = Side::from($side);
                }
            }
        }

        foreach ($match->participants()->get() as $participant) {
            /** @var MatchPlayer $participant */
            $side = $wanted[$participant->user_id] ?? null;

            if ($side === null) {
                $participant->delete();

                continue;
            }

            if ($participant->side !== $side) {
                $participant->side = $side;
                $participant->save();
            }

            unset($wanted[$participant->user_id]);
        }

        foreach ($wanted as $userId => $side) {
            $match->participants()->create(['user_id' => $userId, 'side' => $side]);
        }

        $match->unsetRelation('participants');
        $match->unsetRelation('players');
    }

    /**
     * Completed when the tournament is decided, back to In progress when it no longer is.
     * Draft and open tournaments are left alone.
     */
    public static function refreshStatus(Tournament $tournament): void
    {
        if (! in_array($tournament->status, [TournamentStatus::InProgress, TournamentStatus::Completed], true)) {
            return;
        }

        $status = self::isDecided($tournament) ? TournamentStatus::Completed : TournamentStatus::InProgress;

        if ($tournament->status !== $status) {
            $tournament->status = $status;
            $tournament->save();
        }
    }

    /**
     * Knockout: the final is finished. Round robin: every match that is not cancelled is finished.
     */
    public static function isDecided(Tournament $tournament): bool
    {
        $matches = $tournament->matches()->get(['id', 'tournament_id', 'round', 'bracket_position', 'status', 'winning_side']);

        if ($matches->isEmpty()) {
            return false;
        }

        if ($tournament->isKnockout()) {
            $lastRound = $matches->max('round');
            $final = $matches
                ->filter(fn (BadmintonMatch $match): bool => $match->round === $lastRound)
                ->sortBy('bracket_position')
                ->first();

            return $final !== null && $final->isFinished() && $final->winning_side !== null;
        }

        $playable = $matches->reject(fn (BadmintonMatch $match): bool => $match->status === MatchStatus::Cancelled);

        return $playable->isNotEmpty()
            && $playable->every(fn (BadmintonMatch $match): bool => $match->isFinished());
    }

    /**
     * Put the entry going through from $match into its slot of the next match.
     */
    private static function advance(BadmintonMatch $match): void
    {
        if ($match->bracket_position === null) {
            return;
        }

        $next = self::nextMatch($match);

        if ($next === null || in_array($next->status, self::LOCKED, true)) {
            return;
        }

        $column = self::nextSide($match->bracket_position) === Side::A ? 'entry_a_id' : 'entry_b_id';
        $entryId = self::advancingEntryId($match);
        $previous = $next->getAttribute($column);

        if ($previous === $entryId) {
            return;
        }

        $next->setAttribute($column, $entryId);
        $next->save();

        if ($previous !== null) {
            // The line-up changed, so picks made for the old matchup no longer mean anything.
            $next->predictions()->delete();
        }

        self::syncParticipants($next);
    }
}
