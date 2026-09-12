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
