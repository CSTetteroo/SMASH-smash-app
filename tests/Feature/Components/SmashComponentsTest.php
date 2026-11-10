<?php

use App\Actions\Matches\SaveMatch;
use App\Enums\ClubNightKind;
use App\Enums\Discipline;
use App\Enums\MatchStatus;
use App\Enums\Programme;
use App\Enums\RsvpStatus;
use App\Enums\Side;
use App\Enums\TournamentStatus;
use App\Models\BadmintonMatch;
use App\Models\Tournament;
use App\Models\TournamentEntry;
use App\Models\User;
use App\Services\Prediction\MatchPredictor;
use App\Services\Rating\Leaderboard;
use App\Services\Rating\RatingEngine;
use App\Services\Stats\PlayerStats;
use App\Services\Tournament\Bracket;
use App\Services\Tournament\DrawGenerator;
use App\Services\Tournament\StandingRow;
use App\Services\Tournament\Standings;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/*
 * Renders every x-smash.* component with data from the real domain code (SaveMatch, the rating
 * engine, the draw generator and the stats services), in every match state the pages will see.
 * Helpers are prefixed with "smashComponents" because Pest test-file functions are global.
 */

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-03 18:00:00'));
});

function smashComponentsPlayer(string $name, ?Programme $programme = Programme::Games): User
{
    return User::factory()->create(['name' => $name, 'programme' => $programme]);
}

/**
 * @param  list<User>  $sideA
 * @param  list<User>  $sideB
 * @param  list<array{0: int, 1: int}>  $games  side A's points first
 * @param  array<string, mixed>  $extra
 */
function smashComponentsMatch(array $sideA, array $sideB, array $games, array $extra = []): BadmintonMatch
{
    return SaveMatch::handle([
        'discipline' => count($sideA) > 1 ? Discipline::Doubles : Discipline::Singles,
        'side_a' => array_map(fn (User $user): int => $user->id, $sideA),
        'side_b' => array_map(fn (User $user): int => $user->id, $sideB),
        'status' => MatchStatus::Completed,
        'games' => array_map(fn (array $game): array => ['a' => $game[0], 'b' => $game[1]], $games),
        ...$extra,
    ]);
}

/**
 * Friendlies in every state, with real ratings: Wei climbs, Priya drops, then Priya beats Wei
 * (an upset by more than smash.upset_threshold).
 *
 * @return array{players: array<string, User>, matches: array<string, BadmintonMatch>}
 */
function smashComponentsFriendlies(): array
{
    $p = [
        'lotte' => smashComponentsPlayer('Lotte Jansen', Programme::Games),
        'sam' => smashComponentsPlayer('Sam de Vries', Programme::MediaCreativeBusiness),
        'wei' => smashComponentsPlayer('Wei Chen', Programme::DataScienceAi),
        'priya' => smashComponentsPlayer('Priya Raman', null),
        'daan' => smashComponentsPlayer('Daan Bakker', Programme::Imagineering),
        'hannah' => smashComponentsPlayer('Hannah Schmidt', Programme::FacilityManagement),
        'lucas' => smashComponentsPlayer('Lucas Peeters', Programme::BuiltEnvironment),
        'giulia' => smashComponentsPlayer('Giulia Romano', Programme::LeisureEvents),
        'newbie' => smashComponentsPlayer('Noor Haddad', Programme::Hotel),
    ];

    // One rating replay at the end instead of one per result (callers reload what they render).
    $m = RatingEngine::deferred(function () use ($p): array {
        foreach (range(1, 4) as $day) {
            smashComponentsMatch([$p['wei']], [$p['daan']], [[21, 10], [21, 10]], ['played_at' => "2026-09-0{$day} 20:00"]);
        }
        foreach (range(1, 2) as $day) {
            smashComponentsMatch([$p['hannah']], [$p['priya']], [[21, 10], [21, 10]], ['played_at' => "2026-09-1{$day} 20:00"]);
        }

        return [
            'singles' => smashComponentsMatch([$p['lotte']], [$p['sam']], [[21, 17], [19, 21], [21, 15]], ['played_at' => '2026-10-31 15:58', 'court' => 2]),
            'doubles' => smashComponentsMatch([$p['sam'], $p['lucas']], [$p['lotte'], $p['giulia']], [[21, 18], [21, 16]], ['played_at' => '2026-10-27 20:40', 'court' => 1]),
            'unrated' => smashComponentsMatch([$p['daan']], [$p['hannah']], [[12, 15]], ['played_at' => '2026-10-29 21:40', 'is_rated' => false, 'best_of' => 1, 'points_per_game' => 15, 'court' => 4]),
            'upset' => smashComponentsMatch([$p['priya']], [$p['wei']], [[18, 21], [21, 19], [23, 21]], ['played_at' => '2026-11-02 21:10', 'court' => 4]),
            'live' => smashComponentsMatch([$p['lotte']], [$p['wei']], [[21, 15], [18, 21], [14, 11]], ['status' => MatchStatus::InProgress, 'scheduled_at' => '2026-11-03 17:30', 'court' => 3]),
            'fixture' => smashComponentsMatch([$p['sam']], [$p['priya']], [], ['status' => MatchStatus::Scheduled, 'scheduled_at' => '2026-11-05 20:30', 'court' => 2]),
            'unscheduled' => smashComponentsMatch([$p['daan']], [$p['giulia']], [], ['status' => MatchStatus::Scheduled]),
            'walkover' => smashComponentsMatch([$p['hannah']], [$p['daan']], [], ['status' => MatchStatus::Walkover, 'walkover_winner' => 'b', 'played_at' => '2026-10-20 20:00']),
            'cancelled' => smashComponentsMatch([$p['lucas'], $p['giulia']], [$p['daan'], $p['hannah']], [], ['status' => MatchStatus::Cancelled, 'scheduled_at' => '2026-10-29 21:00']),
        ];
    });

    foreach ($p as $user) {
        $user->refresh();
    }

    return ['players' => $p, 'matches' => $m];
}

/**
 * A six-player knockout (bracket of 8: two byes), with the draw made and one quarter-final played.
 *
 * @param  list<User>  $players
 */
function smashComponentsKnockout(array $players): Tournament
{
    $tournament = Tournament::factory()->open()->singles()->knockout()->create(['name' => 'Halloween Smash']);

    foreach ($players as $player) {
        TournamentEntry::factory()->for($tournament)->create(['player_id' => $player->id]);
    }

    DrawGenerator::generate($tournament);

    $first = $tournament->matches()->where('round', 1)->whereNotNull('entry_a_id')->whereNotNull('entry_b_id')->orderBy('bracket_position')->firstOrFail();
    SaveMatch::handle(['status' => MatchStatus::Completed, 'games' => [['a' => 21, 'b' => 15], ['a' => 21, 'b' => 12]], 'played_at' => '2026-11-01 14:00'], $first);

    return $tournament->refresh();
}

/**
 * Play every remaining match of a knockout (side A wins), round by round.
 */
function smashComponentsFinish(Tournament $tournament): Tournament
{
    for ($round = 1; $round <= $tournament->totalRounds(); $round++) {
        $open = $tournament->matches()->where('round', $round)->where('status', MatchStatus::Scheduled->value)->orderBy('bracket_position')->get();

        foreach ($open as $match) {
            SaveMatch::handle(['status' => MatchStatus::Completed, 'games' => [['a' => 21, 'b' => 18], ['a' => 21, 'b' => 19]], 'played_at' => '2026-11-02 1'.$round.':00'], $match);
        }
    }

    return $tournament->refresh();
}

function smashComponentsDetails(BadmintonMatch $match): BadmintonMatch
{
    return BadmintonMatch::query()->withDetails()->findOrFail($match->id);
}

test('the match card renders every friendly state with real ratings, deltas and upsets', function () {
    ['players' => $p, 'matches' => $m] = smashComponentsFriendlies();
    $m = array_map(fn (BadmintonMatch $match): BadmintonMatch => smashComponentsDetails($match), $m);
    $card = '<x-smash.match-card :match="$match" />';

    // Completed singles: names, every game, the rating change from match_players, the link.
    $change = (int) round((float) $m['singles']->ratingChangeFor($p['lotte']));
    expect($change)->toBeGreaterThan(0);
    $this->blade($card, ['match' => $m['singles']])
        ->assertSee('Lotte Jansen beat Sam de Vries 2–1 (21–17, 19–21, 21–15).')
        ->assertSee('+'.$change)
        ->assertSee('Match report')
        ->assertSee(route('matches.show', $m['singles']))
        ->assertSee('Friendly')
        ->assertDontSee('Upset');

    // The upset: the winner's pre-match rating was lower by at least the threshold.
    $before = $m['upset']->participants->mapWithKeys(fn ($participant) => [$participant->side->value => $participant->rating_before]);
    expect($before['b'] - $before['a'])->toBeGreaterThanOrEqual((float) config('smash.upset_threshold'));
    $this->blade($card, ['match' => $m['upset']])
        ->assertSee('Upset')
        ->assertSee('Won as the underdog by '.(int) round($before['b'] - $before['a']).' rating points')
        ->assertSee('Yesterday');

    // Doubles: both pairs, the discipline chip, a rating change per player.
    $this->blade($card, ['match' => $m['doubles']])
        ->assertSee('Sam de Vries &amp; Lucas Peeters beat Lotte Jansen &amp; Giulia Romano 2–0', false)
        ->assertSee('Doubles')
        ->assertSee('data-smash-rating-delta', false);

    // Live: the game in progress is not counted yet.
    $this->blade($card, ['match' => $m['live']])
        ->assertSee('Live: Lotte Jansen against Wei Chen, 1–1 in games (21–15, 18–21, 14–11).')
        ->assertSee('Follow live')
        ->assertSee('Today');

    // Fixture with a time and a court, and one with neither.
    $this->blade($card, ['match' => $m['fixture']])
        ->assertSee('Thursday 5 November')
        ->assertSee('20:30')
        ->assertSee('Court 2')
        ->assertSee('Preview');
    $this->blade($card, ['match' => $m['unscheduled']])
        ->assertSee('TBC')
        ->assertSee('date to be confirmed');

    // Walkover, cancelled and unrated.
    $this->blade($card, ['match' => $m['walkover']])
        ->assertSee('W/O')
        ->assertSee('Daan Bakker won by walkover against Hannah Schmidt.')
        ->assertSee('Walkover');
    $this->blade($card, ['match' => $m['cancelled']])
        ->assertSee('Cancelled')
        ->assertSee('Details');
    $this->blade($card, ['match' => $m['unrated']])
        ->assertSee('Unrated')
        ->assertSee('Best of 1 · to 15')
        ->assertDontSee('data-smash-rating-delta', false);
});

test('the match card shows chances, perspective, compact rows and can hide ratings', function () {
    ['players' => $p, 'matches' => $m] = smashComponentsFriendlies();
    $fixture = smashComponentsDetails($m['fixture']);
    $prediction = MatchPredictor::forMatch($fixture);
    $percentA = max(1, min(99, (int) round($prediction->probabilityA * 100)));

    $this->blade('<x-smash.match-card :match="$match" :probability="$prediction" />', ['match' => $fixture, 'prediction' => $prediction])
        ->assertSee($percentA.'<span class="text-[0.6em]">%</span>', false)
        ->assertSee((100 - $percentA).'<span class="text-[0.6em]">%</span>', false);

    $this->blade('<x-smash.match-card :match="$match" :probability="0.71" />', ['match' => $fixture])
        ->assertSee('71<span class="text-[0.6em]">%</span>', false);

    // Ratings next to names by default; ratings="false" drops them.
    $this->blade('<x-smash.match-card :match="$match" />', ['match' => $fixture])->assertSee('data-smash-rating', false);
    $this->blade('<x-smash.match-card :match="$match" :ratings="false" />', ['match' => $fixture])->assertDontSee('data-smash-rating', false);

    // "for": the player's side is highlighted and the result chip reads from their side.
    $this->blade('<x-smash.match-card :match="$match" :for="$user" />', ['match' => smashComponentsDetails($m['singles']), 'user' => $p['lotte']])
        ->assertSee('Won');
    $this->blade('<x-smash.match-card :match="$match" :for="$user" variant="compact" />', ['match' => smashComponentsDetails($m['doubles']), 'user' => $p['lotte']])
        ->assertSee('Lost')
        ->assertSee('Tue 27 Oct');
});

test('the match card handles tournament rounds, byes and open draw slots', function () {
    ['players' => $p] = smashComponentsFriendlies();
    $tournament = smashComponentsKnockout([$p['lotte'], $p['sam'], $p['wei'], $p['priya'], $p['daan'], $p['hannah']]);
    $matches = BadmintonMatch::query()->withDetails()->where('tournament_id', $tournament->id)->orderBy('round')->orderBy('bracket_position')->get();

    $bye = $matches->first(fn (BadmintonMatch $match): bool => $match->isBye());
    $played = $matches->first(fn (BadmintonMatch $match): bool => $match->round === 1 && $match->status === MatchStatus::Completed);
    $open = $matches->first(fn (BadmintonMatch $match): bool => $match->round === 2 && ($match->entry_a_id === null || $match->entry_b_id === null));
    $final = $matches->firstWhere('round', 3);

    $this->blade('<x-smash.match-card :match="$match" />', ['match' => $bye])
        ->assertSee($bye->winningEntry()?->name().' advances with a bye.')
        ->assertSee('Bye')
        ->assertDontSee('Match report')
        ->assertDontSee(route('matches.show', $bye));

    $this->blade('<x-smash.match-card :match="$match" />', ['match' => $played])
        ->assertSee('Halloween Smash')
        ->assertSee('Quarter-final')
        ->assertSee(route('tournaments.show', $tournament))
        ->assertSee('Match report');

    // An open slot never shows win chances, even when a prediction is passed.
    $this->blade('<x-smash.match-card :match="$match" :probability="0.64" />', ['match' => $open])
        ->assertSee('TBD')
        ->assertSee('Semi-final')
        ->assertDontSee('<span class="text-[0.6em]">%</span>', false);

    $this->blade('<x-smash.match-card :match="$match" variant="compact" />', ['match' => $final])
        ->assertSee('Final')
        ->assertSee('TBD');

    // x-smash.player with match + side: the known entry's player even before participants are
    // synced (the other slot is still open), and "TBD" for the open slot.
    $known = $open->entry_a_id !== null ? Side::A : Side::B;
    expect($open->playersOn($known))->toBeEmpty();
    $this->blade('<x-smash.player :match="$match" :side="$known" /><x-smash.player :match="$match" :side="$open" />', ['match' => $open, 'known' => $known, 'open' => $known->opposite()])
        ->assertSee('data-smash-player-link>'.e($open->entryOn($known)?->name() ?? ''), false)
        ->assertSee('TBD');
});

test('match cards and brackets run no queries for eager-loaded matches', function () {
    ['players' => $p] = smashComponentsFriendlies();
    $tournament = smashComponentsKnockout([$p['lotte'], $p['sam'], $p['wei'], $p['priya'], $p['daan'], $p['hannah']]);

    $matches = BadmintonMatch::query()->withDetails()->latestFirst()->get();
    expect($matches->count())->toBeGreaterThan(15);

    DB::flushQueryLog();
    DB::enableQueryLog();
    $this->blade(
        '@foreach ($matches as $match)<x-smash.match-card :match="$match" :for="$user" />@endforeach',
        ['matches' => $matches, 'user' => $p['lotte']],
    )->assertSee('Halloween Smash');
    // Round labels need the draw size: at most one query per tournament (memoised).
    expect(count(DB::getQueryLog()))->toBeLessThanOrEqual($matches->pluck('tournament_id')->filter()->unique()->count());

    $rounds = Bracket::for($tournament);
    DB::flushQueryLog();
    $this->blade('<x-smash.bracket :rounds="$rounds" :for="$user" />', ['rounds' => $rounds, 'user' => $p['lotte']]);
    expect(DB::getQueryLog())->toBe([]);
    DB::disableQueryLog();
});

test('the bracket renders the Bracket service draw from byes to the champion', function () {
    ['players' => $p] = smashComponentsFriendlies();
    $tournament = smashComponentsKnockout([$p['lotte'], $p['sam'], $p['wei'], $p['priya'], $p['daan'], $p['hannah']]);
    $rounds = Bracket::for($tournament);

    expect(array_column($rounds, 'label'))->toBe(['Quarter-finals', 'Semi-finals', 'Final']);

    $played = $rounds[0]['matches']->first(fn (BadmintonMatch $match): bool => $match->status === MatchStatus::Completed);
    $view = $this->blade('<x-smash.bracket :rounds="$rounds" :for="$user" label="Halloween Smash draw" />', ['rounds' => $rounds, 'user' => $p['lotte']])
        ->assertSee('Halloween Smash draw')
        ->assertSee('Quarter-finals')
        ->assertSee('Semi-finals')
        ->assertSee('Final')
        ->assertSee('Bye')
        ->assertSee('TBD')
        ->assertSee($played->winningEntry()?->shortName() ?? '')
        ->assertSee(route('matches.show', $played))
        ->assertDontSee('Champion');

    // Lotte's slot is highlighted wherever she appears.
    $view->assertSee('bg-navy-50', false);

    $tournament = smashComponentsFinish($tournament);
    $rounds = Bracket::for($tournament);
    $champion = $tournament->champion();

    expect($tournament->status)->toBe(TournamentStatus::Completed)
        ->and($champion)->not->toBeNull();

    $this->blade('<x-smash.bracket :rounds="$rounds" :champion="$champion" />', ['rounds' => $rounds, 'champion' => $champion])
        ->assertSee('Champion')
        ->assertSee($champion->name());

    // No draw yet: nothing, or an empty state when asked for one.
    $this->blade('<x-smash.bracket :rounds="[]" />')->assertDontSee('data-smash-bracket', false);
    $this->blade('<x-smash.bracket :rounds="[]" empty="The draw is made when registration closes." />')
        ->assertSee('The draw is made when registration closes.');
});

test('entries and standings render tournament entries, pairs and seeds', function () {
    ['players' => $p] = smashComponentsFriendlies();

    $doubles = Tournament::factory()->open()->doubles()->knockout()->create(['name' => 'Winter Doubles Showdown']);
    TournamentEntry::factory()->for($doubles)->create(['player_id' => $p['lotte']->id, 'partner_id' => $p['sam']->id, 'seed' => 1]);
    TournamentEntry::factory()->for($doubles)->create(['player_id' => $p['lucas']->id, 'partner_id' => $p['giulia']->id]);
    $entries = $doubles->entries()->with(['player', 'partner'])->seeded()->get();

    DB::flushQueryLog();
    DB::enableQueryLog();
    $view = $this->blade(
        '@foreach ($entries as $entry)<x-smash.entry :entry="$entry" :for="$user" />@endforeach',
        ['entries' => $entries, 'user' => $p['sam']],
    );
    expect(DB::getQueryLog())->toBe([]);
    DB::disableQueryLog();

    $view->assertSee('Lotte Jansen')
        ->assertSee('Sam de Vries')
        ->assertSee('You')
        ->assertSee('Seed 1')
        ->assertSee('Unseeded')
        ->assertSee((string) (int) round($entries[0]->rating()));

    // A round robin table from the Standings service: no seed box, record per entry.
    $roundRobin = Tournament::factory()->open()->singles()->roundRobin()->create(['name' => 'Autumn Ladder']);
    foreach ([$p['wei'], $p['priya'], $p['hannah']] as $player) {
        TournamentEntry::factory()->for($roundRobin)->create(['player_id' => $player->id]);
    }
    DrawGenerator::generate($roundRobin);
    $first = $roundRobin->matches()->whereNotNull('entry_a_id')->whereNotNull('entry_b_id')->orderBy('round')->orderBy('id')->firstOrFail();
    SaveMatch::handle(['status' => MatchStatus::Completed, 'games' => [['a' => 21, 'b' => 11], ['a' => 21, 'b' => 13]]], $first);
    $rows = Standings::for($roundRobin->refresh());

    $leader = $rows->first();
    expect($leader)->toBeInstanceOf(StandingRow::class);

    $this->blade(
        '@foreach ($rows as $row)<x-smash.rank :position="$row->position" size="sm" /><x-smash.entry :entry="$row->entry" :seed="false" :rating="false" size="sm" /><x-smash.record :wins="$row->won" :losses="$row->lost" size="sm" :label="false" :rate="false" />@endforeach',
        ['rows' => $rows],
    )
        ->assertSee($leader->entry->name())
        ->assertSee('1 wins, 0 losses')
        ->assertDontSee('Unseeded');
});

test('numbers accept the Leaderboard and PlayerStats DTOs as they are', function () {
    ['players' => $p] = smashComponentsFriendlies();
    $rows = Leaderboard::for(Discipline::Singles);
    expect($rows)->not->toBeEmpty();

    $view = $this->blade(<<<'BLADE'
        @foreach ($rows as $row)
            <x-smash.rank :position="$row->position" :movement="$row->movement" size="sm" />
            <x-smash.player :user="$row->user" size="sm" />
            <x-smash.rating :value="$row->rating" :provisional="$row->provisional" size="sm" />
            <x-smash.record :wins="$row->wins" :losses="$row->losses" size="sm" :label="false" />
            <x-smash.form :results="$row->form" size="sm" />
            <x-smash.rating-delta :value="$row->change30d" size="sm" />
        @endforeach
        BLADE, ['rows' => $rows]);

    $wei = $rows->first(fn ($row) => $row->user->is($p['wei']));
    $view->assertSee('Wei Chen')
        ->assertSee('Data Science &amp; AI', false)
        ->assertSee((string) $wei->rating)
        ->assertSee('(provisional)')
        ->assertSee('Recent form, newest first: loss, win, win, win, win')
        ->assertSee($wei->wins.' wins, '.$wei->losses.' losses');

    $stats = PlayerStats::for($p['wei'], Discipline::Singles);
    expect($stats->form[0])->toHaveKeys(['result', 'match_id'])
        ->and($stats->ratingHistory)->toHaveCount(6);

    $this->blade(<<<'BLADE'
        <x-smash.form :results="$stats->form" label="Form" />
        <x-smash.rating-chart :points="$stats->ratingHistory" label="Singles rating" :baseline="1500" table />
        <x-smash.sparkline :points="$stats->ratingHistory" label="Season trend" />
        <x-smash.rank variant="plain" :position="$stats->rank" :of="$stats->rankedPlayers" label="Singles rank" />
        <x-smash.rating :value="$stats->rating" :provisional="$stats->provisional" size="xl" label="Singles" :delta="$stats->change30d" />
        <x-smash.stat-row :items="[
            ['value' => $stats->rating, 'label' => 'Singles rating', 'sublabel' => 'Peak '.$stats->peak],
            ['value' => $stats->winRate === null ? '–' : (int) round($stats->winRate * 100), 'suffix' => '%', 'label' => 'Win rate'],
            ['value' => $stats->currentStreak, 'label' => 'Streak'],
        ]" />
        BLADE, ['stats' => $stats])
        ->assertSee(route('matches.show', $stats->form[0]['match_id']))
        ->assertSee('Singles rating: started at 1500')
        ->assertSee('Show the numbers')
        ->assertSee('Starting rating 1500')
        ->assertSee('data-smash-sparkline', false)
        ->assertSee('Season trend')
        ->assertSee('of '.$stats->rankedPlayers)
        ->assertSee('Peak '.$stats->peak)
        ->assertSee('Win rate');
});

test('components stay calm for new players, missing programmes and empty data', function () {
    ['players' => $p] = smashComponentsFriendlies();
    $stats = PlayerStats::for($p['newbie'], Discipline::Singles);

    expect($stats->rank)->toBeNull()
        ->and($stats->ratingHistory)->toBe([])
        ->and($stats->form)->toBe([]);

    $this->blade(<<<'BLADE'
        <x-smash.rank variant="plain" :position="$stats->rank" label="Singles rank" />
        <x-smash.rank :position="$stats->rank" />
        <x-smash.rating :value="$stats->rating" :provisional="$stats->provisional" />
        <x-smash.record :wins="$stats->wins" :losses="$stats->losses" bar />
        <x-smash.form :results="$stats->form" />
        <x-smash.rating-chart :points="$stats->ratingHistory" label="Singles rating" />
        <x-smash.sparkline :points="$stats->ratingHistory" />
        <x-smash.player :user="$newbie" rating />
        <x-smash.player :user="$priya" rating />
        <x-smash.player name="TBD" />
        <x-smash.rating :value="null" label="Doubles" />
        <x-smash.games :games="[]" empty="No games yet" />
        BLADE, ['stats' => $stats, 'newbie' => $p['newbie'], 'priya' => $p['priya']])
        ->assertSee('Unranked')
        ->assertSee('1500')
        ->assertSee('(provisional)')
        ->assertSee('0 wins, 0 losses')
        ->assertSee('No recent matches')
        ->assertSee('No rated matches yet')
        ->assertDontSee('data-smash-sparkline', false)
        ->assertSee('Noor Haddad')
        ->assertSee('Hotel')
        ->assertSee('Priya Raman')
        ->assertSee('TBD')
        ->assertSee('Unrated')
        ->assertSee('No games yet');
});

test('the win probability reads a MatchPrediction, a match or raw numbers', function () {
    ['players' => $p, 'matches' => $m] = smashComponentsFriendlies();
    $fixture = smashComponentsDetails($m['fixture']);
    $prediction = MatchPredictor::forMatch($fixture);
    $percentA = max(1, min(99, (int) round($prediction->probabilityA * 100)));

    $this->blade('<x-smash.win-probability :prediction="$prediction" :match="$match" variant="large" caption="Who wins?" />', ['prediction' => $prediction, 'match' => $fixture])
        ->assertSee('Who wins?')
        ->assertSee('Win probability: Sam de Vries '.$percentA.' percent, Priya Raman '.(100 - $percentA).' percent.')
        ->assertSee($prediction->confidence);

    $predicted = MatchPredictor::predict([$p['wei'], $p['daan']], [$p['lotte'], $p['sam']], Discipline::Doubles);
    $this->blade('<x-smash.win-probability :prediction="$prediction" :side-a="$a" :side-b="$b" variant="compact" />', ['prediction' => $predicted, 'a' => [$p['wei'], $p['daan']], 'b' => [$p['lotte'], $p['sam']]])
        ->assertSee('Chen &amp; Bakker', false)
        ->assertSee('Jansen &amp; De Vries', false);

    $this->blade('<x-smash.win-probability :a="0.64" label-a="Mateusz" label-b="Arjun" />')
        ->assertSee('Mateusz 64 percent, Arjun 36 percent')
        ->assertSee('Slight edge: Mateusz.');
    $this->blade('<x-smash.win-probability :a="0.18" label-a="Daan" label-b="Ayşe" :confidence="false" />')
        ->assertSee('Daan 18 percent, Ayşe 82 percent.')
        ->assertDontSee('Strong favourite');

    // A tournament slot that is not known yet reads "TBD" and a toss-up.
    $tournament = smashComponentsKnockout([$p['lotte'], $p['sam'], $p['wei'], $p['priya'], $p['daan'], $p['hannah']]);
    $final = BadmintonMatch::query()->withDetails()->where('tournament_id', $tournament->id)->where('round', 3)->firstOrFail();
    $this->blade('<x-smash.win-probability :prediction="$prediction" :match="$match" />', ['prediction' => MatchPredictor::forMatch($final), 'match' => $final])
        ->assertSee('TBD 50 percent')
        ->assertSee('Toss-up');
});

test('games, badges, avatars, players and decoration render', function () {
    ['players' => $p, 'matches' => $m] = smashComponentsFriendlies();
    $live = smashComponentsDetails($m['live']);

    $this->blade('<x-smash.games :match="$match" />', ['match' => $live])
        ->assertSee('Game 3:')
        ->assertSee('(in progress)');
    $this->blade('<x-smash.games :match="$match" side="b" layout="boxes" />', ['match' => smashComponentsDetails($m['singles'])])
        ->assertSee('data-smash-games="boxes"', false);
    $this->blade('<x-smash.games :games="[[29, 30], [30, 28], [21, 9]]" size="sm" />')->assertSee('29');

    $view = $this->blade(<<<'BLADE'
        @foreach ($statuses as $status)<x-smash.status-badge :status="$status" />@endforeach
        <x-smash.status-badge :match="$walkover" size="sm" />
        <x-smash.discipline-badge discipline="doubles" icon />
        <x-smash.discipline-badge :discipline="\App\Enums\Discipline::Singles" />
        BLADE, [
        'statuses' => [...MatchStatus::cases(), ...TournamentStatus::cases(), ...RsvpStatus::cases(), ClubNightKind::Clinic],
        'walkover' => smashComponentsDetails($m['walkover']),
    ]);
    $view->assertSee('Live')->assertSee('Registration open')->assertSee('Waitlist')->assertSee('Clinic')->assertSee('Walkover')->assertSee('Doubles')->assertSee('Singles');

    $p['hannah']->update(['is_active' => false]);
    $this->blade(<<<'BLADE'
        <x-smash.avatar :user="$lotte" ring="auto" :rank="1" size="xl" />
        <x-smash.avatar :user="$hannah" />
        <x-smash.avatar placeholder size="sm" />
        <x-smash.player :users="[$sam, $lucas]" rating discipline="doubles" />
        <x-smash.player :user="$lotte" variant="compact" rating :winner="true" short />
        <x-smash.player :user="$wei" variant="stacked" align="end" :href="false" />
        <x-smash.court-pattern />
        <x-smash.court-pattern variant="plan" intensity="bold" :shuttle="false" />
        <x-smash.shuttle title="Shuttlecock" :angle="-135" />
        <x-smash.sparkline :values="[1500, 1512, 1498, 1530]" />
        BLADE, ['lotte' => $p['lotte'], 'hannah' => $p['hannah']->refresh(), 'sam' => $p['sam'], 'lucas' => $p['lucas'], 'wei' => $p['wei']])
        ->assertSee('outline-smash-500', false)
        ->assertSee('tone-grey', false)
        ->assertSee('Sam de Vries')
        ->assertSee('Lucas Peeters')
        ->assertSee('Jansen')
        ->assertSee('Wei Chen')
        ->assertSee('data-smash-court-pattern="perspective"', false)
        ->assertSee('data-smash-court-pattern="plan"', false)
        ->assertSee('aria-label="Shuttlecock"', false);
});

test('the overlapping ui primitives render the canonical domain components', function () {
    ['players' => $p] = smashComponentsFriendlies();
    $stats = PlayerStats::for($p['lotte'], Discipline::Singles);

    $this->blade('<x-ui.player :user="$user" size="sm" />', ['user' => $p['lotte']])->assertSee('data-smash-player', false)->assertSee('Lotte Jansen');
    $this->blade('<x-ui.player name="Guest player" meta="Visitor" />')->assertDontSee('data-smash-player', false)->assertSee('Guest player');
    $this->blade('<x-ui.game-scores :games="[[21, 17], [19, 21]]" layout="boxes" />')->assertSee('data-smash-games="boxes"', false);
    $this->blade('<x-ui.probability :a="0.62" label-a="Sam" label-b="Lotte" />')->assertSee('data-smash-win-probability', false)->assertSee('Sam 62 percent');
    $this->blade('<x-ui.line-chart :points="$points" label="Singles rating" :baseline="1500" />', ['points' => $stats->ratingHistory])
        ->assertSee('data-smash-rating-chart', false)
        ->assertSee('Show the numbers');
    $this->blade('<x-ui.rating :value="null" />')->assertSee('Unrated');
    $this->blade('<x-ui.rating :value="1532" provisional size="xs" muted />')->assertSee('(provisional)');
    $this->blade('<x-ui.rank :position="null" />')->assertSee('Unranked');

    // The header's x-ui.avatar (name only) and x-smash.avatar pick the same tone for a player.
    $users = [$p['lotte'], $p['sam'], $p['wei'], $p['priya'], $p['daan'], $p['hannah']];
    $html = (string) $this->blade('@foreach ($users as $user)<x-smash.avatar :user="$user" /><x-ui.avatar :name="$user->name" />@endforeach', ['users' => $users]);
    preg_match_all('/tone-(navy|sky|orange|green|grey)/', $html, $found);

    $pairs = array_chunk($found[1], 2);
    expect($pairs)->toHaveCount(6);
    foreach ($pairs as [$smashTone, $uiTone]) {
        expect($smashTone)->toBe($uiTone)->not->toBe('grey');
    }
});

test('side labels in the match card follow the model helpers', function () {
    ['matches' => $m] = smashComponentsFriendlies();
    $doubles = smashComponentsDetails($m['doubles']);

    expect($doubles->sideLabel(Side::A))->toBe('Sam de Vries & Lucas Peeters');

    $this->blade('<x-smash.match-card :match="$match" />', ['match' => $doubles])
        ->assertSee('aria-label="Match report: Sam de Vries &amp; Lucas Peeters – Lotte Jansen &amp; Giulia Romano"', false);
});
