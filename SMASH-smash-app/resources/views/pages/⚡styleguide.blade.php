<?php

use App\Enums\Discipline;
use App\Enums\MatchStatus;
use App\Enums\Programme;
use App\Enums\SkillLevel;
use App\Enums\TournamentFormat;
use App\Enums\TournamentStatus;
use App\Models\BadmintonMatch;
use App\Models\MatchGame;
use App\Models\MatchPlayer;
use App\Models\Tournament;
use App\Models\TournamentEntry;
use App\Models\User;
use Carbon\CarbonImmutable;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Title('Styleguide')] class extends Component {
    #[Url]
    public string $discipline = 'singles';

    public bool $notifications = true;

    public string $level = 'intermediate';

    /**
     * Demo action for the loading state of x-ui.button.
     */
    public function saveDemo(): void
    {
        usleep(900_000);

        Flux::toast(variant: 'success', text: __('Match saved. Ratings recalculated.'));
    }

    /**
     * @return LengthAwarePaginator<int, int>
     */
    #[Computed]
    public function paginator(): LengthAwarePaginator
    {
        return new LengthAwarePaginator(range(1, 12), 186, 12, 3, ['path' => request()->url()]);
    }

    /**
     * Synthetic rating history (same shape as PlayerStats::ratingHistory).
     *
     * @return list<array{date: \Carbon\CarbonImmutable, rating: float, change: float}>
     */
    #[Computed]
    public function history(): array
    {
        $rating = 1500.0;
        $points = [];
        $day = \Carbon\CarbonImmutable::parse('2026-09-01 20:00');

        foreach ([0, 24, 18, -14, 21, 9, -19, 16, 12, -8, 14, -21, 10, 8, 15, -12, 9, 11, -16, 13, 7, -9, 12, 6, -11, 10, 9] as $i => $change) {
            $rating += $change;
            $points[] = ['date' => $day->addDays($i * 2 + intdiv($i, 3)), 'rating' => $rating, 'change' => (float) $change];
        }

        return $points;
    }

    /**
     * @return list<array{pos:int, name:string, programme:string, rating:int, played:int, wins:int, form:list<string>, move:?int, change:float, provisional:bool}>
     */
    #[Computed]
    public function rankings(): array
    {
        return [
            ['pos' => 1, 'name' => 'Mateusz Kowalczyk', 'programme' => 'Logistics', 'rating' => 1742, 'played' => 31, 'wins' => 25, 'form' => ['W', 'W', 'W', 'L', 'W'], 'move' => 0, 'change' => 18.4, 'provisional' => false],
            ['pos' => 2, 'name' => 'Lotte Jansen', 'programme' => 'Games', 'rating' => 1698, 'played' => 27, 'wins' => 20, 'form' => ['W', 'L', 'W', 'W', 'W'], 'move' => 2, 'change' => 31.0, 'provisional' => false],
            ['pos' => 3, 'name' => 'Wei Chen', 'programme' => 'Data Science & AI', 'rating' => 1671, 'played' => 22, 'wins' => 15, 'form' => ['L', 'W', 'W', 'L', 'W'], 'move' => -1, 'change' => -6.2, 'provisional' => false],
            ['pos' => 4, 'name' => 'Ayşe Yılmaz', 'programme' => 'Tourism', 'rating' => 1634, 'played' => 19, 'wins' => 12, 'form' => ['W', 'W', 'L', 'L', 'W'], 'move' => 1, 'change' => 12.9, 'provisional' => false],
            ['pos' => 5, 'name' => 'Sam de Vries', 'programme' => 'Media & Creative Business', 'rating' => 1609, 'played' => 24, 'wins' => 13, 'form' => ['L', 'L', 'W', 'W', 'L'], 'move' => -2, 'change' => -21.5, 'provisional' => false],
            ['pos' => 6, 'name' => 'Priya Raman', 'programme' => 'Hotel', 'rating' => 1588, 'played' => 7, 'wins' => 5, 'form' => ['W', 'W', 'W', 'L', 'W'], 'move' => null, 'change' => 88.0, 'provisional' => true],
        ];
    }

    // -----------------------------------------------------------------------------------------
    // Domain component samples (x-smash.*): UNSAVED models with hand-set ids (90001+) and every
    // relation the components read already set, so nothing here touches the database.
    // -----------------------------------------------------------------------------------------

    /**
     * @return array<string, User>
     */
    #[Computed]
    public function demoPlayers(): array
    {
        /**
         * @param  array{0: float, 1: int, 2: int, 3: float}  $singles  rating, played, wins, peak
         * @param  array{0: float, 1: int, 2: int, 3: float}  $doubles
         */
        $player = fn (int $id, string $name, Programme $programme, SkillLevel $level, array $singles, array $doubles, string $role = 'member'): User => (new User)->forceFill([
            'id' => $id,
            'name' => $name,
            'role' => $role,
            'programme' => $programme,
            'level' => $level,
            'is_active' => true,
            'singles_rating' => $singles[0],
            'singles_played' => $singles[1],
            'singles_wins' => $singles[2],
            'singles_peak' => $singles[3],
            'doubles_rating' => $doubles[0],
            'doubles_played' => $doubles[1],
            'doubles_wins' => $doubles[2],
            'doubles_peak' => $doubles[3],
        ]);

        return [
            'mateusz' => $player(90001, 'Mateusz Kowalczyk', Programme::Logistics, SkillLevel::Competitive, [1742.3, 31, 25, 1760.4], [1650.2, 18, 11, 1666.0]),
            'lotte' => $player(90002, 'Lotte Jansen', Programme::Games, SkillLevel::Advanced, [1698.4, 27, 20, 1712.8], [1712.5, 22, 17, 1721.3], 'admin'),
            'wei' => $player(90003, 'Wei Chen', Programme::DataScienceAi, SkillLevel::Advanced, [1659.6, 23, 15, 1690.4], [1604.1, 14, 8, 1611.0]),
            'ayse' => $player(90004, 'Ayşe Yılmaz', Programme::Tourism, SkillLevel::Advanced, [1634.0, 19, 12, 1641.2], [1588.0, 12, 6, 1600.0]),
            'sam' => $player(90005, 'Sam de Vries', Programme::MediaCreativeBusiness, SkillLevel::Intermediate, [1609.4, 24, 13, 1633.0], [1664.3, 21, 14, 1664.3]),
            'priya' => $player(90006, 'Priya Raman', Programme::Hotel, SkillLevel::Intermediate, [1519.2, 7, 5, 1519.2], [1520.0, 4, 2, 1532.0]),
            'lucas' => $player(90007, 'Lucas Peeters', Programme::BuiltEnvironment, SkillLevel::Intermediate, [1555.0, 16, 8, 1570.0], [1640.1, 20, 13, 1640.1]),
            'giulia' => $player(90008, 'Giulia Romano', Programme::LeisureEvents, SkillLevel::Intermediate, [1532.0, 15, 7, 1551.0], [1588.2, 18, 10, 1610.0]),
            'daan' => $player(90009, 'Daan Bakker', Programme::Imagineering, SkillLevel::Beginner, [1480.0, 12, 4, 1512.0], [1502.0, 9, 4, 1515.0]),
            'hannah' => $player(90010, 'Hannah Schmidt', Programme::FacilityManagement, SkillLevel::Beginner, [1512.0, 3, 1, 1515.0], [1500.0, 0, 0, 1500.0]),
            'arjun' => $player(90011, 'Arjun Mehta', Programme::DataScienceAi, SkillLevel::Advanced, [1621.0, 18, 11, 1630.0], [1576.0, 10, 5, 1590.0]),
            'elena' => $player(90012, 'Elena García', Programme::Hotel, SkillLevel::Competitive, [1588.0, 14, 8, 1602.0], [1622.0, 16, 10, 1630.0]),
        ];
    }

    /**
     * @return array<string, Tournament>
     */
    #[Computed]
    public function demoTournaments(): array
    {
        $tournament = fn (int $id, string $name, Discipline $discipline, TournamentFormat $format, TournamentStatus $status, string $startsAt, int $entries, ?int $max): Tournament => (new Tournament)->forceFill([
            'id' => $id,
            'name' => $name,
            'slug' => Str::slug($name),
            'discipline' => $discipline,
            'format' => $format,
            'status' => $status,
            'venue' => 'BUas Sports Hall',
            'starts_at' => CarbonImmutable::parse($startsAt),
            'max_entries' => $max,
            'best_of' => 3,
            'points_per_game' => 21,
            'is_rated' => true,
            // Read by entryCount() / totalRounds(), so round labels need no query.
            'entries_count' => $entries,
        ]);

        return [
            'halloween' => $tournament(93001, 'Halloween Smash', Discipline::Singles, TournamentFormat::SingleElimination, TournamentStatus::InProgress, '2026-10-31 10:00', 24, 32),
            'welcome' => $tournament(93002, 'Welcome Week Open', Discipline::Singles, TournamentFormat::SingleElimination, TournamentStatus::Completed, '2026-09-12 10:00', 16, 16),
            'winter' => $tournament(93003, 'Winter Doubles Showdown', Discipline::Doubles, TournamentFormat::SingleElimination, TournamentStatus::Open, '2026-12-12 10:00', 9, 16),
        ];
    }

    /**
     * @return array<string, TournamentEntry>
     */
    #[Computed]
    public function demoEntries(): array
    {
        $p = $this->demoPlayers;
        $t = $this->demoTournaments;

        return [
            // Halloween Smash (singles): the last eight.
            'mateusz' => $this->demoEntry(94001, $t['halloween'], $p['mateusz'], null, 1),
            'lotte' => $this->demoEntry(94002, $t['halloween'], $p['lotte'], null, 2),
            'wei' => $this->demoEntry(94003, $t['halloween'], $p['wei'], null, 3),
            'ayse' => $this->demoEntry(94004, $t['halloween'], $p['ayse'], null, 4),
            'arjun' => $this->demoEntry(94005, $t['halloween'], $p['arjun'], null, 5),
            'sam' => $this->demoEntry(94006, $t['halloween'], $p['sam'], null, 6),
            'elena' => $this->demoEntry(94007, $t['halloween'], $p['elena'], null, 7),
            'priya' => $this->demoEntry(94008, $t['halloween'], $p['priya']),
            'giulia' => $this->demoEntry(94009, $t['halloween'], $p['giulia']),
            // Winter Doubles Showdown (doubles pairs, registration open).
            'pairLotteSam' => $this->demoEntry(94101, $t['winter'], $p['lotte'], $p['sam'], 1),
            'pairLucasGiulia' => $this->demoEntry(94102, $t['winter'], $p['lucas'], $p['giulia'], 2),
            'pairElenaArjun' => $this->demoEntry(94103, $t['winter'], $p['elena'], $p['arjun']),
            'pairDaanHannah' => $this->demoEntry(94104, $t['winter'], $p['daan'], $p['hannah']),
            // Welcome Week Open (completed).
            'wwLotte' => $this->demoEntry(94201, $t['welcome'], $p['lotte'], null, 2),
            'wwMateusz' => $this->demoEntry(94202, $t['welcome'], $p['mateusz'], null, 1),
            'wwWei' => $this->demoEntry(94203, $t['welcome'], $p['wei'], null, 3),
            'wwSam' => $this->demoEntry(94204, $t['welcome'], $p['sam'], null, 6),
        ];
    }

    /**
     * Every match state the cards handle.
     *
     * @return array<string, BadmintonMatch>
     */
    #[Computed]
    public function demoMatches(): array
    {
        $p = $this->demoPlayers;
        $t = $this->demoTournaments;
        $e = $this->demoEntries;
        $halloween = $t['halloween'];

        return [
            'tournament' => $this->demoMatch(91001, [
                'round' => 2, 'bracket_position' => 7, 'court' => 2, 'winning_side' => 'a',
                'scheduled_at' => '2026-10-31 15:30', 'played_at' => '2026-10-31 15:58',
            ], [[$p['lotte'], 1689.6, 1698.4]], [[$p['giulia'], 1540.8, 1532.0]], [[21, 17], [19, 21], [21, 15]], $halloween, $e['lotte'], $e['giulia']),

            'upset' => $this->demoMatch(91002, [
                'court' => 4, 'winning_side' => 'a', 'played_at' => '2026-11-02 21:10',
            ], [[$p['priya'], 1488.1, 1519.2]], [[$p['wei'], 1678.4, 1659.6]], [[18, 21], [21, 19], [23, 21]]),

            'doubles' => $this->demoMatch(91003, [
                'discipline' => Discipline::Doubles, 'court' => 1, 'winning_side' => 'a', 'played_at' => '2026-10-27 20:40',
            ], [[$p['sam'], 1654.1, 1664.3], [$p['lucas'], 1630.2, 1640.1]], [[$p['lotte'], 1720.6, 1712.5], [$p['giulia'], 1598.4, 1588.2]], [[21, 18], [21, 16]]),

            'live' => $this->demoMatch(91004, [
                'status' => MatchStatus::InProgress, 'court' => 3, 'scheduled_at' => '2026-11-03 20:15',
            ], [[$p['mateusz'], null, null]], [[$p['ayse'], null, null]], [[21, 15], [18, 21], [14, 11]]),

            'fixture' => $this->demoMatch(91005, [
                'status' => MatchStatus::Scheduled, 'round' => 4, 'bracket_position' => 1, 'court' => 1, 'scheduled_at' => '2026-11-07 14:00',
            ], [[$p['mateusz'], null, null]], [[$p['arjun'], null, null]], [], $halloween, $e['mateusz'], $e['arjun']),

            'tbd' => $this->demoMatch(91006, [
                'status' => MatchStatus::Scheduled, 'round' => 4, 'bracket_position' => 2, 'court' => 2, 'scheduled_at' => '2026-11-07 14:45',
            ], [], [], [], $halloween, null, $e['lotte']),

            'challenge' => $this->demoMatch(91007, [
                'status' => MatchStatus::Scheduled, 'court' => 2, 'scheduled_at' => '2026-11-05 20:30',
            ], [[$p['elena'], null, null]], [[$p['priya'], null, null]]),

            'walkover' => $this->demoMatch(91008, [
                'status' => MatchStatus::Walkover, 'winning_side' => 'b', 'is_rated' => false, 'scheduled_at' => '2026-10-20 20:00', 'played_at' => '2026-10-20 20:00',
            ], [[$p['hannah'], null, null]], [[$p['daan'], null, null]]),

            'bye' => $this->demoMatch(91009, [
                'status' => MatchStatus::Walkover, 'round' => 1, 'bracket_position' => 1, 'winning_side' => 'a', 'is_rated' => false,
                'scheduled_at' => '2026-10-31 10:00', 'played_at' => '2026-10-31 10:00',
            ], [], [], [], $halloween, $e['mateusz'], null),

            'cancelled' => $this->demoMatch(91010, [
                'discipline' => Discipline::Doubles, 'status' => MatchStatus::Cancelled, 'scheduled_at' => '2026-10-29 21:00',
            ], [[$p['arjun'], null, null], [$p['elena'], null, null]], [[$p['daan'], null, null], [$p['hannah'], null, null]]),

            'unrated' => $this->demoMatch(91011, [
                'best_of' => 1, 'points_per_game' => 15, 'is_rated' => false, 'winning_side' => 'b', 'court' => 4, 'played_at' => '2026-10-29 21:40',
            ], [[$p['daan'], null, null]], [[$p['hannah'], null, null]], [[12, 15]]),
        ];
    }

    /**
     * Halloween Smash from the quarter-finals on (Bracket::for() shape).
     *
     * @return list<array{round: int, label: string, matches: EloquentCollection<int, BadmintonMatch>}>
     */
    #[Computed]
    public function demoBracket(): array
    {
        $p = $this->demoPlayers;
        $e = $this->demoEntries;
        $t = $this->demoTournaments['halloween'];
        $fixtures = $this->demoMatches;

        $quarterFinals = new EloquentCollection([
            $this->demoMatch(92001, ['round' => 3, 'bracket_position' => 1, 'court' => 1, 'winning_side' => 'a', 'scheduled_at' => '2026-10-31 18:00', 'played_at' => '2026-10-31 18:31'],
                [[$p['mateusz'], 1736.0, 1742.3]], [[$p['priya'], 1525.5, 1519.2]], [[21, 14], [21, 17]], $t, $e['mateusz'], $e['priya']),
            $this->demoMatch(92002, ['round' => 3, 'bracket_position' => 2, 'court' => 2, 'winning_side' => 'b', 'scheduled_at' => '2026-10-31 18:00', 'played_at' => '2026-10-31 18:52'],
                [[$p['ayse'], 1645.1, 1634.0]], [[$p['arjun'], 1609.9, 1621.0]], [[21, 19], [16, 21], [19, 21]], $t, $e['ayse'], $e['arjun']),
            $this->demoMatch(92003, ['round' => 3, 'bracket_position' => 3, 'court' => 3, 'status' => MatchStatus::InProgress, 'scheduled_at' => '2026-11-03 20:00'],
                [[$p['wei'], null, null]], [[$p['sam'], null, null]], [[21, 19], [17, 21], [9, 6]], $t, $e['wei'], $e['sam']),
            $this->demoMatch(92004, ['round' => 3, 'bracket_position' => 4, 'status' => MatchStatus::Walkover, 'winning_side' => 'b', 'is_rated' => false, 'scheduled_at' => '2026-10-31 18:45', 'played_at' => '2026-10-31 18:45'],
                [[$p['elena'], null, null]], [[$p['lotte'], null, null]], [], $t, $e['elena'], $e['lotte']),
        ]);

        $semiFinals = new EloquentCollection([
            $fixtures['fixture'],
            $fixtures['tbd'],
        ]);

        $final = new EloquentCollection([
            $this->demoMatch(92007, ['round' => 5, 'bracket_position' => 1, 'status' => MatchStatus::Scheduled, 'court' => 1, 'scheduled_at' => '2026-11-07 16:30'], [], [], [], $t),
        ]);

        return [
            ['round' => 3, 'label' => $t->roundLabel(3, true), 'matches' => $quarterFinals],
            ['round' => 4, 'label' => $t->roundLabel(4, true), 'matches' => $semiFinals],
            ['round' => 5, 'label' => $t->roundLabel(5), 'matches' => $final],
        ];
    }

    /**
     * Welcome Week Open, semi-finals on, with its champion.
     *
     * @return list<array{round: int, label: string, matches: EloquentCollection<int, BadmintonMatch>}>
     */
    #[Computed]
    public function demoFinishedBracket(): array
    {
        $p = $this->demoPlayers;
        $e = $this->demoEntries;
        $t = $this->demoTournaments['welcome'];

        return [
            ['round' => 3, 'label' => $t->roundLabel(3, true), 'matches' => new EloquentCollection([
                $this->demoMatch(92101, ['round' => 3, 'bracket_position' => 1, 'court' => 1, 'winning_side' => 'b', 'played_at' => '2026-09-12 15:10'],
                    [[$p['mateusz'], 1655.2, 1641.9]], [[$p['sam'], 1560.4, 1573.7]], [[19, 21], [21, 18], [20, 22]], $t, $e['wwMateusz'], $e['wwSam']),
                $this->demoMatch(92102, ['round' => 3, 'bracket_position' => 2, 'court' => 2, 'winning_side' => 'b', 'played_at' => '2026-09-12 15:25'],
                    [[$p['wei'], 1602.0, 1590.1]], [[$p['lotte'], 1611.8, 1623.7]], [[15, 21], [18, 21]], $t, $e['wwWei'], $e['wwLotte']),
            ])],
            ['round' => 4, 'label' => $t->roundLabel(4), 'matches' => new EloquentCollection([
                $this->demoMatch(92103, ['round' => 4, 'bracket_position' => 1, 'court' => 1, 'winning_side' => 'b', 'played_at' => '2026-09-12 17:05'],
                    [[$p['sam'], 1573.7, 1561.9]], [[$p['lotte'], 1623.7, 1635.5]], [[21, 23], [21, 12], [17, 21]], $t, $e['wwSam'], $e['wwLotte']),
            ])],
        ];
    }

    /**
     * Lotte Jansen's singles season in the PlayerStats::ratingHistory shape: the first point is the
     * rating before her first match, then one point per rated match.
     *
     * @return list<array{date: CarbonImmutable, rating: float, change: float, match_id: int}>
     */
    #[Computed]
    public function demoHistory(): array
    {
        $results = [
            ['2026-09-01 20:30', 22.4], ['2026-09-03 20:15', 18.6], ['2026-09-03 21:05', -11.3], ['2026-09-08 20:00', 21.5],
            ['2026-09-10 20:40', 17.3], ['2026-09-12 10:30', 16.4], ['2026-09-12 13:00', 26.9], ['2026-09-12 15:25', 11.9],
            ['2026-09-12 17:05', 11.8], ['2026-09-15 20:20', -9.4], ['2026-09-17 20:50', 14.2], ['2026-09-22 20:10', -9.3],
            ['2026-09-29 20:30', 14.2], ['2026-10-01 20:45', 12.7], ['2026-10-06 20:05', -9.8], ['2026-10-08 21:00', 14.3],
            ['2026-10-13 20:35', 13.6], ['2026-10-15 20:15', 12.7], ['2026-10-20 20:30', 12.5], ['2026-10-22 20:50', 11.6],
            ['2026-10-27 20:20', -15.8], ['2026-10-29 20:45', -7.4], ['2026-10-31 15:58', 8.8],
        ];

        $rating = 1500.0;
        $points = [['date' => CarbonImmutable::parse($results[0][0]), 'rating' => $rating, 'change' => 0.0, 'match_id' => 95001]];
        foreach ($results as $i => [$at, $change]) {
            $rating += $change;
            $points[] = ['date' => CarbonImmutable::parse($at), 'rating' => round($rating, 1), 'change' => $change, 'match_id' => 95001 + $i];
        }

        return $points;
    }

    /**
     * A newcomer's first three rated matches (still provisional).
     *
     * @return list<array{date: CarbonImmutable, rating: float, change: float}>
     */
    #[Computed]
    public function newcomerHistory(): array
    {
        return [
            ['date' => CarbonImmutable::parse('2026-10-20 20:10'), 'rating' => 1500.0, 'change' => 0.0],
            ['date' => CarbonImmutable::parse('2026-10-20 20:10'), 'rating' => 1478.4, 'change' => -21.6],
            ['date' => CarbonImmutable::parse('2026-10-22 20:40'), 'rating' => 1511.9, 'change' => 33.5],
            ['date' => CarbonImmutable::parse('2026-10-29 21:00'), 'rating' => 1519.2, 'change' => 7.3],
        ];
    }

    /**
     * An unsaved match with every relation the components read already set.
     *
     * @param  array<string, mixed>  $attributes
     * @param  list<array{0: User, 1: float|null, 2: float|null}>  $sideA  [player, rating_before, rating_after]
     * @param  list<array{0: User, 1: float|null, 2: float|null}>  $sideB
     * @param  list<array{0: int, 1: int}>  $games  [side A points, side B points] per game
     */
    protected function demoMatch(int $id, array $attributes, array $sideA, array $sideB, array $games = [], ?Tournament $tournament = null, ?TournamentEntry $entryA = null, ?TournamentEntry $entryB = null): BadmintonMatch
    {
        $match = (new BadmintonMatch)->forceFill($attributes + [
            'id' => $id,
            'discipline' => $tournament?->discipline ?? Discipline::Singles,
            'status' => MatchStatus::Completed,
            'best_of' => 3,
            'points_per_game' => 21,
            'is_rated' => true,
            'tournament_id' => $tournament?->id,
            'entry_a_id' => $entryA?->id,
            'entry_b_id' => $entryB?->id,
        ]);

        $participants = [];
        foreach (['a' => $sideA, 'b' => $sideB] as $side => $players) {
            foreach ($players as [$user, $before, $after]) {
                $participants[] = (new MatchPlayer)->forceFill([
                    'id' => $id * 10 + count($participants),
                    'match_id' => $id,
                    'user_id' => $user->id,
                    'side' => $side,
                    'rating_before' => $before,
                    'rating_after' => $after,
                ])->setRelation('user', $user);
            }
        }

        $gameModels = [];
        foreach ($games as $i => [$a, $b]) {
            $gameModels[] = (new MatchGame)->forceFill([
                'id' => $id * 10 + $i,
                'match_id' => $id,
                'number' => $i + 1,
                'side_a_points' => $a,
                'side_b_points' => $b,
            ]);
        }

        return $match
            ->setRelation('participants', new EloquentCollection($participants))
            ->setRelation('games', new EloquentCollection($gameModels))
            ->setRelation('tournament', $tournament)
            ->setRelation('entryA', $entryA)
            ->setRelation('entryB', $entryB);
    }

    protected function demoEntry(int $id, Tournament $tournament, User $player, ?User $partner = null, ?int $seed = null): TournamentEntry
    {
        return (new TournamentEntry)->forceFill([
            'id' => $id,
            'tournament_id' => $tournament->id,
            'player_id' => $player->id,
            'partner_id' => $partner?->id,
            'seed' => $seed,
        ])
            ->setRelation('tournament', $tournament)
            ->setRelation('player', $player)
            ->setRelation('partner', $partner);
    }
}; ?>

@php
    $swatches = [
        'navy' => ['50', '100', '200', '300', '400', '500', '600', '700', '800', '900', '950'],
        'orange' => ['50', '100', '200', '300', '400', '500', '600', '700', '800', '900', '950'],
        'green' => ['50', '100', '200', '300', '400', '500', '600', '700', '800', '900', '950'],
        'smash' => ['50', '100', '200', '300', '400', '500', '600', '700', '800', '900', '950'],
        'zinc' => ['50', '100', '200', '300', '400', '500', '600', '700', '800', '900', '950'],
    ];
    $brandNotes = ['navy-800' => 'BUas navy', 'navy-600' => 'Link blue', 'navy-400' => 'Cyan', 'navy-100' => 'Search pill', 'orange-500' => 'Logo orange', 'orange-400' => 'Footer kicker', 'orange-700' => 'Kicker text', 'green-600' => 'CTA', 'green-700' => 'CTA hover', 'smash-500' => 'SMASH red', 'zinc-100' => 'Band grey', 'zinc-200' => 'Hairline', 'zinc-500' => 'Body text', 'zinc-800' => 'Ink'];
    $semantic = [
        ['canvas', 'Page background'], ['canvas-muted', 'Grey section band'], ['surface', 'Cards, panels, menus'], ['surface-muted', 'Table heads, wells'],
        ['ink', 'Strong text, values'], ['ink-soft', 'Body copy'], ['ink-muted', 'Meta, captions'], ['heading', 'Serif headings'],
        ['line', 'Hairlines'], ['line-strong', 'Outlines, inputs'], ['link', 'Links'], ['kicker', 'Kickers'],
        ['cta', 'CTA buttons'], ['win', 'Wins, rating up'], ['loss', 'Losses, rating down'], ['live', 'Live matches'],
    ];
    $tones = ['navy', 'sky', 'orange', 'green', 'smash', 'grey'];
@endphp

<div>
    <x-ui.page-hero
        :breadcrumbs="[['Design system', route('dev.styleguide')], 'Styleguide']"
        kicker="SMASH design system"
        title="Styleguide"
        intro="BUas house style with SMASH sport energy. Every token and primitive the pages are built from — with real club copy, in light and dark mode."
        court
    >
        <x-slot:meta>
            <x-ui.tag tone="navy">Tailwind v4</x-ui.tag>
            <x-ui.tag tone="sky">Livewire 4</x-ui.tag>
            <x-ui.tag tone="green">Flux free</x-ui.tag>
            <x-ui.tag tone="orange" variant="soft">resources/views/components/ui</x-ui.tag>
        </x-slot:meta>

        <x-slot:actions>
            <x-ui.button variant="cta" href="#buttons">View the buttons</x-ui.button>
            <x-ui.button variant="outline" href="#tables">Tables &amp; data</x-ui.button>
        </x-slot:actions>

        <x-slot:aside>
            <div class="flex items-start gap-5">
                <x-ui.date-badge date="2026-11-05 20:00" weekday />
                <div class="min-w-0">
                    <p class="kicker">Next club night</p>
                    <p class="mt-1 font-serif text-2xl leading-tight text-heading">Thursday social</p>
                    <div class="mt-3 space-y-1.5">
                        <x-ui.meta icon="clock">20:00 – 22:30</x-ui.meta>
                        <x-ui.meta icon="map-pin">BUas Sports Hall · 4 courts</x-ui.meta>
                    </div>
                </div>
            </div>
            <x-ui.progress class="mt-6" :value="21" :max="24" capacity label="Spots taken" />
            <x-ui.button variant="cta" class="mt-6" href="#cards">I'm coming</x-ui.button>
            <div class="mt-6 flex flex-col items-start gap-2 border-t border-line pt-5">
                <x-ui.link variant="serif" href="#cards">Never played before?</x-ui.link>
                <x-ui.link href="#cards">All club nights</x-ui.link>
            </div>
        </x-slot:aside>
    </x-ui.page-hero>

    {{-- ================================================================================= BRAND --}}
    <x-ui.section id="brand" kicker="Brand" title="Logo and mark" intro="The SMASH lockup: hexagon badge, red BUAS BADMINTON tag, Barlow Condensed 900 wordmark and the red bar. Use the light variant on white, inverse on navy.">
        <div class="grid gap-6 lg:grid-cols-[minmax(0,3fr)_minmax(0,2fr)]">
            <x-ui.card padding="lg" class="items-start gap-10">
                <x-app-logo size="xl" />
                <div class="flex flex-wrap items-end gap-10">
                    <x-app-logo size="lg" />
                    <x-app-logo size="md" />
                    <x-app-logo size="sm" />
                </div>
            </x-ui.card>
            <div class="grid gap-6">
                <x-ui.card variant="navy" padding="lg" class="items-start gap-8">
                    <x-app-logo variant="inverse" size="lg" />
                    <x-app-logo variant="inverse" size="sm" />
                </x-ui.card>
                <x-ui.card padding="lg">
                    <p class="label-caps text-ink-muted">x-app-logo-icon · 64 / 48 / 32 / 24 / 16 px</p>
                    <div class="mt-4 flex flex-wrap items-end gap-6">
                        <x-app-logo-icon class="size-16" />
                        <x-app-logo-icon class="size-12" />
                        <x-app-logo-icon class="size-8" />
                        <x-app-logo-icon simple class="size-6" />
                        <x-app-logo-icon simple class="size-4" />
                        <img src="/favicon.svg" alt="Favicon" class="ms-auto size-8">
                    </div>
                </x-ui.card>
            </div>
        </div>
    </x-ui.section>

    {{-- ================================================================================= COLOUR --}}
    <x-ui.section id="colour" tone="grey" kicker="Tokens" title="Colour" intro="Brand scales from buas.nl. Flux paints with zinc, so zinc is remapped to the BUas cool greys. Pages use the semantic tokens so dark mode just works.">
        <div class="grid gap-8">
            @foreach ($swatches as $scale => $steps)
                <div>
                    <p class="label-caps mb-3 text-ink-muted">{{ $scale }}</p>
                    <div class="grid grid-cols-6 gap-px bg-line sm:grid-cols-11">
                        @foreach ($steps as $step)
                            <div class="bg-surface">
                                <div class="h-14" style="background: var(--color-{{ $scale }}-{{ $step }})"></div>
                                <div class="px-2 py-1.5">
                                    <p class="num text-[15px] font-bold text-ink">{{ $step }}</p>
                                    <p class="truncate text-[11px] text-ink-muted">{{ $brandNotes[$scale.'-'.$step] ?? '' }}&nbsp;</p>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
            @endforeach

            <div>
                <p class="label-caps mb-3 text-ink-muted">Semantic tokens (switch to dark mode to see them flip)</p>
                <div class="grid grid-cols-2 gap-px bg-line sm:grid-cols-4 lg:grid-cols-8">
                    @foreach ($semantic as [$token, $note])
                        <div class="bg-surface p-3">
                            <div class="h-10 border border-line" style="background: var(--color-{{ $token }})"></div>
                            <p class="mt-2 font-mono text-xs font-semibold text-ink">{{ $token }}</p>
                            <p class="text-[11px] text-ink-muted">{{ $note }}</p>
                        </div>
                    @endforeach
                </div>
            </div>
        </div>
    </x-ui.section>

    {{-- ================================================================================= TYPE --}}
    <x-ui.section id="type" kicker="Tokens" title="Typography" intro="Inter for UI and body copy, Source Serif 4 for navy headings, Barlow Condensed for scores, ratings and big sporty moments.">
        <div class="grid gap-10 lg:grid-cols-2">
            <div class="space-y-6">
                <div><p class="label-caps text-ink-muted">text-display · serif</p><p class="font-serif text-display text-heading">Smash it forward</p></div>
                <div><p class="label-caps text-ink-muted">text-h1 · page title</p><p class="font-serif text-h1 text-heading">Halloween Smash 2026</p></div>
                <div><p class="label-caps text-ink-muted">text-h2 · section title</p><p class="font-serif text-h2 text-heading">Upcoming club nights</p></div>
                <div><p class="label-caps text-ink-muted">text-h3 · card title</p><p class="font-serif text-h3 text-heading">Winter Doubles Showdown</p></div>
                <div><p class="label-caps text-ink-muted">text-h4 / h5</p><p class="font-serif text-h4 text-heading">Quarter-final · Court 2</p><p class="font-serif text-h5 text-heading">Thursday social night</p></div>
            </div>
            <div class="space-y-6">
                <div><p class="label-caps text-ink-muted">kicker</p><p class="kicker">Season 2026–27 · Singles</p></div>
                <div><p class="label-caps text-ink-muted">text-intro</p><p class="text-intro text-ink-soft">Ratings move after every rated match. Beat a stronger player and you climb faster; lose to a newcomer and it stings a little more.</p></div>
                <div><p class="label-caps text-ink-muted">body · 16px Inter</p><p class="text-ink-soft">Club nights run on Tuesday from 19:30 and Thursday from 20:00 in the BUas Sports Hall. Bring indoor shoes; we bring the nets, shuttles and a ladder board for anyone who wants a ranked game.</p></div>
                <div class="flex flex-wrap items-end gap-8">
                    <div><p class="label-caps text-ink-muted">num · Barlow 800</p><p class="num text-6xl font-extrabold text-heading">1742</p></div>
                    <div><p class="label-caps text-ink-muted">score</p><p class="num text-6xl font-extrabold text-ink">21–17</p></div>
                    <div><p class="label-caps text-ink-muted">italic 800</p><p class="font-display text-5xl font-extrabold italic uppercase text-smash-500">Upset!</p></div>
                </div>
                <div><p class="label-caps text-ink-muted">label-caps · link</p><p class="label-caps text-ink-soft">Win rate · Peak rating</p><p class="mt-2 text-ink-soft">Read <a href="#type" class="link">how the ratings work</a> before your first ladder match.</p></div>
            </div>
        </div>
    </x-ui.section>

    {{-- ================================================================================= BUTTONS --}}
    <x-ui.section id="buttons" tone="grey" kicker="x-ui.button" title="Buttons" intro="Square, uppercase and letter-spaced like buas.nl. Green CTA with the sliding long arrow for the one action that matters; navy for primary actions; pills for navigation affordances.">
        <div class="grid gap-8">
            <div class="flex flex-wrap items-center gap-3">
                <x-ui.button variant="cta">Register for the tournament</x-ui.button>
                <x-ui.button variant="primary" icon="plus">New match</x-ui.button>
                <x-ui.button variant="outline">View bracket</x-ui.button>
                <x-ui.button variant="ghost" icon="pencil-square">Edit</x-ui.button>
                <x-ui.button variant="danger" icon="trash">Delete match</x-ui.button>
                <x-ui.button variant="danger-outline">Withdraw</x-ui.button>
            </div>
            <div class="flex flex-wrap items-center gap-3">
                <x-ui.button variant="cta" size="lg">Join SMASH</x-ui.button>
                <x-ui.button variant="cta" size="md">Join SMASH</x-ui.button>
                <x-ui.button variant="cta" size="sm">Join SMASH</x-ui.button>
                <x-ui.button variant="primary" size="sm" icon="arrow-down-tray">Export CSV</x-ui.button>
                <x-ui.button variant="primary" square icon="ellipsis-horizontal" aria-label="More actions" />
                <x-ui.button variant="outline" disabled>Registration closed</x-ui.button>
            </div>
            <div class="flex flex-wrap items-center gap-3">
                <x-ui.button variant="pill" icon-trailing="magnifying-glass">Search</x-ui.button>
                <x-ui.button variant="pill-navy" icon-trailing="bars-3">Menu</x-ui.button>
                <x-ui.button variant="pill-green" icon-trailing="arrow-right">Join SMASH</x-ui.button>
                <x-ui.button variant="pill-outline" icon="funnel">Filters</x-ui.button>
                <x-ui.button variant="pill" size="sm">Singles</x-ui.button>
                <x-ui.button variant="link">All tournaments</x-ui.button>
            </div>
            <div class="flex flex-wrap items-center gap-4">
                <x-ui.button variant="cta" wire:click="saveDemo">Save match</x-ui.button>
                <p class="text-sm text-ink-muted">Loading state: Livewire marks the clicked button <code class="font-mono text-xs">data-loading</code>; the label swaps for a spinner automatically.</p>
            </div>
            <div class="theme-navy flex flex-wrap items-center gap-3 p-6">
                <x-ui.button variant="pill-orange" icon-trailing="arrow-right" size="lg">Your dashboard</x-ui.button>
                <x-ui.button variant="cta">Log a match</x-ui.button>
                <x-ui.link href="#buttons" variant="promo">How ratings work</x-ui.link>
            </div>
        </div>
    </x-ui.section>

    {{-- ================================================================================= TAGS --}}
    <x-ui.section id="tags" kicker="x-ui.tag · x-ui.live" title="Tags and status" intro="Outlined uppercase chips, like the programme filters on buas.nl. Tones match the enums' tone() helpers.">
        <div class="grid gap-6">
            @foreach (['outline', 'soft', 'solid'] as $variant)
                <div class="flex flex-wrap items-center gap-2">
                    <span class="label-caps w-20 text-ink-muted">{{ $variant }}</span>
                    @foreach ($tones as $tone)
                        <x-ui.tag :tone="$tone" :variant="$variant">{{ $tone }}</x-ui.tag>
                    @endforeach
                </div>
            @endforeach
            <div class="flex flex-wrap items-center gap-2">
                <span class="label-caps w-20 text-ink-muted">status</span>
                <x-ui.live />
                <x-ui.live variant="solid" />
                <x-ui.tag tone="navy" dot>Scheduled</x-ui.tag>
                <x-ui.tag tone="green" variant="soft" icon="check">Completed</x-ui.tag>
                <x-ui.tag tone="orange">Walkover</x-ui.tag>
                <x-ui.tag tone="grey">Cancelled</x-ui.tag>
                <x-ui.tag tone="sky" size="sm">Doubles</x-ui.tag>
                <x-ui.tag tone="smash" variant="solid" icon="bolt">Upset</x-ui.tag>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                <span class="label-caps w-20 text-ink-muted">filters</span>
                <x-ui.tag size="lg" href="#tags" active>All programmes</x-ui.tag>
                <x-ui.tag size="lg" href="#tags">Built Environment</x-ui.tag>
                <x-ui.tag size="lg" href="#tags">Data Science &amp; AI</x-ui.tag>
                <x-ui.tag size="lg" href="#tags">Games</x-ui.tag>
                <x-ui.tag size="lg" href="#tags">Leisure &amp; Events</x-ui.tag>
            </div>
        </div>
    </x-ui.section>

    {{-- ================================================================================= CARDS --}}
    <x-ui.section id="cards" tone="grey" kicker="x-ui.card" title="Cards" intro="Square corners. Either a hairline, the BUas card shadow or a 4px coloured top bar. Pass href to make the whole card a link.">
        <x-slot:actions>
            <x-ui.link href="#cards">All tournaments</x-ui.link>
        </x-slot:actions>

        <div class="grid gap-6 md:grid-cols-2 xl:grid-cols-4">
            <x-ui.card highlight="orange" href="#cards">
                <x-ui.kicker>Singles · Knockout</x-ui.kicker>
                <p class="mt-2 font-serif text-h4 text-heading group-hover/card:underline">Halloween Smash</p>
                <div class="mt-3 space-y-1.5">
                    <x-ui.meta icon="calendar-days">Quarter-finals Sat 7 Nov</x-ui.meta>
                    <x-ui.meta icon="user-group">24 players · 8 left</x-ui.meta>
                </div>
                <x-ui.progress class="mt-5" :value="16" :max="23" label="Matches played" />
                <x-ui.link class="mt-6">View bracket</x-ui.link>
            </x-ui.card>

            <x-ui.card highlight="sky" href="#cards">
                <x-ui.kicker>Doubles · Knockout</x-ui.kicker>
                <p class="mt-2 font-serif text-h4 text-heading group-hover/card:underline">Winter Doubles Showdown</p>
                <div class="mt-3 space-y-1.5">
                    <x-ui.meta icon="calendar-days">Sat 12 Dec · 10:00</x-ui.meta>
                    <x-ui.meta icon="user-group">9 of 16 pairs registered</x-ui.meta>
                </div>
                <div class="mt-5 flex gap-2"><x-ui.tag tone="green" dot>Registration open</x-ui.tag></div>
                <x-ui.link class="mt-6">Find a partner</x-ui.link>
            </x-ui.card>

            <x-ui.card variant="shadow">
                <p class="kicker">Biggest upset this season</p>
                <x-ui.versus winner="b" class="mt-4">
                    <x-slot:a><span class="text-end"><span class="block font-semibold text-ink">Wei Chen</span><span class="num text-sm text-ink-muted">1671</span></span></x-slot:a>
                    <x-slot:center><span class="num text-2xl font-extrabold text-ink">1–2</span></x-slot:center>
                    <x-slot:b><span><span class="block font-semibold text-ink">Priya Raman</span><span class="num text-sm text-ink-muted">1488</span></span></x-slot:b>
                </x-ui.versus>
                <x-ui.game-scores class="mt-4 justify-center self-center" :games="[[21, 18], [17, 21], [19, 21]]" size="sm" />
                <p class="mt-4 text-center text-sm text-ink-soft">A 23% chance, according to the predictor.</p>
            </x-ui.card>

            <x-ui.card variant="navy">
                <p class="kicker">Pick'em</p>
                <p class="mt-2 font-serif text-h4">Who wins the final?</p>
                <x-ui.probability class="mt-5" :a="0.64" label-a="Kowalczyk" label-b="Jansen" size="sm" />
                <x-ui.button variant="pill-orange" size="sm" class="mt-6 self-start" icon-trailing="arrow-right">Make your pick</x-ui.button>
            </x-ui.card>
        </div>

        <div class="mt-6 grid gap-6 md:grid-cols-3">
            @foreach (['green' => 'Training night', 'navy' => 'Ladder night', 'grey' => 'Beginners clinic'] as $tone => $title)
                <x-ui.card :highlight="$tone" padding="md">
                    <div class="flex gap-4">
                        <x-ui.date-badge date="2026-11-{{ 10 + $loop->index * 2 }} 19:30" size="sm" />
                        <div>
                            <p class="font-serif text-h5 text-heading">{{ $title }}</p>
                            <x-ui.meta icon="clock" class="mt-1 text-sm">19:30 – 22:00</x-ui.meta>
                        </div>
                    </div>
                </x-ui.card>
            @endforeach
        </div>
    </x-ui.section>

    {{-- ================================================================================= STATS --}}
    <x-ui.section id="stats" kicker="x-ui.stat · rating · delta · rank · movement · form-guide" title="Numbers" intro="Barlow Condensed with tabular figures for everything you would put on a scoreboard.">
        <div class="grid grid-cols-2 gap-px bg-line md:grid-cols-4">
            <x-ui.stat class="bg-surface p-6" value="1742" label="Singles rating" :delta="18.4" sublabel="Peak 1760 · #1 of 58" />
            <x-ui.stat class="bg-surface p-6" value="81" suffix="%" label="Win rate" sublabel="25 wins, 6 losses" />
            <x-ui.stat class="bg-surface p-6" value="7" label="Win streak" tone="smash" sublabel="Longest this season" />
            <x-ui.stat class="bg-surface p-6" value="186" label="Matches played" sublabel="Season 2026–27" />
        </div>

        <div class="mt-8 flex flex-wrap items-center gap-x-10 gap-y-6">
            <div class="flex items-center gap-3"><x-ui.rank :position="1" /><x-ui.rank :position="2" /><x-ui.rank :position="3" /><x-ui.rank :position="14" /></div>
            <div class="flex items-center gap-4"><x-ui.rating :value="1609.4" size="lg" label="Singles" /><x-ui.rating :value="1532" provisional size="lg" label="Doubles" /></div>
            <div class="flex items-center gap-4"><x-ui.delta :value="12.6" /><x-ui.delta :value="-8.2" /><x-ui.delta :value="0" /><x-ui.delta :value="31" size="lg" /></div>
            <div class="flex items-center gap-4"><x-ui.movement :value="3" /><x-ui.movement :value="-2" /><x-ui.movement :value="0" /><x-ui.movement :value="null" /></div>
            <x-ui.form-guide :results="['W', 'W', 'L', 'W', 'L']" />
            <x-ui.form-guide :results="['L', 'W']" size="sm" />
        </div>

        <div class="mt-10 grid gap-6 lg:grid-cols-[minmax(0,2fr)_minmax(0,1fr)]">
            <x-ui.card padding="lg">
                <div class="flex flex-wrap items-end justify-between gap-4">
                    <div>
                        <p class="kicker">x-ui.line-chart</p>
                        <p class="mt-1 font-serif text-h4 text-heading">Singles rating this season</p>
                    </div>
                    <x-ui.delta :value="collect($this->history)->sum('change')" size="lg" suffix="since September" />
                </div>
                <x-ui.line-chart class="mt-6" :points="$this->history" label="Singles rating" :baseline="1500" />
            </x-ui.card>
            <x-ui.card padding="lg" class="justify-between gap-6">
                <x-ui.stat :value="(int) round(collect($this->history)->last()['rating'])" label="Current rating" :delta="collect($this->history)->last()['change']" />
                <x-ui.stat :value="(int) round(collect($this->history)->max('rating'))" label="Season peak" size="sm" />
                <x-ui.line-chart :points="[]" label="Doubles rating" :table="false" />
            </x-ui.card>
        </div>
    </x-ui.section>

    {{-- ================================================================================= TABLES --}}
    <x-ui.section id="tables" tone="grey" kicker="x-ui.table · x-ui.tabs · x-ui.player" title="Tables" intro="Real data density, like a federation ranking list. The wrapper scrolls horizontally on phones and works with a plain table or flux:table.">
        <x-ui.tabs label="Discipline" class="mb-6">
            <x-ui.tabs.item wire:click="$set('discipline', 'singles')" :current="$discipline === 'singles'" :count="58">Singles</x-ui.tabs.item>
            <x-ui.tabs.item wire:click="$set('discipline', 'doubles')" :current="$discipline === 'doubles'" :count="44">Doubles</x-ui.tabs.item>
            <x-ui.tabs.item href="#tables">Head-to-head</x-ui.tabs.item>
        </x-ui.tabs>

        <x-ui.table>
            <x-slot:header>
                <p class="font-serif text-h4 text-heading">{{ ucfirst($discipline) }} ranking</p>
                <x-ui.tabs variant="pills" label="Period">
                    <x-ui.tabs.item current>Season</x-ui.tabs.item>
                    <x-ui.tabs.item>30 days</x-ui.tabs.item>
                </x-ui.tabs>
            </x-slot:header>

            <table>
                <thead>
                    <tr>
                        <th class="w-16">#</th>
                        <th>Player</th>
                        <th class="text-end">Rating</th>
                        <th class="text-end">30 days</th>
                        <th class="text-end max-sm:hidden">W–L</th>
                        <th class="max-md:hidden">Form</th>
                        <th class="w-14 text-end">Move</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($this->rankings as $row)
                        <tr @if ($row['pos'] === 5) data-current @endif>
                            <td><x-ui.rank :position="$row['pos']" size="sm" /></td>
                            <td><x-ui.player :name="$row['name']" :meta="$row['programme']" href="#tables" size="sm" /></td>
                            <td class="text-end"><x-ui.rating :value="$row['rating']" :provisional="$row['provisional']" size="sm" /></td>
                            <td class="text-end"><x-ui.delta :value="$row['change']" size="sm" /></td>
                            <td class="num text-end text-[17px] font-semibold text-ink max-sm:hidden">{{ $row['wins'] }}–{{ $row['played'] - $row['wins'] }}</td>
                            <td class="max-md:hidden"><x-ui.form-guide :results="$row['form']" size="sm" /></td>
                            <td class="text-end"><x-ui.movement :value="$row['move']" /></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>

            <x-slot:footer>
                <flux:pagination :paginator="$this->paginator" />
            </x-slot:footer>
        </x-ui.table>

        <div class="mt-8 grid gap-6 lg:grid-cols-2">
            <x-ui.table dense>
                <flux:table>
                    <flux:table.columns>
                        <flux:table.column>Match</flux:table.column>
                        <flux:table.column>Score</flux:table.column>
                        <flux:table.column align="end">Played</flux:table.column>
                    </flux:table.columns>
                    <flux:table.rows>
                        <flux:table.row>
                            <flux:table.cell variant="strong">Jansen / Peeters v Chen / Romano</flux:table.cell>
                            <flux:table.cell><x-ui.game-scores :games="[[21, 15], [21, 19]]" size="sm" /></flux:table.cell>
                            <flux:table.cell align="end">Tue 3 Nov</flux:table.cell>
                        </flux:table.row>
                        <flux:table.row>
                            <flux:table.cell variant="strong">De Vries v Yılmaz</flux:table.cell>
                            <flux:table.cell><x-ui.game-scores :games="[[18, 21], [21, 16], [23, 21]]" size="sm" /></flux:table.cell>
                            <flux:table.cell align="end">Thu 29 Oct</flux:table.cell>
                        </flux:table.row>
                    </flux:table.rows>
                </flux:table>
            </x-ui.table>

            <x-ui.card padding="lg">
                <p class="kicker">Match result</p>
                <x-ui.versus winner="a" class="mt-4">
                    <x-slot:a><x-ui.player name="Lotte Jansen" meta="Games · 1698" href="#tables" class="flex-row-reverse text-end" /></x-slot:a>
                    <x-slot:center><span class="num text-4xl font-extrabold text-ink">2–1</span><span class="label-caps mt-1 text-ink-muted">Final</span></x-slot:center>
                    <x-slot:b><x-ui.player name="Mateusz Kowalczyk" meta="Logistics · 1742" href="#tables" /></x-slot:b>
                </x-ui.versus>
                <div class="mt-6 flex justify-center"><x-ui.game-scores layout="boxes" :games="[[21, 19], [14, 21], [21, 17]]" /></div>
            </x-ui.card>
        </div>
    </x-ui.section>

    {{-- ================================================================================= PEOPLE / META --}}
    <x-ui.section id="people" kicker="x-ui.avatar · player · meta · facts · step · link" title="People and details">
        <div class="grid gap-10 lg:grid-cols-3">
            <div class="space-y-5">
                <div class="flex items-end gap-3">
                    <x-ui.avatar name="Ayşe Yılmaz" size="xl" />
                    <x-ui.avatar name="Wei Chen" size="lg" />
                    <x-ui.avatar name="Sam de Vries" size="md" />
                    <x-ui.avatar name="Lucas Peeters" size="sm" />
                    <x-ui.avatar name="Giulia Romano" size="xs" />
                </div>
                <x-ui.player name="Priya Raman" meta="Hotel · Intermediate" size="lg" href="#people" />
                <x-ui.player name="Mateusz Kowalczyk" meta="Logistics · Competitive" href="#people" />
            </div>

            <x-ui.facts :items="['Format' => 'Single elimination', 'Discipline' => 'Singles', 'Best of' => '3 games to 21', 'Entries' => '24 players, 8 byes', 'Venue' => 'BUas Sports Hall', 'Rated' => 'Yes · K × 1.25']" />

            <div class="space-y-6">
                <x-ui.step number="1" title="Play a rated match">Friendlies at club nights count, as long as both sides agree on the score.</x-ui.step>
                <x-ui.step number="2" title="Ratings move">Beating a stronger player earns more points than beating a newcomer.</x-ui.step>
                <div class="flex flex-wrap gap-x-6 gap-y-2 pt-2">
                    <x-ui.link href="#people">All players</x-ui.link>
                    <x-ui.link href="#people" variant="promo">Discover the club</x-ui.link>
                    <x-ui.link href="#people" variant="back">Rankings</x-ui.link>
                </div>
            </div>
        </div>
    </x-ui.section>

    {{-- ================================================================================= FEEDBACK --}}
    <x-ui.section id="feedback" tone="grey" kicker="x-ui.alert · x-ui.empty · x-ui.progress" title="Feedback and empty states">
        <div class="grid gap-6 lg:grid-cols-2">
            <div class="space-y-3">
                <x-ui.alert tone="info" title="Ratings are recalculating">Results from tonight will show in the rankings in a minute.</x-ui.alert>
                <x-ui.alert tone="success" title="You're on the list">See you on Thursday at 20:00. We'll hold your spot until 20:15.</x-ui.alert>
                <x-ui.alert tone="warning" title="Waitlist" dismissible>The Tuesday training night is full. You're number 3 on the waitlist.</x-ui.alert>
                <x-ui.alert tone="danger" title="Score doesn't add up">Game 2 must end at 21 with a two-point lead, or at 30.</x-ui.alert>
            </div>
            <div class="space-y-6">
                <x-ui.empty title="No matches yet">
                    Play a friendly at the next club night and log it here to get your first rating.
                    <x-slot:actions><x-ui.button variant="cta" size="sm">Log a match</x-ui.button></x-slot:actions>
                </x-ui.empty>
                <x-ui.card class="gap-5">
                    <x-ui.progress :value="12" :max="24" capacity label="Tuesday training" />
                    <x-ui.progress :value="21" :max="24" capacity label="Thursday social" />
                    <x-ui.progress :value="24" :max="24" capacity label="Sunday clinic" value-label="Full · 3 on waitlist" />
                    <x-ui.progress :value="0.64" :max="1" tone="green" size="lg" label="Tournament progress" value-label="64%" />
                </x-ui.card>
            </div>
        </div>
    </x-ui.section>

    {{-- ================================================================================= FORMS --}}
    <x-ui.section id="forms" kicker="x-ui.form-section · Flux inputs" title="Forms" intro="Flux inputs inherit the theme: BUas uppercase labels, square-ish corners, navy focus outline.">
        <form wire:submit="saveDemo" class="max-w-5xl">
            <x-ui.form-section title="Match details" description="Friendlies you played at a club night. Both players must be SMASH members.">
                <div class="grid gap-6 sm:grid-cols-2">
                    <flux:select label="Discipline" wire:model="discipline">
                        <option value="singles">Singles</option>
                        <option value="doubles">Doubles</option>
                    </flux:select>
                    <flux:input label="Played on" type="date" value="2026-11-03" />
                </div>
                <flux:input label="Venue" placeholder="BUas Sports Hall" description="Leave empty for the usual hall." />
                <flux:textarea label="Notes" rows="3" placeholder="Anything worth remembering about this match?" />
            </x-ui.form-section>

            <x-ui.form-section title="Preferences" description="How we keep you in the loop.">
                <flux:radio.group label="Level" variant="segmented" wire:model="level">
                    <flux:radio value="beginner" label="Beginner" />
                    <flux:radio value="intermediate" label="Intermediate" />
                    <flux:radio value="advanced" label="Advanced" />
                </flux:radio.group>
                <flux:switch label="Email me when a club night opens" wire:model="notifications" />
                <flux:checkbox label="Count this match towards the rankings" checked />
                <flux:input label="Email" type="email" value="sam.devries@smash.test" invalid />
                <flux:error message="This email is already used by another member." />
            </x-ui.form-section>

            <x-ui.form-actions>
                <x-slot:secondary>
                    <x-ui.button variant="danger-outline" size="sm" icon="trash">Delete</x-ui.button>
                </x-slot:secondary>
                <x-ui.button variant="ghost">Cancel</x-ui.button>
                <x-ui.button variant="cta" type="submit">Save match</x-ui.button>
            </x-ui.form-actions>
        </form>
    </x-ui.section>

    {{-- ================================================================================= NAVY --}}
    <x-ui.section tone="navy" kicker="theme-navy" title="Navy band" intro="Any section can turn navy; headings, kickers, links and lines adapt through the semantic tokens.">
        <x-slot:actions>
            <x-ui.button variant="pill-orange" icon-trailing="arrow-right">Join the club</x-ui.button>
        </x-slot:actions>
        <div class="grid grid-cols-2 gap-6 md:grid-cols-4">
            <x-ui.stat value="64" label="Members" />
            <x-ui.stat value="186" label="Matches" />
            <x-ui.stat value="5" label="Tournaments" />
            <x-ui.stat value="12,480" label="Points played" />
        </div>
    </x-ui.section>

    {{-- ================================================================================= HEROES --}}
    <x-ui.section id="heroes" kicker="x-ui.page-hero" title="Page hero variants" intro="Grey band (default), white or navy; optional court lines when there is no aside card; size lg for landing moments." spacing="sm" />

    <x-ui.page-hero
        :breadcrumbs="[['Tournaments', '#heroes'], 'Halloween Smash']"
        kicker="Singles · Knockout · In progress"
        title="Halloween Smash"
        intro="24 players, one trophy. The quarter-finals are on Saturday 7 November from 10:00 in the BUas Sports Hall."
        court
    >
        <x-slot:meta>
            <x-ui.live />
            <x-ui.tag tone="orange">Quarter-finals</x-ui.tag>
            <x-ui.tag tone="grey">Best of 3 · to 21</x-ui.tag>
        </x-slot:meta>
        <x-slot:actions>
            <x-ui.button variant="cta">Predict the quarter-finals</x-ui.button>
            <x-ui.button variant="outline">Bracket</x-ui.button>
        </x-slot:actions>
    </x-ui.page-hero>

    <x-ui.page-hero tone="navy" size="lg" kicker="BUas Badminton Club" title="Smash it forward." intro="Club nights twice a week, ratings that move with every match and tournaments for every level." court>
        <x-slot:actions>
            <x-ui.button variant="pill-orange" size="lg" icon-trailing="arrow-right">Join the club</x-ui.button>
            <x-ui.link variant="promo">How ratings work</x-ui.link>
        </x-slot:actions>
    </x-ui.page-hero>

    {{-- ================================================================================= FLUX --}}
    <x-ui.section id="flux" tone="grey" kicker="Flux in the theme" title="Flux components" intro="Flux keeps working everywhere: accent is navy, zinc is BUas grey and corners are square-ish.">
        <div class="flex flex-wrap items-center gap-3">
            <flux:button variant="primary">Flux primary</flux:button>
            <flux:button>Flux outline</flux:button>
            <flux:button variant="ghost" icon="cog-6-tooth">Settings</flux:button>
            <flux:badge color="lime">Badge</flux:badge>
            <flux:badge>Neutral</flux:badge>
            <flux:tooltip content="Ratings update after every match">
                <flux:button icon="information-circle" variant="subtle" square />
            </flux:tooltip>
            <flux:dropdown>
                <flux:button icon-trailing="chevron-down">Dropdown</flux:button>
                <flux:menu>
                    <flux:menu.item icon="pencil-square">Edit match</flux:menu.item>
                    <flux:menu.item icon="arrow-path">Recalculate</flux:menu.item>
                    <flux:menu.separator />
                    <flux:menu.item icon="trash" variant="danger">Delete</flux:menu.item>
                </flux:menu>
            </flux:dropdown>
            <flux:modal.trigger name="styleguide-modal">
                <x-ui.button variant="outline" size="sm">Open modal</x-ui.button>
            </flux:modal.trigger>
        </div>
        <flux:callout icon="megaphone" class="mt-6" heading="Flux callout">Use x-ui.alert for page feedback; callouts are fine inside forms.</flux:callout>

        <flux:modal name="styleguide-modal" class="md:w-[28rem]">
            <div class="space-y-6">
                <div>
                    <flux:heading size="lg">Delete this match?</flux:heading>
                    <flux:text class="mt-2">Ratings for all four players will be recalculated. This cannot be undone.</flux:text>
                </div>
                <div class="flex justify-end gap-2">
                    <flux:modal.close><x-ui.button variant="ghost" size="sm">Cancel</x-ui.button></flux:modal.close>
                    <x-ui.button variant="danger" size="sm">Delete match</x-ui.button>
                </div>
            </div>
        </flux:modal>
    </x-ui.section>

    {{-- ================================================================================= DOMAIN COMPONENTS (x-smash.*) --}}
    @php
        $p = $this->demoPlayers;
        $m = $this->demoMatches;
        $e = $this->demoEntries;
        $lotteMatches = collect([$m['tournament'], $m['doubles'], $this->demoBracket[0]['matches'][3], $this->demoFinishedBracket[1]['matches'][0], $this->demoFinishedBracket[0]['matches'][1]]);
    @endphp

    <x-ui.section id="domain" tone="navy" kicker="x-smash.*" title="Domain components" intro="Badminton building blocks on top of the primitives above. They take models (User, BadmintonMatch, TournamentEntry …) and know the rules: sides, games, pre-match ratings, upsets, byes. Everything on this page is built from unsaved sample models." class="relative isolate overflow-hidden">
        <x-smash.court-pattern class="absolute top-1/2 right-[-6rem] -z-10 h-[150%] w-auto -translate-y-1/2 text-white max-lg:hidden" />
        <nav class="flex max-w-3xl flex-wrap gap-x-7 gap-y-3" aria-label="Domain components">
            @foreach (['smash-people' => 'Players', 'smash-numbers' => 'Numbers & status', 'smash-matches' => 'Match cards', 'smash-predictions' => 'Win probability', 'smash-charts' => 'Charts', 'smash-tournaments' => 'Tournaments', 'smash-decoration' => 'Court & shuttle'] as $anchor => $text)
                <x-ui.link variant="promo" :href="'#'.$anchor" :navigate="false">{{ $text }}</x-ui.link>
            @endforeach
        </nav>
    </x-ui.section>

    {{-- ---------------------------------------------------------------- Players --}}
    <x-ui.section id="smash-people" kicker="x-smash.avatar · x-smash.player" title="Players" intro="Initials in a stable BUas tone per name, the same as the header avatar (grey when inactive). Rings mark committee members and the number one. A doubles pair shares one line, with overlapping avatars.">
        <div class="grid gap-6 lg:grid-cols-3">
            <x-ui.card padding="lg" class="gap-6">
                <div>
                    <p class="label-caps text-ink-muted">Sizes xl · lg · md · sm · xs</p>
                    <div class="mt-4 flex flex-wrap items-end gap-3">
                        <x-smash.avatar :user="$p['ayse']" size="xl" />
                        <x-smash.avatar :user="$p['wei']" size="lg" />
                        <x-smash.avatar :user="$p['sam']" />
                        <x-smash.avatar :user="$p['lucas']" size="sm" />
                        <x-smash.avatar :user="$p['giulia']" size="xs" />
                    </div>
                </div>
                <div>
                    <p class="label-caps text-ink-muted">ring="auto" · square · placeholder</p>
                    <div class="mt-4 flex flex-wrap items-center gap-4">
                        <x-smash.avatar :user="$p['mateusz']" size="lg" ring="auto" :rank="1" />
                        <x-smash.avatar :user="$p['lotte']" size="lg" ring="auto" />
                        <x-smash.avatar :user="$p['daan']" size="lg" square />
                        <x-smash.avatar placeholder size="lg" />
                    </div>
                    <p class="mt-3 text-sm text-ink-muted">#1 gets the SMASH red ring, committee members a navy one.</p>
                </div>
            </x-ui.card>

            <x-ui.card padding="lg" class="gap-5">
                <p class="label-caps text-ink-muted">Default · with rating</p>
                <x-smash.player :user="$p['lotte']" rating ring="auto" />
                <x-smash.player :user="$p['priya']" rating />
                <x-smash.player :users="[$p['sam'], $p['lucas']]" rating />
                <x-smash.player :user="$p['wei']" size="lg" :rating="1659.6" meta="Data Science & AI · Advanced" />
                <x-smash.player name="TBD" />
            </x-ui.card>

            <x-ui.card padding="lg" class="gap-5">
                <p class="label-caps text-ink-muted">Compact · winner / loser · mirrored</p>
                <x-smash.player :user="$p['mateusz']" variant="compact" rating :winner="true" />
                <x-smash.player :user="$p['ayse']" variant="compact" rating :winner="false" />
                <x-smash.player :users="[$p['elena'], $p['arjun']]" variant="compact" short rating />
                <x-smash.player :user="$p['giulia']" align="end" rating />
                <div class="mt-2 flex items-start justify-around gap-4 border-t border-line pt-5">
                    <x-smash.player :user="$p['lotte']" variant="stacked" rating />
                    <x-smash.player :users="[$p['sam'], $p['lucas']]" variant="stacked" short />
                </div>
            </x-ui.card>
        </div>
    </x-ui.section>

    {{-- ---------------------------------------------------------------- Numbers & status --}}
    <x-ui.section id="smash-numbers" tone="grey" kicker="rating · rating-delta · rank · form · record · badges · stat-row" title="Numbers and status">
        <div class="grid gap-6 lg:grid-cols-2">
            <x-ui.card padding="lg" class="gap-7">
                <div>
                    <p class="label-caps text-ink-muted">x-smash.rating · xs → xl, provisional, unrated, with delta</p>
                    <div class="mt-4 flex flex-wrap items-end gap-x-6 gap-y-4">
                        <x-smash.rating :user="$p['mateusz']" size="xl" label="Singles" />
                        <x-smash.rating :user="$p['priya']" size="lg" label="Singles" />
                        <x-smash.rating :user="$p['lotte']" discipline="doubles" label="Doubles" :delta="12.4" />
                        <x-smash.rating :value="1532" size="sm" />
                        <x-smash.rating :value="1532" size="xs" muted />
                        <x-smash.rating :value="null" label="Doubles" />
                    </div>
                </div>
                <div>
                    <p class="label-caps text-ink-muted">x-smash.rating-delta · text and chip</p>
                    <div class="mt-4 flex flex-wrap items-center gap-4">
                        <x-smash.rating-delta :value="12.4" />
                        <x-smash.rating-delta :value="-8.2" />
                        <x-smash.rating-delta :value="0" />
                        <x-smash.rating-delta :value="null" />
                        <x-smash.rating-delta :participant="$m['upset']->participants[0]" size="lg" />
                        <x-smash.rating-delta :value="31" variant="chip" />
                        <x-smash.rating-delta :value="-19" variant="chip" />
                        <x-smash.rating-delta :value="0" variant="chip" />
                    </div>
                </div>
                <div>
                    <p class="label-caps text-ink-muted">x-smash.rank · box with movement, plain with "of n"</p>
                    <div class="mt-4 flex flex-wrap items-end gap-x-8 gap-y-5">
                        <div class="flex flex-col gap-2">
                            <x-smash.rank :position="1" :movement="0" />
                            <x-smash.rank :position="2" :movement="2" />
                            <x-smash.rank :position="14" :movement="-3" />
                            <x-smash.rank :position="22" :movement="null" />
                        </div>
                        <x-smash.rank variant="plain" :position="1" size="xl" :of="58" label="Singles rank" />
                        <x-smash.rank variant="plain" :position="7" size="lg" :movement="3" label="Doubles rank" />
                        <x-smash.rank variant="plain" :position="null" size="lg" label="Not ranked yet" />
                    </div>
                </div>
            </x-ui.card>

            <x-ui.card padding="lg" class="gap-7">
                <div>
                    <p class="label-caps text-ink-muted">x-smash.form · results, or matches + for</p>
                    <div class="mt-4 flex flex-col items-start gap-3">
                        <x-smash.form :results="['W', 'W', 'L', 'W', 'L']" label="Form" />
                        <x-smash.form :matches="$lotteMatches" :for="$p['lotte']" label="Lotte" :links="false" />
                        <x-smash.form :results="['W']" size="sm" label="New" />
                    </div>
                </div>
                <div>
                    <p class="label-caps text-ink-muted">x-smash.record</p>
                    <div class="mt-4 grid grid-cols-3 items-end gap-6">
                        <x-smash.record :user="$p['lotte']" label="Singles" size="lg" />
                        <x-smash.record :user="$p['sam']" discipline="doubles" label="Doubles" bar />
                        <x-smash.record :wins="1" :losses="2" size="sm" :label="false" bar />
                    </div>
                </div>
                <div>
                    <p class="label-caps text-ink-muted">x-smash.discipline-badge · x-smash.status-badge</p>
                    <div class="mt-4 flex flex-wrap items-center gap-2">
                        <x-smash.discipline-badge discipline="singles" />
                        <x-smash.discipline-badge discipline="doubles" />
                        <x-smash.discipline-badge :discipline="\App\Enums\Discipline::Doubles" icon size="md" />
                    </div>
                    <div class="mt-3 flex flex-wrap items-center gap-2">
                        @foreach (\App\Enums\MatchStatus::cases() as $status)
                            <x-smash.status-badge :status="$status" />
                        @endforeach
                        <x-smash.status-badge :match="$m['bye']" />
                    </div>
                    <div class="mt-3 flex flex-wrap items-center gap-2">
                        @foreach (\App\Enums\TournamentStatus::cases() as $status)
                            <x-smash.status-badge :status="$status" size="sm" />
                        @endforeach
                        @foreach (\App\Enums\RsvpStatus::cases() as $status)
                            <x-smash.status-badge :status="$status" size="sm" variant="soft" />
                        @endforeach
                    </div>
                </div>
            </x-ui.card>
        </div>

        <p class="label-caps mt-10 mb-3 text-ink-muted">x-smash.stat-row</p>
        <x-smash.stat-row :items="[
            ['value' => $p['lotte']->rating(\App\Enums\Discipline::Singles), 'label' => 'Singles rating', 'delta' => 8.8, 'sublabel' => 'Peak '.$p['lotte']->peak(\App\Enums\Discipline::Singles)],
            ['value' => (int) round($p['lotte']->winRate(\App\Enums\Discipline::Singles) * 100), 'suffix' => '%', 'label' => 'Win rate', 'sublabel' => $p['lotte']->wins(\App\Enums\Discipline::Singles).' wins, '.$p['lotte']->losses(\App\Enums\Discipline::Singles).' losses'],
            ['value' => 5, 'label' => 'Win streak', 'tone' => 'smash', 'sublabel' => 'Longest: 7'],
            ['value' => '#2', 'label' => 'Singles rank', 'sublabel' => 'of 58 ranked players'],
        ]" />
    </x-ui.section>

    {{-- ---------------------------------------------------------------- Match cards --}}
    <x-ui.section id="smash-matches" kicker="x-smash.match-card · x-smash.games" title="Match cards" intro="One card for every state: results with games and rating changes, live scores, fixtures with the time or the predicted chances, walkovers, byes, open draw slots and cancellations. The whole card links to the match; names and the tournament stay separate links.">
        <div class="grid gap-6 md:grid-cols-2 xl:grid-cols-3">
            <x-smash.match-card :match="$m['tournament']" />
            <x-smash.match-card :match="$m['upset']" />
            <x-smash.match-card :match="$m['doubles']" />
            <x-smash.match-card :match="$m['live']" />
            <x-smash.match-card :match="$m['fixture']" />
            <x-smash.match-card :match="$m['challenge']" :probability="0.71" />
            <x-smash.match-card :match="$m['tbd']" />
            <x-smash.match-card :match="$m['walkover']" />
            <x-smash.match-card :match="$m['bye']" />
            <x-smash.match-card :match="$m['cancelled']" />
            <x-smash.match-card :match="$m['unrated']" />
            <x-smash.match-card :match="$m['fixture']" :probability="0.64" />
        </div>

        <div class="mt-10 grid gap-6 lg:grid-cols-[minmax(0,1fr)_minmax(0,1fr)]">
            <div>
                <p class="label-caps mb-3 text-ink-muted">variant="compact" · for = Lotte Jansen (her side highlighted, Won / Lost)</p>
                <div class="grid gap-3">
                    <x-smash.match-card :match="$m['tournament']" variant="compact" :for="$p['lotte']" />
                    <x-smash.match-card :match="$m['doubles']" variant="compact" :for="$p['lotte']" />
                    <x-smash.match-card :match="$m['live']" variant="compact" />
                    <x-smash.match-card :match="$m['tbd']" variant="compact" :for="$p['lotte']" />
                </div>
            </div>
            <x-ui.card padding="lg" class="gap-5">
                <p class="label-caps text-ink-muted">x-smash.games</p>
                <dl class="grid grid-cols-[auto_minmax(0,1fr)] items-baseline gap-x-6 gap-y-4 text-sm">
                    <dt class="text-ink-muted">From a match</dt>
                    <dd><x-smash.games :match="$m['tournament']" /></dd>
                    <dt class="text-ink-muted">Side B's view</dt>
                    <dd><x-smash.games :match="$m['tournament']" side="b" /></dd>
                    <dt class="text-ink-muted">Live</dt>
                    <dd><x-smash.games :match="$m['live']" /></dd>
                    <dt class="text-ink-muted">Golden point</dt>
                    <dd><x-smash.games :games="[[29, 30], [30, 28], [21, 9]]" size="sm" /></dd>
                    <dt class="text-ink-muted">To 15, large</dt>
                    <dd><x-smash.games :games="[['a' => 15, 'b' => 12]]" :target="15" size="lg" /></dd>
                    <dt class="text-ink-muted">layout="boxes"</dt>
                    <dd><x-smash.games :match="$m['live']" layout="boxes" size="sm" /></dd>
                    <dt class="text-ink-muted">Not started</dt>
                    <dd><x-smash.games :match="$m['fixture']" empty="No games yet" /></dd>
                </dl>
            </x-ui.card>
        </div>
    </x-ui.section>

    {{-- ---------------------------------------------------------------- Win probability --}}
    <x-ui.section id="smash-predictions" tone="grey" kicker="x-smash.win-probability" title="Win probability" intro="Side A navy, side B orange, the favourite in full colour. Feed it a probability or a MatchPrediction from the predictor; the bands (toss-up, slight edge, favourite, strong favourite) follow the predictor's.">
        <div class="grid gap-6 lg:grid-cols-3">
            <x-ui.card padding="lg" class="gap-8">
                <x-smash.win-probability :a="0.64" :side-a="[$p['mateusz']]" :side-b="[$p['arjun']]" caption="Semi-final · Sat 7 Nov" />
                <x-smash.win-probability :a="0.38" :side-a="[$p['sam'], $p['lucas']]" :side-b="[$p['lotte'], $p['giulia']]" />
            </x-ui.card>

            <x-ui.card padding="lg" class="gap-4">
                <p class="label-caps text-ink-muted">variant="compact" · names</p>
                @foreach ([[$p['mateusz'], $p['arjun'], 0.64], [$p['elena'], $p['priya'], 0.71], [$p['wei'], $p['sam'], 0.52], [$p['daan'], $p['ayse'], 0.12]] as [$one, $two, $chance])
                    <div class="border-t border-line pt-4 first-of-type:border-t-0 first-of-type:pt-0">
                        <x-smash.win-probability variant="compact" names :a="$chance" :side-a="[$one]" :side-b="[$two]" />
                    </div>
                @endforeach
            </x-ui.card>

            <x-ui.card variant="navy" padding="lg">
                <p class="kicker">Pick'em</p>
                <p class="mt-2 font-serif text-h4">Who wins the semi-final?</p>
                <x-smash.win-probability class="mt-6" variant="large" :a="0.64" :side-a="[$p['mateusz']]" :side-b="[$p['arjun']]" />
                <x-ui.button variant="pill-orange" size="sm" class="mt-8 self-start" icon-trailing="arrow-right">Make your pick</x-ui.button>
            </x-ui.card>
        </div>
        <x-ui.card padding="lg" class="mt-6">
            <x-smash.win-probability class="mx-auto w-full max-w-3xl" variant="large" :a="0.52" :side-a="[$p['wei']]" :side-b="[$p['sam']]" caption="Who wins?" />
        </x-ui.card>
    </x-ui.section>

    {{-- ---------------------------------------------------------------- Charts --}}
    <x-ui.section id="smash-charts" kicker="x-smash.rating-chart · x-smash.sparkline" title="Rating charts" intro="Pure inline SVG: dates along the bottom, gridlines with labels, start, peak and current markers, and a hover or keyboard tooltip. Text stays crisp at any width; screen readers get a summary and every value.">
        <div class="grid items-start gap-6 lg:grid-cols-[minmax(0,2fr)_minmax(0,1fr)]">
            <x-ui.card padding="lg">
                <div class="flex flex-wrap items-end justify-between gap-4">
                    <div>
                        <p class="kicker">Lotte Jansen</p>
                        <p class="mt-1 font-serif text-h4 text-heading">Singles rating this season</p>
                    </div>
                    <div class="flex items-center gap-3">
                        <x-smash.sparkline :points="$this->demoHistory" :label="__('Season trend')" />
                        <x-smash.rating-delta :value="collect($this->demoHistory)->sum('change')" size="lg" />
                    </div>
                </div>
                <x-smash.rating-chart class="mt-6" :points="$this->demoHistory" label="Singles rating" :baseline="1500" :height="280" />
            </x-ui.card>

            <div class="grid gap-6">
                <x-ui.card padding="lg">
                    <p class="font-serif text-h5 text-heading">Newcomer · three matches</p>
                    <x-smash.rating-chart class="mt-4" :points="$this->newcomerHistory" label="Singles rating" :height="170" :baseline="1500" />
                </x-ui.card>
                <x-ui.card padding="lg">
                    <p class="font-serif text-h5 text-heading">Doubles rating</p>
                    <x-smash.rating-chart class="mt-4" :points="[]" label="Doubles rating" />
                </x-ui.card>
            </div>
        </div>

        <div class="mt-6 grid items-start gap-6 lg:grid-cols-2">
            <x-ui.card padding="lg">
                <p class="font-serif text-h5 text-heading">scale="index" · evenly spaced matches</p>
                <x-smash.rating-chart class="mt-4" :points="$this->demoHistory" scale="index" :height="200" label="Singles rating" :markers="false" />
            </x-ui.card>
            <x-ui.table>
                <table>
                    <thead><tr><th>Player</th><th class="text-end">Rating</th><th>Last 10</th><th class="text-end">30 days</th></tr></thead>
                    <tbody>
                        @foreach ([['lotte', [1640, 1652, 1645, 1660, 1671, 1668, 1680, 1689, 1694, 1698]], ['wei', [1690, 1684, 1676, 1680, 1671, 1675, 1668, 1678, 1671, 1660]], ['sam', [1609, 1612, 1605, 1609, 1611, 1607, 1609, 1610, 1608, 1609]], ['priya', [1500, 1478, 1497, 1512, 1488, 1519]]] as [$key, $series])
                            <tr>
                                <td><x-smash.player :user="$p[$key]" size="sm" :meta="false" /></td>
                                <td class="text-end"><x-smash.rating :user="$p[$key]" size="sm" /></td>
                                <td><x-smash.sparkline :values="$series" :width="96" :label="__('Last ten ratings')" /></td>
                                <td class="text-end"><x-smash.rating-delta :value="end($series) - $series[0]" size="sm" /></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </x-ui.table>
        </div>
    </x-ui.section>

    {{-- ---------------------------------------------------------------- Tournaments --}}
    <x-ui.section id="smash-tournaments" tone="grey" kicker="x-smash.entry · x-smash.bracket · bracket-match · bracket-slot" title="Tournaments" intro="Entries with seeds and entry ratings, and knockout draws that scroll sideways on phones. Feed x-smash.bracket the Bracket service's rounds.">
        <div class="grid gap-6 lg:grid-cols-2">
            <x-ui.card padding="none">
                <div class="flex items-baseline justify-between gap-4 border-b border-line px-5 py-4">
                    <p class="font-serif text-h5 text-heading">Winter Doubles Showdown</p>
                    <span class="label-caps text-ink-muted">4 of 16 pairs</span>
                </div>
                <ul class="divide-y divide-line">
                    @foreach (['pairLotteSam', 'pairLucasGiulia', 'pairElenaArjun', 'pairDaanHannah'] as $key)
                        <li><x-smash.entry :entry="$e[$key]" :for="$p['sam']" class="px-5 py-3" /></li>
                    @endforeach
                </ul>
            </x-ui.card>
            <x-ui.card padding="none">
                <div class="flex items-baseline justify-between gap-4 border-b border-line px-5 py-4">
                    <p class="font-serif text-h5 text-heading">Halloween Smash · seeds</p>
                    <span class="label-caps text-ink-muted">24 players</span>
                </div>
                <ul class="divide-y divide-line">
                    <li><x-smash.entry :entry="$e['mateusz']" class="px-5 py-3" :place="1" /></li>
                    <li><x-smash.entry :entry="$e['lotte']" class="px-5 py-3" :place="2" /></li>
                    <li><x-smash.entry :entry="$e['wei']" class="px-5 py-3" /></li>
                    <li><x-smash.entry :entry="$e['priya']" class="px-5 py-3" size="sm" /></li>
                </ul>
            </x-ui.card>
        </div>

        <x-ui.card padding="lg" class="mt-6">
            <div class="mb-5 flex flex-wrap items-baseline justify-between gap-4">
                <div>
                    <p class="kicker">Halloween Smash · in progress</p>
                    <p class="mt-1 font-serif text-h4 text-heading">From the quarter-finals</p>
                </div>
                <p class="text-sm text-ink-muted">Highlighted: Lotte Jansen's path</p>
            </div>
            <x-smash.bracket :rounds="$this->demoBracket" :for="$p['lotte']" label="Halloween Smash draw" />
        </x-ui.card>

        <x-ui.card padding="lg" class="mt-6">
            <p class="kicker">Welcome Week Open · completed</p>
            <p class="mt-1 mb-5 font-serif text-h4 text-heading">Semi-finals, final and champion</p>
            <x-smash.bracket :rounds="$this->demoFinishedBracket" :champion="$e['wwLotte']" label="Welcome Week Open draw" />
        </x-ui.card>
    </x-ui.section>

    {{-- ---------------------------------------------------------------- Decoration --}}
    <x-ui.section id="smash-decoration" kicker="x-smash.court-pattern · x-smash.shuttle" title="Court and shuttle" intro="After the club's own “Smash it forward” poster: court lines in perspective and a shuttle smashed over the net. Decorative only, coloured with one text class.">
        <div class="theme-navy relative isolate overflow-hidden px-6 py-14 sm:px-12 lg:py-20">
            <x-smash.court-pattern class="absolute top-1/2 right-[-4rem] -z-10 h-[135%] w-auto -translate-y-1/2 text-white sm:right-0" />
            <p class="kicker">BUas Badminton Club</p>
            <p class="mt-3 font-display text-[clamp(2.75rem,2rem+4vw,5.5rem)] leading-[0.9] font-extrabold italic uppercase text-white">Smash it<br>forward.</p>
            <p class="mt-6 max-w-md text-intro text-ink-soft">Club nights twice a week and ratings that move after every match.</p>
        </div>

        <div class="mt-6 grid gap-6 md:grid-cols-3">
            <div class="relative isolate flex h-56 items-end overflow-hidden bg-canvas-muted p-5">
                <x-smash.court-pattern class="absolute inset-0 -z-10 size-full text-navy-800 dark:text-navy-200" fit="cover" />
                <p class="label-caps text-ink-soft">perspective · on grey</p>
            </div>
            <div class="relative isolate flex h-56 items-end overflow-hidden border border-line bg-surface p-5">
                <x-smash.court-pattern variant="plan" class="absolute inset-x-6 top-5 bottom-12 -z-10 text-navy-800 dark:text-navy-200" intensity="bold" />
                <p class="label-caps text-ink-soft">variant="plan" · bold</p>
            </div>
            <div class="theme-navy relative isolate flex h-56 items-end overflow-hidden p-5">
                <x-smash.court-pattern class="absolute inset-0 -z-10 size-full text-white" intensity="subtle" :shuttle="false" fit="cover" />
                <p class="label-caps text-ink-soft">subtle · no shuttle</p>
            </div>
        </div>

        <div class="mt-6 grid gap-6 md:grid-cols-2">
            <x-ui.card padding="lg">
                <p class="label-caps text-ink-muted">x-smash.shuttle · colour</p>
                <div class="mt-4 flex flex-wrap items-end gap-6 text-heading">
                    <x-smash.shuttle class="size-28" title="Shuttlecock" />
                    <x-smash.shuttle class="size-20" :angle="-135" />
                    <x-smash.shuttle class="size-14" :angle="40" />
                    <x-smash.shuttle class="size-10" :angle="180" />
                    <x-smash.shuttle class="size-6" />
                </div>
            </x-ui.card>
            <x-ui.card variant="navy" padding="lg">
                <p class="label-caps text-ink-muted">variant="line" · on navy</p>
                <div class="mt-4 flex flex-wrap items-end gap-6 text-white">
                    <x-smash.shuttle variant="line" class="size-28" :angle="-120" />
                    <x-smash.shuttle class="size-20 text-navy-950" :angle="-60" />
                    <x-smash.shuttle variant="line" class="size-14 text-orange-400" :angle="25" />
                </div>
            </x-ui.card>
        </div>
    </x-ui.section>

</div>
