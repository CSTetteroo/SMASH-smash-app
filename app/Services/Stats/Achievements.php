<?php

namespace App\Services\Stats;

use App\Enums\Discipline;
use App\Enums\MatchStatus;
use App\Enums\Side;
use App\Enums\TournamentStatus;
use App\Models\BadmintonMatch;
use App\Models\MatchPlayer;
use App\Models\Prediction;
use App\Models\Tournament;
use App\Models\TournamentEntry;
use App\Models\User;
use App\Services\Tournament\TournamentProgress;
use Carbon\CarbonImmutable;

/**
 * Profile badges. Match badges count every completed match (rated or not, both disciplines);
 * Giant Slayer needs the stored pre-match ratings of a rated match.
 */
final class Achievements
{
    public const GIANT_SLAYER_GAP = 150;

    public const SHUTOUT_MAX_CONCEDED = 5;

    public const PEAK_TARGET = 1700;

    /**
     * key => [name, description, icon, tone, target for count-based badges].
     *
     * @var array<string, array{0: string, 1: string, 2: string, 3: string, 4: int|null}>
     */
    private const CATALOGUE = [
        'first_blood' => ['First Blood', 'Win your first match.', 'bolt', 'smash', null],
        'giant_slayer' => ['Giant Slayer', 'Beat a side rated at least 150 points higher than yours.', 'arrow-trending-up', 'orange', null],
        'on_fire' => ['On Fire', 'Win five matches in a row.', 'fire', 'smash', 5],
        'iron_lungs' => ['Iron Lungs', 'Win a game that went past 21, like 23–21 or 30–29.', 'heart', 'sky', null],
        'comeback_kid' => ['Comeback Kid', 'Win a match after losing the first game.', 'arrow-uturn-up', 'green', null],
        'shutout' => ['Shutout', 'Win a game conceding five points or fewer.', 'shield-check', 'navy', null],
        'champion' => ['Champion', 'Win a club tournament.', 'trophy', 'orange', null],
        'finalist' => ['Finalist', 'Reach a tournament final, or finish in the top two of a round robin.', 'star', 'navy', null],
        'dynamic_duo' => ['Dynamic Duo', 'Win 10 doubles matches with the same partner.', 'user-group', 'sky', 10],
        'veteran' => ['Veteran', 'Play 25 matches.', 'check-badge', 'navy', 25],
        'club_regular' => ['Club Regular', 'Check in at 10 club nights.', 'calendar-days', 'green', 10],
        'oracle' => ['Oracle', "Call 10 winners correctly in pick'em.", 'eye', 'sky', 10],
        'peak_1700' => ['Peak 1700', 'Reach a rating of 1700 in singles or doubles.', 'chart-bar', 'orange', self::PEAK_TARGET],
    ];

    /**
     * Every badge, in a fixed order, with earned flags, dates and progress.
     *
     * @return list<Achievement>
     */
    public static function for(User $user): array
    {
        /** @var array<string, CarbonImmutable|null> $earnedAt key => first time (null = earned, date unknown) */
        $earnedAt = [];
        $current = [];

        self::matchBadges($user, $earnedAt, $current);
        self::tournamentBadges($user, $earnedAt);
        self::clubNightBadge($user, $earnedAt, $current);
        self::predictionBadge($user, $earnedAt, $current);

        $peak = max($user->peak(Discipline::Singles), $user->peak(Discipline::Doubles));
        $current['peak_1700'] = $peak;

        if ($peak >= self::PEAK_TARGET && ! array_key_exists('peak_1700', $earnedAt)) {
            $earnedAt['peak_1700'] = null;
        }

        $achievements = [];

        foreach (self::CATALOGUE as $key => [$name, $description, $icon, $tone, $target]) {
            $earned = array_key_exists($key, $earnedAt);

            $achievements[] = new Achievement(
                key: $key,
                name: $name,
                description: $description,
                icon: $icon,
                tone: $tone,
                earned: $earned,
                earnedAt: $earned ? $earnedAt[$key] : null,
                progress: $target === null ? null : [
                    'current' => min($target, $earned ? $target : (int) ($current[$key] ?? 0)),
                    'target' => $target,
                ],
            );
        }

        return $achievements;
    }

    /**
     * @param  array<string, CarbonImmutable|null>  $earnedAt
     * @param  array<string, int>  $current
     */
    private static function matchBadges(User $user, array &$earnedAt, array &$current): void
    {
        $matches = BadmintonMatch::query()
            ->forPlayer($user)
            ->where('matches.status', MatchStatus::Completed->value)
            ->whereNotNull('matches.winning_side')
            ->with(['participants', 'games'])
            ->orderByRaw('matches.played_at is null desc')
            ->orderBy('matches.played_at')
            ->orderBy('matches.id')
            ->get();

        $played = 0;
        $run = 0;
        $longestRun = 0;
        /** @var array<int, int> $partnerWins */
        $partnerWins = [];

        foreach ($matches as $match) {
            $mine = $match->participantFor($user);

            if ($mine === null) {
                continue;
            }

            $side = $mine->side;
            $won = $match->winning_side === $side;
            $when = $match->played_at ?? $match->updated_at;
            $played++;

            if ($played === 25) {
                $earnedAt['veteran'] ??= $when;
            }

            if ($mine->rating_after !== null && $mine->rating_after >= self::PEAK_TARGET) {
                $earnedAt['peak_1700'] ??= $when;
            }

            foreach ($match->games as $game) {
                $for = $game->pointsFor($side);
                $against = $game->pointsFor($side->opposite());

                if ($for > $against && $for > $match->points_per_game) {
                    $earnedAt['iron_lungs'] ??= $when;
                }

                if ($for > $against && $against <= self::SHUTOUT_MAX_CONCEDED) {
                    $earnedAt['shutout'] ??= $when;
                }
            }

            if (! $won) {
                $run = 0;

                continue;
            }

            $run++;
            $longestRun = max($longestRun, $run);
            $earnedAt['first_blood'] ??= $when;

            if ($run === 5) {
                $earnedAt['on_fire'] ??= $when;
            }

            $first = $match->games->first();

            if ($match->best_of > 1 && $first !== null && $first->pointsFor($side) < $first->pointsFor($side->opposite())) {
                $earnedAt['comeback_kid'] ??= $when;
            }

            if (self::ratingGap($match, $side) >= self::GIANT_SLAYER_GAP) {
                $earnedAt['giant_slayer'] ??= $when;
            }

            if ($match->discipline->playersPerSide() > 1) {
                foreach ($match->participants as $participant) {
                    if ($participant->side === $side && $participant->user_id !== $user->id) {
                        $partnerWins[$participant->user_id] = ($partnerWins[$participant->user_id] ?? 0) + 1;

                        if ($partnerWins[$participant->user_id] === 10) {
                            $earnedAt['dynamic_duo'] ??= $when;
                        }
                    }
                }
            }
        }

        $current['veteran'] = $played;
        $current['on_fire'] = $longestRun;
        $current['dynamic_duo'] = $partnerWins === [] ? 0 : max($partnerWins);
    }

    /**
     * Opponents' mean pre-match rating minus our side's (null-safe: 0 when ratings are missing).
     */
    private static function ratingGap(BadmintonMatch $match, Side $side): float
    {
        if (! $match->is_rated) {
            return 0.0;
        }

        $ours = $match->participants->filter(fn (MatchPlayer $participant): bool => $participant->side === $side);
        $theirs = $match->participants->filter(fn (MatchPlayer $participant): bool => $participant->side !== $side);
        $ratings = $match->participants->map(fn (MatchPlayer $participant): ?float => $participant->rating_before);

        if ($ours->isEmpty() || $theirs->isEmpty() || $ratings->contains(null)) {
            return 0.0;
        }

        return (float) $theirs->avg('rating_before') - (float) $ours->avg('rating_before');
    }

    /**
     * @param  array<string, CarbonImmutable|null>  $earnedAt
     */
    private static function tournamentBadges(User $user, array &$earnedAt): void
    {
        $entries = TournamentEntry::query()->forPlayer($user)->with('tournament')->get();

        foreach ($entries as $entry) {
            $tournament = $entry->tournament;

            if ($tournament->isKnockout()) {
                self::knockoutBadges($tournament, $entry, $earnedAt);

                continue;
            }

            if ($tournament->status !== TournamentStatus::Completed) {
                continue;
            }

            $when = $tournament->ends_at ?? $tournament->starts_at;

            if ($tournament->champion()?->id === $entry->id) {
                $earnedAt['champion'] = self::earlier($earnedAt['champion'] ?? false, $when);
                $earnedAt['finalist'] = self::earlier($earnedAt['finalist'] ?? false, $when);
            } elseif ($tournament->runnerUp()?->id === $entry->id) {
                $earnedAt['finalist'] = self::earlier($earnedAt['finalist'] ?? false, $when);
            }
        }
    }

    /**
     * @param  array<string, CarbonImmutable|null>  $earnedAt
     */
    private static function knockoutBadges(Tournament $tournament, TournamentEntry $entry, array &$earnedAt): void
    {
        $final = $tournament->finalMatch();

        if ($final === null || ! in_array($entry->id, [$final->entry_a_id, $final->entry_b_id], true)) {
            return;
        }

        $semiFinal = $final->round > 1
            ? $tournament->matches()
                ->where('round', $final->round - 1)
                ->where(fn ($query) => $query->where('entry_a_id', $entry->id)->orWhere('entry_b_id', $entry->id))
                ->first()
            : null;

        $earnedAt['finalist'] = self::earlier(
            $earnedAt['finalist'] ?? false,
            $semiFinal->played_at ?? $final->played_at ?? $tournament->starts_at,
        );

        if (TournamentProgress::advancingEntryId($final) === $entry->id) {
            $earnedAt['champion'] = self::earlier($earnedAt['champion'] ?? false, $final->played_at ?? $tournament->ends_at);
        }
    }

    /**
     * @param  array<string, CarbonImmutable|null>  $earnedAt
     * @param  array<string, int>  $current
     */
    private static function clubNightBadge(User $user, array &$earnedAt, array &$current): void
    {
        $checkIns = $user->rsvps()
            ->whereNotNull('checked_in_at')
            ->orderBy('checked_in_at')
            ->pluck('checked_in_at')
            ->values();

        $current['club_regular'] = $checkIns->count();

        if ($checkIns->count() >= 10) {
            $tenth = $checkIns->get(9);
            $earnedAt['club_regular'] = $tenth instanceof CarbonImmutable ? $tenth : null;
        }
    }

    /**
     * @param  array<string, CarbonImmutable|null>  $earnedAt
     * @param  array<string, int>  $current
     */
    private static function predictionBadge(User $user, array &$earnedAt, array &$current): void
    {
        $correct = $user->predictions()
            ->with('match.games')
            ->get()
            ->filter(fn (Prediction $prediction): bool => $prediction->isCorrect() === true)
            ->sortBy(fn (Prediction $prediction): string => ($prediction->match->played_at?->format('Y-m-d H:i:s') ?? '').sprintf('%010d', $prediction->match_id))
            ->values();

        $current['oracle'] = $correct->count();

        if ($correct->count() >= 10) {
            $earnedAt['oracle'] = $correct->get(9)?->match->played_at;
        }
    }

    /**
     * The earlier of an existing date (false = none yet, null = unknown) and a new one.
     */
    private static function earlier(CarbonImmutable|false|null $existing, ?CarbonImmutable $candidate): ?CarbonImmutable
    {
        if ($existing === false) {
            return $candidate;
        }

        if ($existing === null || $candidate === null) {
            return $existing ?? $candidate;
        }

        return $candidate->lessThan($existing) ? $candidate : $existing;
    }
}
