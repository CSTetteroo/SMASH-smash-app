<?php

namespace App\Services\Stats;

use App\Enums\Discipline;
use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * A player's numbers in one discipline (rated, completed matches).
 */
final readonly class PlayerStatsSummary
{
    /**
     * @param  int|null  $rank  position in the rankings, null when not ranked (inactive or no matches)
     * @param  int  $rankedPlayers  players in the rankings
     * @param  int  $rating  current rating, rounded
     * @param  int  $peak  highest rating reached, rounded
     * @param  float|null  $winRate  0–1, null without matches
     * @param  int  $currentStreak  +n wins or −n losses in a row (0 without matches)
     * @param  list<array{result: 'W'|'L', match_id: int}>  $form  newest first, at most five
     * @param  list<array{date: CarbonImmutable, rating: float, change: float, match_id: int}>  $ratingHistory  chronological; the first point is the rating before the first match (change 0)
     * @param  array{match_id: int, opponent: string, opponent_rating: int}|null  $bestWin  win over the highest-rated side (pre-match)
     * @param  list<array{user: User, played: int, wins: int}>  $partners  doubles partners, most matches first
     * @param  array{user: User, played: int, losses: int}|null  $nemesis  opponent with the most wins against this player (losing record, 2+ losses)
     * @param  array{user: User, played: int, wins: int}|null  $favouriteOpponent  opponent beaten most often (winning record, 2+ wins)
     */
    public function __construct(
        public ?int $rank,
        public int $rankedPlayers,
        public int $rating,
        public int $peak,
        public int $played,
        public int $wins,
        public int $losses,
        public ?float $winRate,
        public int $currentStreak,
        public int $longestWinStreak,
        public array $form,
        public array $ratingHistory,
        public float $change30d,
        public ?array $bestWin,
        public int $gamesWon,
        public int $gamesLost,
        public int $pointsWon,
        public int $pointsLost,
        public int $decidersWon,
        public int $decidersPlayed,
        public array $partners,
        public ?array $nemesis,
        public ?array $favouriteOpponent,
        public Discipline $discipline = Discipline::Singles,
        public bool $provisional = true,
    ) {}
}
