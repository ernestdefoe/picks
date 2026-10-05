<?php

declare(strict_types=1);

/*
 * The ESPN adapter, and the league registry it reads.
 *
 * 🚨 Every fixture here is a REAL response, saved off ESPN in September 2026 —
 * a Commanders/Packers game, Timberwolves/Bucks, Braves/Phillies, Stars/Sabres
 * and Everton 2–2 Manchester United. ESPN publishes no documentation for this
 * API, so a hand-written payload would only prove the adapter agrees with
 * whoever imagined it, and the four shape differences the adapter exists to
 * absorb are exactly the ones nobody would think to imagine.
 *
 * 🚨 Plain PHP, no PHPUnit and no Guzzle. `get()` is overridden to read a file,
 * so nothing here touches the network, the database or Flarum:
 *
 *     php tests/run.php
 */

require __DIR__ . '/../src/Service/Leagues/League.php';
require __DIR__ . '/../src/Service/Leagues/Leagues.php';
require __DIR__ . '/../src/Service/Providers/Provider.php';
require __DIR__ . '/../src/Service/Providers/EspnProvider.php';
require __DIR__ . '/../src/Service/CurrentWeek.php';
require __DIR__ . '/../src/Confidence/Selector.php';
require __DIR__ . '/../src/Confidence/Rules.php';
require __DIR__ . '/../src/Confidence/Scoring.php';

use Resofire\Picks\Confidence\Rules;
use Resofire\Picks\Confidence\Scoring;
use Resofire\Picks\Confidence\Selector;
use Resofire\Picks\Service\CurrentWeek;
use Resofire\Picks\Service\Leagues\League;
use Resofire\Picks\Service\Leagues\Leagues;
use Resofire\Picks\Service\Providers\EspnProvider;

/* --------------------------------------------------------------- the harness */

$failures = [];
$passed = 0;

function ok(bool $condition, string $why, string $context = ''): void
{
    global $failures;

    if (!$condition) {
        $failures[] = $why . ($context === '' ? '' : ' — ' . $context);
    }
}

function same($expected, $actual, string $why): void
{
    ok($expected === $actual, $why . ' (expected ' . json_encode($expected) . ', got ' . json_encode($actual) . ')');
}

/**
 * The adapter with the wire replaced by the fixture directory.
 *
 * 🚨 Only `get()` is overridden. Everything the adapter actually does — the
 * flattening, the pivot, the side matching, the finished-game rule — is the
 * real code, which is the only reason this proves anything.
 */
final class FixtureEspn extends EspnProvider
{
    public string $file = '';

    public int $calls = 0;

    public function __construct()
    {
    }

    protected function get(string $path, array $params): array
    {
        $this->calls++;

        $json = file_get_contents(__DIR__ . '/fixtures/' . $this->file);

        if ($json === false) {
            throw new RuntimeException('missing fixture ' . $this->file);
        }

        return json_decode($json, true);
    }
}

$espn = static function (string $file): FixtureEspn {
    $provider = new FixtureEspn();
    $provider->file = $file;

    return $provider;
};

$leagues = new Leagues();

/* ------------------------------------------------------------------ the tests */

$tests = [];

$tests['the registry answers, and an unknown league falls back'] = function () use ($leagues) {
    /*
     * A season row naming a league that has since been removed is somebody's
     * install, not a programming error — and skipping it beats a scheduled job
     * that dies with every other league's fixtures still unsynced.
     */
    same('cfb', $leagues->get('quidditch')->key, 'an unknown league did not fall back');
    same('cfb', $leagues->get(null)->key, 'a null league did not fall back');
    same('espn', $leagues->get('nfl')->provider, 'the NFL is not on ESPN');
    same('cfbd', $leagues->get('cfb')->provider, 'college football left CollegeFootballData');

    /*
     * 🚨 College football and the NFL share a VOCABULARY and not a provider.
     * A recap of either says the same words about yards and turnovers, which
     * is why there is one `gridiron` rather than two identical sports.
     */
    same('gridiron', $leagues->get('cfb')->sport, 'college football is not gridiron');
    same('gridiron', $leagues->get('nfl')->sport, 'the NFL is not gridiron');
    same('soccer', $leagues->get('epl')->sport, 'the Premier League is not soccer');

    // Weeks are a gridiron idea. Everything else is played to a date.
    ok($leagues->get('nfl')->hasWeeks, 'the NFL lost its weeks');
    ok(!$leagues->get('nba')->hasWeeks, 'basketball grew weeks');
    ok(!$leagues->get('epl')->hasWeeks, 'league football grew weeks');
};

$tests['a finished game is read from the state, never the status name'] = function () use ($espn, $leagues) {
    /*
     * 🚨 THE bug this adapter exists to avoid. Soccer's finished game is
     * `STATUS_FULL_TIME`; baseball's is `STATUS_FINAL`; a match settled on
     * penalties is something else again. Matching on the name works for the one
     * sport it was written against and silently leaves every other league's
     * games permanently "in progress" — a thread that never gets its recap and
     * never says why.
     */
    $games = $espn('espn-scoreboard-soccer.json')->games($leagues->get('epl'), 2026);

    ok($games !== [], 'no games came back');

    $everton = null;

    foreach ($games as $game) {
        if ($game['home'] === 'Everton') {
            $everton = $game;
        }
    }

    ok($everton !== null, 'the Everton match is missing');
    ok((bool) $everton['completed'], 'a full-time match was not read as finished');
    same('post', $everton['status'], 'the state was misread');
    same(2, $everton['home_score'], 'the home score');
    same(2, $everton['away_score'], 'the away score');
    same('Manchester United', $everton['away'], 'the away team');

    // A league with no weeks gets no week number invented for it.
    same(null, $everton['week'], 'a week was invented for league football');
};

$tests['a gridiron league keeps its week'] = function () use ($espn, $leagues) {
    $games = $espn('espn-scoreboard-nfl.json')->games($leagues->get('nfl'), 2026, 1);

    ok($games !== [], 'no games came back');
    same(1, $games[0]['week'], 'the week was lost');
    same('regular', $games[0]['season_type'], 'the season type');
    ok($games[0]['external_id'] !== '', 'the event id was lost');
};

$tests['an NFL box score lands in the shape the recap already reads'] = function () use ($espn, $leagues) {
    /*
     * 🚨 The whole seam, in one assertion. ESPN's NFL statistic names are
     * IDENTICAL to CollegeFootballData's — `firstDowns`, `totalYards`,
     * `thirdDownEff`, `turnovers`, `possessionTime` — and so are its player
     * labels: `C/ATT`, `YDS`, `TD`, `INT`, `CAR`, `REC`. So an NFL game is
     * described by the gridiron vocabulary that already exists, with no new
     * words written for it at all.
     */
    $box = $espn('espn-summary-nfl.json')->boxScore($leagues->get('nfl'), '401772936', 2026, 1);

    ok($box !== null, 'no box score came back');
    same(2, count($box['teams']), 'both sides');

    $stats = [];

    foreach ($box['teams'][0]['stats'] as $entry) {
        $stats[$entry['category']] = $entry['stat'];
    }

    foreach (['firstDowns', 'totalYards', 'thirdDownEff', 'turnovers', 'possessionTime'] as $key) {
        ok(isset($stats[$key]), $key . ' is missing from an NFL box score');
    }

    // The ratio and the clock survive as themselves rather than as numbers.
    ok(str_contains((string) $stats['thirdDownEff'], '-'), 'the third-down ratio was flattened to a number');
    ok(str_contains((string) $stats['possessionTime'], ':'), 'the possession clock was flattened to a number');

    // And the players, pivoted out of ESPN's parallel arrays.
    $passing = null;

    foreach ($box['players'][0]['categories'] as $category) {
        if ($category['name'] === 'passing') {
            $passing = $category;
        }
    }

    ok($passing !== null, 'the passing category is missing');

    $byType = [];

    foreach ($passing['types'] as $type) {
        $byType[$type['name']] = $type['athletes'][0];
    }

    ok(isset($byType['C/ATT'], $byType['YDS'], $byType['TD'], $byType['INT']), 'a passing figure is missing');
    same('Jayden Daniels', $byType['C/ATT']['name'], 'the quarterback');
    same('24/42', $byType['C/ATT']['stat'], 'his completions');
    same('200', $byType['YDS']['stat'], 'his yards');
    same('2', $byType['TD']['stat'], 'his touchdowns');
};

$tests['a baseball box score is prefixed, because three groups say "hits"'] = function () use ($espn, $leagues) {
    /*
     * 🚨 The trap the prefix exists for. ESPN answers baseball team statistics
     * as GROUPS, and `hits` appears in batting, pitching AND fielding meaning
     * hits made, hits allowed and hits handled in the field. Flattened onto
     * bare names, the last group wins and a fielding figure is printed as the
     * batting line — a number that looks entirely plausible and is about
     * something else.
     */
    $box = $espn('espn-summary-mlb.json')->boxScore($leagues->get('mlb'), '401816843', 2026);

    ok($box !== null, 'no box score came back');

    $stats = [];

    foreach ($box['teams'][0]['stats'] as $entry) {
        $stats[$entry['category']] = $entry['stat'];
    }

    foreach (['batting.hits', 'batting.runs', 'batting.homeRuns', 'pitching.strikeouts', 'fielding.errors'] as $key) {
        ok(isset($stats[$key]), $key . ' is missing from a baseball box score');
    }

    ok(!isset($stats['hits']), 'an unprefixed "hits" survived — three groups would have fought over it');

    // Batting hits and fielding hits are different numbers and stayed different.
    ok($stats['batting.hits'] !== $stats['fielding.hits'], 'the groups collapsed into one another');

    /*
     * 🚨 And the player groups are named from `type`, not `name` — baseball
     * leaves `name` null and the NFL leaves `type` null. Reading only one of
     * them loses every category in the other half of the sports.
     */
    $names = array_column($box['players'][0]['categories'], 'name');

    ok(in_array('batting', $names, true), 'the batting group lost its name');
    ok(in_array('pitching', $names, true), 'the pitching group lost its name');
};

$tests['a basketball box score has one unnamed group and keeps it'] = function () use ($espn, $leagues) {
    $box = $espn('espn-summary-nba.json')->boxScore($leagues->get('nba'), '401705718', 2026);

    ok($box !== null, 'no box score came back');

    $stats = [];

    foreach ($box['teams'][0]['stats'] as $entry) {
        $stats[$entry['category']] = $entry['stat'];
    }

    foreach (['fieldGoalPct', 'totalRebounds', 'assists', 'turnovers', 'pointsInPaint'] as $key) {
        ok(isset($stats[$key]), $key . ' is missing from a basketball box score');
    }

    // A made-attempted pair stays a pair.
    ok(str_contains((string) $stats['fieldGoalsMade-fieldGoalsAttempted'], '-'), 'the field-goal pair was flattened');

    /*
     * 🚨 Basketball supplies NEITHER `name` NOR `type` on its single player
     * group, because there is only one. Dropping a nameless group would lose
     * every basketball player line there is.
     */
    same(['general'], array_column($box['players'][0]['categories'], 'name'), 'the nameless group was lost');
};

$tests['a hockey box score names its groups from the other field again'] = function () use ($espn, $leagues) {
    $box = $espn('espn-summary-nhl.json')->boxScore($leagues->get('nhl'), '401803652', 2026);

    ok($box !== null, 'no box score came back');

    $names = array_column($box['players'][0]['categories'], 'name');

    ok(in_array('forwards', $names, true), 'the forwards group is missing');
    ok(in_array('goalies', $names, true), 'the goalies group is missing');

    /*
     * 🚨 ESPN answers a FOURTH hockey group, `skaters`, with a full set of
     * column labels and NO athletes in it. Carrying it through would put a
     * heading over nothing in every hockey recap; a group with nobody in it is
     * not a group.
     */
    ok(!in_array('skaters', $names, true), 'an empty group survived');
};

$tests['the away team\'s players are not filed under the home team'] = function () use ($espn, $leagues) {
    /*
     * 🚨 ESPN puts `homeAway` on the TEAM side of a box score and nowhere on
     * the player side, in every sport read. Defaulting both to "home" would
     * name the away team's players under the home team — wrong in a way that
     * reads perfectly plausibly and would never be noticed in a recap.
     */
    foreach (['nfl' => 'nfl', 'mlb' => 'mlb', 'nba' => 'nba', 'nhl' => 'nhl'] as $file => $league) {
        $box = $espn('espn-summary-' . $file . '.json')->boxScore($leagues->get($league), '1', 2026);

        ok($box !== null, $file . ': no box score');

        $sides = array_column($box['players'], 'homeAway');

        sort($sides);

        same(['away', 'home'], $sides, $file . ': the two player sides are not one of each');
    }
};

$tests['a summary is fetched once per game, and only so many per run'] = function () use ($espn, $leagues) {
    /*
     * 🚨 A box score here is ONE CALL PER GAME, unlike CollegeFootballData
     * which answers a whole week at once. A Saturday of college basketball is a
     * hundred and fifty games; without a ceiling, one scheduled job would fire
     * a hundred and fifty outbound requests inside a minute. That has taken a
     * site on this stack down before.
     */
    $provider = $espn('espn-summary-nfl.json');
    $league = $leagues->get('nfl');

    $provider->boxScore($league, 'same-game', 2026, 1);
    $provider->boxScore($league, 'same-game', 2026, 1);

    same(1, $provider->calls, 'the same game was fetched twice');

    for ($i = 0; $i < EspnProvider::MAX_SUMMARIES_PER_RUN + 10; $i++) {
        $provider->boxScore($league, 'game-' . $i, 2026, 1);
    }

    same(EspnProvider::MAX_SUMMARIES_PER_RUN, $provider->calls, 'the per-run ceiling did not hold');
};

$tests['the lead-in is read off the fixture, sentinels and all'] = function () {
    /*
     * 🚨 99 is the trap, and it is the only one here worth a test of its own.
     * Every competitor in the payload carries a `curatedRank`, and for two
     * thirds of the teams playing on a Saturday its value is 99 — the feed's
     * word for "outside the poll". Taken at face value it puts "#99" beside
     * most of the names on the board, which looks like a number being counted
     * and is not one.
     */
    same(0, EspnProvider::rank(['curatedRank' => ['current' => 99]]), '99 was not read as unranked');
    same(0, EspnProvider::rank([]), 'a competitor with no rank block was not unranked');
    same(0, EspnProvider::rank(['curatedRank' => ['current' => 0]]), 'zero was not unranked');
    same(12, EspnProvider::rank(['curatedRank' => ['current' => 12]]), 'a real rank was lost');
    same(0, EspnProvider::rank(['curatedRank' => ['current' => 26]]), 'a rank outside a 25-team poll was kept');

    /*
     * 🚨 Picked by TYPE. The array also carries home, road and conference
     * records, and taking the first would be right until the feed reordered
     * them — at which point every board would quietly show road records.
     */
    $records = ['records' => [
        ['name' => 'Home', 'type' => 'home', 'summary' => '2-0'],
        ['name' => 'overall', 'type' => 'total', 'summary' => '3-1'],
    ]];
    same('3-1', EspnProvider::record($records), 'the overall record was not the one taken');
    same('', EspnProvider::record([]), 'a competitor with no records invented one');

    /*
     * 🚨 The NATIONAL listing. A reader that took the first entry would tell
     * everybody on the board to watch a regional channel most of them cannot
     * receive.
     */
    $broadcasts = ['broadcasts' => [
        ['market' => 'home', 'names' => ['KTVT']],
        ['market' => 'national', 'names' => ['ABC']],
    ]];
    same('ABC', EspnProvider::broadcast($broadcasts), 'a regional listing beat the national one');
    same('KTVT', EspnProvider::broadcast(['broadcasts' => [['market' => 'home', 'names' => ['KTVT']]]]), 'a regional-only listing was dropped');
    same('', EspnProvider::broadcast([]), 'a game with no listing was given one');

    // 🚨 Composed from the parts that are there: an address with a city and no
    // state must not print "London, " with the comma hanging off it.
    same('College Station, TX', EspnProvider::venueCity(['address' => ['city' => 'College Station', 'state' => 'TX']]), 'city and state were not joined');
    same('London, England', EspnProvider::venueCity(['address' => ['city' => 'London', 'country' => 'England']]), 'country did not stand in for state');
    same('Dublin', EspnProvider::venueCity(['address' => ['city' => 'Dublin']]), 'a lone city gained a trailing comma');
    same('', EspnProvider::venueCity([]), 'an empty venue produced a location');
};

$tests['a fixture carries its lead-in through the adapter'] = function () use ($espn, $leagues) {
    $games = $espn('espn-scoreboard-nfl.json')->games($leagues->get('nfl'), 2026, 2);

    ok($games !== [], 'no games came back at all');

    foreach ($games as $game) {
        foreach (['home_rank', 'away_rank', 'home_record', 'away_record', 'venue', 'venue_city', 'broadcast'] as $key) {
            ok(array_key_exists($key, $game), 'the adapter dropped ' . $key . ' from the fixture');
        }

        // Whatever the feed said, a rank that reaches a row is a real one.
        ok($game['home_rank'] >= 0 && $game['home_rank'] <= 25, 'a sentinel rank reached the fixture', json_encode($game['home_rank']));
        ok($game['away_rank'] >= 0 && $game['away_rank'] <= 25, 'a sentinel rank reached the fixture', json_encode($game['away_rank']));
    }
};

$tests['a finished record has that game taken back out of it'] = function () {
    /*
     * 🚨 The feed's record for a past game INCLUDES that game. Ball State read
     * 0-1 on the day they lost their opener, not 0-0 — so a backfill that
     * stored it as sent would put the loss of the game you are looking at into
     * the record printed beside it, giving the result away above the result.
     */
    same('0-0', EspnProvider::recordBefore('0-1', false), 'a loss was not taken back out');
    same('0-0', EspnProvider::recordBefore('1-0', true), 'a win was not taken back out');
    same('3-1', EspnProvider::recordBefore('4-1', true), 'the wrong column was decremented');
    same('4-0', EspnProvider::recordBefore('4-1', false), 'the wrong column was decremented');

    // A ties column is carried through rather than parsed and rebuilt.
    same('2-1-1', EspnProvider::recordBefore('3-1-1', true), 'a ties column did not survive');

    /*
     * 🚨 It refuses rather than guesses. A board with no record on it reads as
     * a board that has not got one, which is true; a wrong record reads as a
     * fact.
     */
    same('', EspnProvider::recordBefore('0-0', true), 'a win was unwound out of a team that had not won one');
    same('', EspnProvider::recordBefore('0-0', false), 'a loss was unwound out of a team that had not lost one');
    same('', EspnProvider::recordBefore('', true), 'an empty record produced one');
    same('', EspnProvider::recordBefore('TBD', false), 'an unparseable record produced one');
};

$tests['no console command narrows a helper the base class already has'] = function () {
    /*
     * 🚨 This took the whole CLI down on a live board, silently, mid-season.
     *
     * `Flarum\Console\AbstractCommand` declares `info()` and `error()` as
     * PROTECTED. Redeclaring either as `private` in a subclass is a fatal at
     * CLASS-LOAD time — "access level must be protected or weaker" — and every
     * console command is loaded when the console boots. So one private helper
     * in one command does not break that command: it breaks `php flarum`
     * entirely, including `schedule:run`, which is what polls live scores.
     *
     * It is invisible from the web, because nothing there ever loads a console
     * class, and PHP writes a compile-time fatal to the error log rather than
     * to stdout — so the symptom is `php flarum list` printing NOTHING and
     * exiting 255. Nothing anywhere says why.
     *
     * A static check rather than reflection, because loading these classes
     * needs Flarum itself and this suite deliberately does not.
     */
    foreach (glob(__DIR__ . '/../src/Console/*.php') ?: [] as $file) {
        $source = (string) file_get_contents($file);

        foreach (['info', 'error'] as $helper) {
            ok(
                preg_match('/private\s+(static\s+)?function\s+' . $helper . '\s*\(/', $source) !== 1,
                basename($file) . ' declares ' . $helper . '() private, which the base class declares protected',
                'a private override of a protected method is a fatal at class-load time, and it takes the whole console with it'
            );
        }
    }
};

$tests['a league no provider covers is skipped, not thrown at'] = function () use ($espn) {
    $provider = $espn('espn-summary-nfl.json');
    $orphan = new League('orphan', 'Orphan', 'espn', '', 'gridiron', false);

    ok(!$provider->supports($orphan), 'an ESPN league with no path claimed support');
    same([], $provider->games($orphan, 2026), 'it tried to sync anyway');
    same(null, $provider->boxScore($orphan, '1', 2026), 'it tried to fetch anyway');
};

/*
 * Which week the board opens on (CurrentWeek::pick).
 *
 * 🚨 college-football.co.uk opens two weeks at a time and the board always
 * landed on the LATER one — next week, while this week was still being
 * played. Week 6 here is played on Saturday 3 October 2026; week 7 the
 * Saturday after. Kickoffs are UTC, as stored: 16:00Z is noon Eastern.
 */
$weekSix = static function (string $status) {
    return [
        ['2026-10-03 16:00:00', $status],
        ['2026-10-03 19:30:00', $status],
        ['2026-10-04 00:00:00', $status],   // Saturday 8pm Eastern
        ['2026-10-04 02:30:00', $status],   // the last, 10:30pm Eastern
    ];
};

$weekSeven = [
    ['2026-10-10 16:00:00', 'scheduled'],
    ['2026-10-11 00:00:00', 'scheduled'],
];

$board = static function (array ...$weeks): array {
    $rows = [];
    foreach ($weeks as [$id, $open, $games]) {
        $row = ['id' => $id, 'is_open' => $open, 'unfinished' => [], 'last_kickoff' => null];
        foreach ($games as [$kickoff, $status]) {
            if ($status !== 'finished') {
                $row['unfinished'][] = $kickoff;
            }
            if ($row['last_kickoff'] === null || $kickoff > $row['last_kickoff']) {
                $row['last_kickoff'] = $kickoff;
            }
        }
        $rows[] = $row;
    }

    return $rows;
};

$at = static fn (string $utc) => new DateTimeImmutable($utc, new DateTimeZone('UTC'));

$tests['two open weeks: Sunday midday is still this week, not next'] = function () use ($board, $weekSix, $weekSeven, $at) {
    $old = [['2026-09-26 16:00:00', 'finished']];

    // Sunday 4 October, 12:00 Eastern. Week 6 is final, the last game ended
    // around 2am — inside the day's grace.
    same(6, CurrentWeek::pick($board([5, false, $old], [6, true, $weekSix('finished')], [7, true, $weekSeven]), $at('2026-10-04 16:00:00')), 'Sunday midday ET jumped past week 6');

    // Saturday afternoon with games still to play.
    same(6, CurrentWeek::pick($board([6, true, $weekSix('scheduled')], [7, true, $weekSeven]), $at('2026-10-03 20:00:00')), 'game day itself jumped past week 6');

    // The following Tuesday, week 6 final: next week is now this week.
    same(7, CurrentWeek::pick($board([5, false, $old], [6, true, $weekSix('finished')], [7, true, $weekSeven]), $at('2026-10-06 16:00:00')), 'the Tuesday after did not move on to week 7');

    // A stale earlier week left open (week 5 here) is skipped, not landed on.
    same(6, CurrentWeek::pick($board([5, true, $old], [6, true, $weekSix('finished')], [7, true, $weekSeven]), $at('2026-10-04 16:00:00')), 'an old open week was chosen');
};

$tests['the rule falls back sensibly at the edges'] = function () use ($board, $weekSix, $at) {
    // Every open week complete and past its grace: the latest open week.
    same(7, CurrentWeek::pick($board([6, true, $weekSix('finished')], [7, true, [['2026-10-10 16:00:00', 'finished']]], [8, false, [['2026-10-17 16:00:00', 'scheduled']]]), $at('2026-10-14 12:00:00')), 'all-complete did not fall back to the latest open week');

    // Nothing open at all: the week being played, from every week.
    same(7, CurrentWeek::pick($board([6, false, $weekSix('finished')], [7, false, [['2026-10-10 16:00:00', 'scheduled']]], [8, false, [['2026-10-17 16:00:00', 'scheduled']]]), $at('2026-10-08 12:00:00')), 'with no week open it did not find the week being played');

    // A game the feed never finished (cancelled) must not pin the board for ever.
    $stuck = $weekSix('finished');
    $stuck[0][1] = 'scheduled';
    same(7, CurrentWeek::pick($board([6, true, $stuck], [7, true, [['2026-10-10 16:00:00', 'scheduled']]]), $at('2026-10-08 12:00:00')), 'a never-reported game held the board on a finished week');

    // ...but a game under way late into the night keeps its week current.
    same(6, CurrentWeek::pick($board([6, true, [['2026-10-04 03:30:00', 'in_progress']]], [7, true, []]), $at('2026-10-04 06:00:00')), 'an in-progress game lost its week');

    same(null, CurrentWeek::pick([], $at('2026-10-04 16:00:00')), 'no weeks should give no week');
};

/*
 * Auto-unlock (CurrentWeek::weekToUnlock). Week 5 played Saturday 3 October
 * 2026; week 6 the Saturday after. Each game is [kickoff UTC, finished].
 */
$season = static function (array $five, bool $sixOpen = false): array {
    return [
        ['id' => 4, 'is_open' => false, 'games' => [['2026-09-26 16:00:00', true]]],
        ['id' => 5, 'is_open' => true, 'games' => $five],
        ['id' => 6, 'is_open' => $sixOpen, 'games' => [['2026-10-10 16:00:00', false], ['2026-10-11 04:00:00', false]]],
        ['id' => 7, 'is_open' => false, 'games' => [['2026-10-17 16:00:00', false]]],
    ];
};

$tests['auto-unlock: a complete week opens the next, an unfinished one does not'] = function () use ($season, $at) {
    $sunday = $at('2026-10-04 16:00:00');

    // Week 5 all final: week 6 opens.
    same(6, CurrentWeek::weekToUnlock($season([['2026-10-03 16:00:00', true], ['2026-10-04 02:30:00', true]]), $sunday), 'an all-final week 5 did not open week 6');

    // One game postponed two days ago, never finalised: week 6 still opens.
    same(6, CurrentWeek::weekToUnlock($season([['2026-10-02 12:00:00', false], ['2026-10-04 02:30:00', true]]), $sunday), 'a postponed game kept week 6 shut');

    // A game still to play (Sunday evening): nothing opens.
    same(null, CurrentWeek::weekToUnlock($season([['2026-10-03 16:00:00', true], ['2026-10-04 23:00:00', false]]), $sunday), 'week 6 opened with a week 5 game still to play');

    // A game kicked off last night and not yet final (inside 36h): nothing opens.
    same(null, CurrentWeek::weekToUnlock($season([['2026-10-04 02:30:00', false]]), $sunday), 'a game still being reported was written off too soon');

    // The next week already open (a board that opens two at a time): it is the
    // latest open week, so nothing more opens until IT is complete.
    same(null, CurrentWeek::weekToUnlock($season([['2026-10-03 16:00:00', true]], true), $sunday), 'it opened past a week already open');

    // An unannounced kickoff far ahead (the 04:00Z placeholder) is never "over".
    ok(!CurrentWeek::gameIsDone('2026-10-11 04:00:00', false, $sunday), 'a future placeholder kickoff counted as done');
};

$tests['auto-unlock: switched on mid-season, it opens the week being played'] = function () use ($at) {
    $weeks = [
        ['id' => 1, 'is_open' => true, 'games' => [['2026-09-05 16:00:00', true]]],
        ['id' => 2, 'is_open' => false, 'games' => [['2026-09-12 16:00:00', true]]],
        ['id' => 3, 'is_open' => false, 'games' => []],
        ['id' => 4, 'is_open' => false, 'games' => [['2026-09-26 16:00:00', false]]],   // stuck, long over
        ['id' => 6, 'is_open' => false, 'games' => [['2026-10-10 16:00:00', false]]],
    ];
    same(6, CurrentWeek::weekToUnlock($weeks, $at('2026-10-04 16:00:00')), 'it did not step over finished and empty weeks to the week being played');

    // No open week at all: the first week is always opened by hand.
    $weeks[0]['is_open'] = false;
    same(null, CurrentWeek::weekToUnlock($weeks, $at('2026-10-04 16:00:00')), 'it opened a week with none open');

    // An empty open week is not "complete" — an unsynced schedule must not cascade.
    same(null, CurrentWeek::weekToUnlock([['id' => 1, 'is_open' => true, 'games' => []], ['id' => 2, 'is_open' => false, 'games' => [['2026-10-10 16:00:00', false]]]], $at('2026-10-04 16:00:00')), 'an empty open week opened the next');
};

$tests['auto-unlock and the board agree'] = function () use ($board, $at) {
    // Week 5 final at ~2am Sunday; auto-unlock opens week 6 at once, but the
    // board stays on week 5 for the day of results, then moves.
    $games = [['2026-10-04 02:30:00', 'finished']];
    $six = [['2026-10-10 16:00:00', 'scheduled']];
    same(5, CurrentWeek::pick($board([5, true, $games], [6, true, $six]), $at('2026-10-04 16:00:00')), 'the board jumped to the newly opened week');
    same(6, CurrentWeek::pick($board([5, true, $games], [6, true, $six]), $at('2026-10-05 12:00:00')), 'the board did not move on the next day');

    // A postponed game: unlock and board both let go at kickoff + 36h.
    $stuck = [['2026-10-03 16:00:00', 'scheduled']];
    same(5, CurrentWeek::pick($board([5, true, $stuck], [6, true, $six]), $at('2026-10-05 03:00:00')), 'the board left a week still inside 36h');
    same(6, CurrentWeek::pick($board([5, true, $stuck], [6, true, $six]), $at('2026-10-05 05:00:00')), 'the board held a week past 36h');
};


/* ------------------------------------------------------- the Confidence contest */

/*
 * A game for the selector, defaulting to a plain unranked Saturday kickoff that
 * is still open on the Sunday the tests run at.
 */
$cgame = static function (int $id, array $over = []): array {
    return $over + [
        'id' => $id, 'status' => 'scheduled', 'cutoff' => '2026-10-10 16:00:00', 'match_date' => '2026-10-10 16:00:00',
        'home_rank' => 0, 'away_rank' => 0, 'home_record' => '2-2', 'away_record' => '2-2', 'broadcast' => '', 'time_tbd' => false,
    ];
};

$tests['confidence: two ranked sides, then one, then the best records'] = function () use ($cgame, $at) {
    // Measured week 6 of 2026 on the demo, cut down to the cases that matter.
    $games = [
        $cgame(1, ['home_record' => '5-0', 'away_record' => '4-0', 'broadcast' => 'FOX']),           // unranked, unbeaten
        $cgame(2, ['home_rank' => 7, 'away_rank' => 2]),                                              // #7 v #2  = 9
        $cgame(3, ['home_rank' => 24, 'away_rank' => 11]),                                            // #24 v #11 = 35
        $cgame(4, ['away_rank' => 1]),                                                                // #1 alone
        $cgame(5, ['home_rank' => 15, 'away_rank' => 23]),                                            // = 38
        $cgame(6, ['home_rank' => 3]),                                                                // #3 alone
        $cgame(7, ['home_record' => '1-4', 'away_record' => '0-5', 'broadcast' => 'ABC']),            // unranked, poor
    ];

    same([2, 3, 5, 4, 6, 1, 7], Selector::order($games, $at('2026-10-04 16:00:00')), 'the biggest games did not come first');
    same([2, 3, 5], Selector::choose($games, 3, $at('2026-10-04 16:00:00')), 'choose() did not take the top of the order');
};

$tests['confidence: a rank of 0 is unranked, never #0'] = function () use ($cgame, $at) {
    $games = [$cgame(1, ['home_rank' => 0, 'away_rank' => 0]), $cgame(2, ['home_rank' => 25])];
    same([2, 1], Selector::order($games, $at('2026-10-04 16:00:00')), 'a 0 rank sorted as the best rank in the country');
    same(null, Selector::rank(0), 'rank 0 read as a rank');
    same(null, Selector::rank(null), 'a null rank read as a rank');
};

$tests['confidence: a started or locked game is never chosen'] = function () use ($cgame, $at) {
    $games = [
        $cgame(1, ['home_rank' => 1, 'away_rank' => 2, 'status' => 'in_progress']),
        $cgame(2, ['home_rank' => 3, 'away_rank' => 4, 'cutoff' => '2026-10-04 12:00:00']),   // locked this morning
        $cgame(3, ['home_rank' => 5, 'away_rank' => 6, 'status' => 'finished']),
        $cgame(4),
    ];
    same([4], Selector::order($games, $at('2026-10-04 16:00:00')), 'a game that had started or locked was chosen');
};

$tests['confidence: inside a tier, the bigger broadcast then primetime break the tie'] = function () use ($cgame, $at) {
    $games = [
        $cgame(1, ['broadcast' => 'ESPN+']),
        $cgame(2, ['broadcast' => 'CBS']),
        $cgame(3, ['broadcast' => 'SEC Network']),
        // Same network as 2, at 7:30pm Eastern.
        $cgame(4, ['broadcast' => 'CBS', 'match_date' => '2026-10-10 23:30:00', 'cutoff' => '2026-10-10 23:30:00']),
    ];
    same([4, 2, 3, 1], Selector::order($games, $at('2026-10-04 16:00:00')), 'the tie-breaks inside a tier were wrong');

    // 🚨 The 04:00Z placeholder of an unannounced kickoff is midnight Eastern —
    // it must not count as primetime.
    ok(!Selector::isPrimetime($cgame(9, ['match_date' => '2026-10-10 04:00:00', 'time_tbd' => true])), 'an unannounced kickoff counted as primetime');
    same(0.5, Selector::winPct(null), 'a missing record was not neutral');
    same(0.8, Selector::winPct('4-1'), 'a 4-1 record was misread');
};

$tests['confidence: saving checks values are unique and in range'] = function () {
    $sel = [11, 12, 13];
    $pick = fn (int $e, ?string $o, $c) => ['event_id' => $e, 'selected_outcome' => $o, 'confidence' => $c];

    $r = Rules::validate(3, $sel, [], [], [$pick(11, 'home', 3), $pick(12, 'away', 1)]);
    same(null, $r['error'], 'a good save was refused');
    same([11 => ['outcome' => 'home', 'confidence' => 3], 12 => ['outcome' => 'away', 'confidence' => 1]], $r['write'], 'the wrong rows were written');

    same(Rules::DUPLICATE_VALUE, Rules::validate(3, $sel, [], [], [$pick(11, 'home', 2), $pick(12, 'away', 2)])['error'], 'two games holding 2 were accepted');
    same(Rules::OUT_OF_RANGE, Rules::validate(3, $sel, [], [], [$pick(11, 'home', 4)])['error'], 'a value above N was accepted');
    same(Rules::OUT_OF_RANGE, Rules::validate(3, $sel, [], [], [$pick(11, 'home', 0)])['error'], 'a value of 0 was accepted');
    same(Rules::OUT_OF_RANGE, Rules::validate(3, $sel, [], [], [$pick(11, 'home', 1.5)])['error'], 'a fractional value was accepted');
    same(Rules::NOT_IN_CONTEST, Rules::validate(3, $sel, [], [], [$pick(99, 'home', 1)])['error'], 'a game outside the contest was accepted');
    same(Rules::BAD_OUTCOME, Rules::validate(3, $sel, [], [], [$pick(11, 'draw', 1)])['error'], 'an outcome other than home/away was accepted');
    same(Rules::DUPLICATE_GAME, Rules::validate(3, $sel, [], [], [$pick(11, 'home', 1), $pick(11, 'away', 2)])['error'], 'one game sent twice was accepted');

    // No winner chosen = no pick on that game, and its value is not held.
    $r = Rules::validate(3, $sel, [], [], [$pick(11, null, 3), $pick(12, 'home', 3)]);
    same(null, $r['error'], 'an unpicked game held its value');
    same([12], array_keys($r['write']), 'an unpicked game was written');
};

$tests['confidence: a locked pick keeps its value, the rest reshuffle around it'] = function () {
    $sel = [11, 12, 13];
    $pick = fn (int $e, ?string $o, $c) => ['event_id' => $e, 'selected_outcome' => $o, 'confidence' => $c];
    $existing = [11 => ['outcome' => 'home', 'confidence' => 3], 12 => ['outcome' => 'away', 'confidence' => 2], 13 => ['outcome' => 'home', 'confidence' => 1]];

    // 11 has kicked off. Swapping 12 and 13 is fine.
    $r = Rules::validate(3, $sel, [11], $existing, [$pick(11, 'home', 3), $pick(12, 'away', 1), $pick(13, 'home', 2)]);
    same(null, $r['error'], 'reshuffling unlocked games was refused');
    same([12 => ['outcome' => 'away', 'confidence' => 1], 13 => ['outcome' => 'home', 'confidence' => 2]], $r['write'], 'the locked pick was rewritten, or the unlocked ones were not');

    // Leaving the locked game out of the save is fine too: it is kept, not dropped.
    same(null, Rules::validate(3, $sel, [11], $existing, [$pick(12, 'away', 2), $pick(13, 'home', 1)])['error'], 'a save without the locked game was refused');

    // Taking the locked game's value is refused...
    same(Rules::DUPLICATE_VALUE, Rules::validate(3, $sel, [11], $existing, [$pick(12, 'away', 3)])['error'], 'an unlocked game took a locked game\'s value');
    // ...and so is changing the locked pick's winner or value.
    same(Rules::LOCKED, Rules::validate(3, $sel, [11], $existing, [$pick(11, 'away', 3)])['error'], 'a locked winner was changed');
    same(Rules::LOCKED, Rules::validate(3, $sel, [11], $existing, [$pick(11, 'home', 1)])['error'], 'a locked value was changed');
    // A locked game nobody picked cannot be picked late.
    same(Rules::LOCKED, Rules::validate(3, $sel, [11], [], [$pick(11, 'home', 3)])['error'], 'a pick was made on a locked game');

    same(null, Rules::tiebreaker('51')['error'], 'a tiebreaker of 51 was refused');
    same(51, Rules::tiebreaker('51')['value'], 'a tiebreaker of 51 was misread');
    same(Rules::BAD_TIEBREAKER, Rules::tiebreaker(-3)['error'], 'a negative tiebreaker was accepted');
    same(Rules::BAD_TIEBREAKER, Rules::tiebreaker('lots')['error'], 'a tiebreaker of text was accepted');
    same(null, Rules::tiebreaker(null)['value'], 'clearing the tiebreaker did not clear it');
};

$tests['confidence: scoring with and without a penalty'] = function () {
    $picks = [
        ['is_correct' => true, 'confidence' => 10],
        ['is_correct' => false, 'confidence' => 9],
        ['is_correct' => true, 'confidence' => 4],
        ['is_correct' => false, 'confidence' => 3],
        ['is_correct' => null, 'confidence' => 1],      // not decided yet: counts for nothing
    ];

    same(['points' => 14, 'picks' => 4, 'correct' => 2, 'accuracy' => 50.0], Scoring::total($picks, 'none'), 'no-penalty scoring was wrong');
    same(['points' => 9, 'picks' => 4, 'correct' => 2, 'accuracy' => 50.0], Scoring::total($picks, 'half'), 'half-penalty scoring was wrong (9/2=4, 3/2=1)');
    same(['points' => 2, 'picks' => 4, 'correct' => 2, 'accuracy' => 50.0], Scoring::total($picks, 'full'), 'full-penalty scoring was wrong');

    // 🚨 Not floored: a bad week with a full penalty is a negative week.
    same(-7, Scoring::total([['is_correct' => false, 'confidence' => 7]], 'full')['points'], 'a negative week was floored');
};

$tests['confidence: the closest tiebreaker wins a tie on points'] = function () {
    same(4, Scoring::tiebreakDiff(51, 24, 31), 'a guess of 51 for 24-31 is 4 out');
    same(null, Scoring::tiebreakDiff(51, null, null), 'a guess was scored before the game finished');
    same(null, Scoring::tiebreakDiff(null, 24, 31), 'no guess was scored as a guess');

    $ranked = Scoring::rank([
        ['user_id' => 1, 'points' => 40, 'correct' => 7, 'diff' => 12],
        ['user_id' => 2, 'points' => 40, 'correct' => 6, 'diff' => 3],     // same points, closer guess: ahead
        ['user_id' => 3, 'points' => 40, 'correct' => 8, 'diff' => null],  // no guess: behind any guess
        ['user_id' => 4, 'points' => 41, 'correct' => 5, 'diff' => 30],    // more points beats any guess
        ['user_id' => 5, 'points' => 40, 'correct' => 9, 'diff' => 3],     // same guess as 2: more correct picks
    ]);
    same([4, 5, 2, 1, 3], array_column($ranked, 'user_id'), 'the standings were not points, then guess, then correct picks');
};

$tests['confidence: all time is the sum of the seasons'] = function () {
    $lines = Scoring::combine([
        // Member 1: two seasons, one of them negative under a full penalty.
        ['user_id' => 1, 'points' => 60, 'picks' => 20, 'correct' => 15, 'diff' => 4],
        ['user_id' => 1, 'points' => -5, 'picks' => 10, 'correct' => 3, 'diff' => null],
        // Member 2: one big season.
        ['user_id' => 2, 'points' => 55, 'picks' => 10, 'correct' => 9, 'diff' => 2],
        // Member 3: the same 55, two seasons, both with a guess.
        ['user_id' => 3, 'points' => 30, 'picks' => 10, 'correct' => 6, 'diff' => 1],
        ['user_id' => 3, 'points' => 25, 'picks' => 10, 'correct' => 5, 'diff' => 5],
        // Member 4: a row with nothing scored is not a line at all.
        ['user_id' => 4, 'points' => 0, 'picks' => 0, 'correct' => 0, 'diff' => null],
    ]);

    // All three end on 55: the summed guess distance (2, 4, 6) decides it.
    same([2, 1, 3], array_column($lines, 'user_id'), 'all time was not ranked on summed points, then summed guess distance');

    $one = $lines[1];
    same(55, $one['points'], 'a negative season was not subtracted from all time');
    same(30, $one['picks'], 'all-time picks were not summed');
    same(18, $one['correct'], 'all-time correct picks were not summed');
    same(60.0, $one['accuracy'], 'all-time accuracy was not worked out from the summed counts');
    same(4, $one['diff'], 'a season with no guess changed the all-time guess distance');

    same(6, $lines[2]['diff'], 'guess distances were not summed across seasons');
    same(55.0, $lines[2]['accuracy'], 'accuracy averaged percentages instead of counting picks');

    same([], Scoring::combine([]), 'nobody played, yet all time had lines');
};

/* ------------------------------------------------------------------ the runner */

foreach ($tests as $name => $test) {
    $before = count($failures);
    $test();

    if (count($failures) === $before) {
        $passed++;
        echo "  ok   " . $name . "\n";
        continue;
    }

    echo "  FAIL " . $name . "\n";

    foreach (array_slice($failures, $before) as $failure) {
        echo "       " . $failure . "\n";
    }
}

echo "\n" . $passed . '/' . count($tests) . " passed\n";

exit($failures === [] ? 0 : 1);
