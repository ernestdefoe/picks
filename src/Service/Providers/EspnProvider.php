<?php

namespace Resofire\Picks\Service\Providers;

use GuzzleHttp\Client as HttpClient;
use Resofire\Picks\Service\Leagues\League;
use RuntimeException;

/**
 * ESPN — the NFL, the NBA, MLB, the NHL and league football, in one shape.
 *
 * 🚨 The single most useful fact about this API: every sport answers the same
 * two endpoints with the same envelope. A scoreboard is
 * `{events: [{id, date, competitions: [{competitors: [{homeAway, score, team}], status}]}]}`
 * whether it is the Premier League or MLB, which is why one adapter covers
 * every league here and why adding another is a line in the registry.
 *
 * 🚨 It needs NO API KEY, which is the other reason it is the multi-sport
 * backbone. CollegeFootballData stays where it is used because it is richer for
 * college football, not because ESPN could not answer.
 *
 * Everything below was read off live responses in September 2026 rather than
 * from documentation, because ESPN publishes none for this.
 */
class EspnProvider implements Provider
{
    protected const BASE = 'https://site.api.espn.com/apis/site/v2/sports';
    protected const TIMEOUT = 20;

    /**
     * 🚨 A hard ceiling on summary fetches per run, because a box score here is
     * ONE CALL PER GAME — unlike CollegeFootballData, which answers a whole
     * week at once. A full MLB day is fifteen games and a Saturday of college
     * basketball is a hundred and fifty; without this, a single scheduled job
     * would fire a hundred and fifty outbound requests inside a minute. That
     * has caused a real outage on this stack before.
     *
     * Whatever is not fetched this run is fetched the next one. A box score
     * arriving an hour later is invisible; a queue worker taken out by its own
     * traffic is not.
     */
    public const MAX_SUMMARIES_PER_RUN = 25;

    /** @var array<string, array<string, mixed>> summary responses already fetched this process */
    protected array $summaries = [];

    protected int $fetched = 0;

    public function __construct(protected HttpClient $http)
    {
    }

    public function key(): string
    {
        return 'espn';
    }

    public function supports(League $league): bool
    {
        return $league->espnPath !== '';
    }

    public function games(League $league, int $year, ?int $week = null, string $seasonType = 'regular'): array
    {
        if (!$this->supports($league)) {
            return [];
        }

        $params = ['limit' => 1000];

        /*
         * 🚨 Weeks and dates are not interchangeable, and asking for the wrong
         * one returns TODAY rather than an error. ESPN understands `week` for
         * the sports that have them and silently ignores it for the rest — so a
         * basketball season asked for "week 3" would answer with tonight's
         * games, and the sync would happily store them as week 3.
         */
        if ($league->hasWeeks && $week !== null) {
            $params['week'] = $week;
            $params['seasontype'] = $seasonType === 'postseason' ? 3 : 2;
            $params['dates'] = $year;
        } else {
            $params['dates'] = $year;
        }

        $response = $this->get($league->espnPath . '/scoreboard', $params);

        $games = [];

        foreach ((array) ($response['events'] ?? []) as $event) {
            $game = $this->game($event, $league);

            if ($game !== null) {
                $games[] = $game;
            }
        }

        return $games;
    }

    public function boxScore(League $league, string $externalId, int $year, ?int $week = null, string $seasonType = 'regular'): ?array
    {
        if (!$this->supports($league) || $externalId === '') {
            return null;
        }

        if ($this->fetched >= self::MAX_SUMMARIES_PER_RUN && !isset($this->summaries[$externalId])) {
            return null;
        }

        $summary = $this->summary($league, $externalId);

        if ($summary === null) {
            return null;
        }

        $box = (array) ($summary['boxscore'] ?? []);
        $teams = $this->teamSides((array) ($box['teams'] ?? []));

        // No team statistics is no box score. Half of one is worse than none.
        if (count($teams) < 2) {
            return null;
        }

        return [
            'teams' => $teams,
            'players' => $this->playerSides((array) ($box['players'] ?? []), (array) ($box['teams'] ?? [])),
        ];
    }

    /* ------------------------------------------------------------- fixtures */

    /** @return array<string, mixed>|null */
    protected function game(array $event, League $league): ?array
    {
        $competition = (array) (($event['competitions'] ?? [[]])[0] ?? []);
        $competitors = (array) ($competition['competitors'] ?? []);

        $home = null;
        $away = null;

        foreach ($competitors as $side) {
            if (($side['homeAway'] ?? '') === 'home') {
                $home = $side;
            } elseif (($side['homeAway'] ?? '') === 'away') {
                $away = $side;
            }
        }

        if ($home === null || $away === null) {
            return null;
        }

        $status = (array) (($competition['status'] ?? $event['status'] ?? [])['type'] ?? []);

        /*
         * Who has the ball, as OUR word for a side rather than ESPN's id for a
         * team.
         *
         * 🚨 `situation.possession` is an ESPN team id, and nothing in this
         * database is keyed by one. Resolving it here — against the two
         * competitors already in hand — means everything downstream stores
         * "home" or "away", which is what a scoreboard needs and cannot drift
         * out of step with anybody's team table.
         */
        $situation = (array) ($competition['situation'] ?? []);
        $hasBall = (string) ($situation['possession'] ?? '');
        $possession = '';

        if ($hasBall !== '') {
            foreach ([['home', $home], ['away', $away]] as [$side, $competitor]) {
                if ((string) (($competitor['team'] ?? [])['id'] ?? $competitor['id'] ?? '') === $hasBall) {
                    $possession = $side;
                }
            }
        }

        /*
         * The clock, for a scoreboard that means to look like one.
         *
         * 🚨 Period is safe to show and the clock is not, on its own: a quarter
         * lasts fifteen minutes and a game clock moves every second, so a
         * number fetched a minute ago is a lie by the time it is read. Both are
         * carried along with WHEN they were true, and whatever draws them
         * decides what is still worth showing.
         */
        $clock = (array) ($competition['status'] ?? $event['status'] ?? []);

        /*
         * 🚨 Finished is read from `completed` and `state`, NEVER from the
         * status name. Soccer's finished game is `STATUS_FULL_TIME`, baseball's
         * is `STATUS_FINAL`, and a match settled on penalties is something else
         * again — matching on the name works for the sport it was written
         * against and silently leaves every other league's games permanently
         * "in progress".
         */
        $completed = (bool) ($status['completed'] ?? false) || ($status['state'] ?? '') === 'post';

        return [
            'external_id' => (string) ($event['id'] ?? ''),
            'week' => $league->hasWeeks ? $this->weekNumber($event) : null,
            'season_type' => ((int) (($event['season'] ?? [])['type'] ?? 2)) === 3 ? 'postseason' : 'regular',
            'start' => (string) ($event['date'] ?? ''),
            'home' => (string) (($home['team'] ?? [])['displayName'] ?? ''),
            'away' => (string) (($away['team'] ?? [])['displayName'] ?? ''),
            /*
             * 🚨 The crest comes back with the FIXTURE, and taking it here is
             * what saves a second endpoint entirely. A pick'em whose teams have
             * no logo is a board of grey squares — the first version of this
             * shipped exactly that, and it looked broken rather than unfinished.
             */
            'home_team' => $this->club($home),
            'away_team' => $this->club($away),
            'home_score' => isset($home['score']) ? (int) $home['score'] : null,
            'away_score' => isset($away['score']) ? (int) $away['score'] : null,
            'completed' => $completed,
            'status' => (string) ($status['state'] ?? 'pre'),
            'neutral_site' => (bool) ($competition['neutralSite'] ?? false),

            /* --------------------------------------------------- the lead-in */

            /*
             * 🚨 Taken from the FIXTURE, which is what makes it free and what
             * makes it right. Free because this payload is already in hand;
             * right because a rank belongs to the week the game was played in,
             * and asking a poll for it later would answer with today's.
             */
            'home_rank' => self::rank($home),
            'away_rank' => self::rank($away),
            'home_record' => self::record($home),
            'away_record' => self::record($away),
            'venue' => trim((string) (((array) ($competition['venue'] ?? []))['fullName'] ?? '')),
            'venue_city' => self::venueCity((array) ($competition['venue'] ?? [])),
            'broadcast' => self::broadcast($competition),

            /* ------------------------------------------------- live state */
            'period' => (int) ($clock['period'] ?? 0),
            'clock' => trim((string) ($clock['displayClock'] ?? '')),
            // ESPN's own words, which beat anything built here from a number:
            // it already knows what a period means in a game gone to overtime.
            'clock_detail' => trim((string) ($status['shortDetail'] ?? $status['detail'] ?? '')),
            'possession' => $possession,
            /*
             * The short form. "2nd & 10" is what belongs on a scoreboard;
             * "2nd & 10 at TCU 45" is a sentence, and the yard line is already
             * the least durable thing on the strip.
             */
            'down_distance' => self::downAndDistance($situation),
            'ball_on' => trim((string) ($situation['possessionText'] ?? '')),
            'red_zone' => ! empty($situation['isRedZone']),
        ];
    }

    /**
     * "2nd & 10", from whichever the feed happened to send.
     *
     * 🚨 The text is not always there. ESPN's situation block changes shape
     * through a game — between possessions it can carry `down` and `distance`
     * as bare numbers with no sentence built from them, and after a score it
     * carries `down = 0`, which means there is no down rather than a zeroth one.
     *
     * @param array<string, mixed> $situation
     */
    public static function downAndDistance(array $situation): string
    {
        $text = trim((string) ($situation['shortDownDistanceText'] ?? ''));

        /*
         * 🚨 A negative distance is refused, not printed.
         *
         * Caught live on the Convoro board: ESPN sent "4th & -1" for a minute
         * either side of the half and it went straight onto the scoreboard. A
         * board reading "4th & -1" is not a board with a small mistake on it,
         * it is one nobody trusts again — and a feed briefly disagreeing with
         * itself is an ordinary event, not an exceptional one.
         *
         * Showing nothing is the honest answer meanwhile; the score and clock
         * beside it are unaffected.
         */
        if ($text !== '' && ! preg_match('/-\s*\d/', $text)) {
            return $text;
        }

        $down = (int) ($situation['down'] ?? 0);

        if ($down < 1 || $down > 4) {
            return '';
        }

        $distance = (int) ($situation['distance'] ?? 0);
        $ordinal = [1 => '1st', 2 => '2nd', 3 => '3rd', 4 => '4th'][$down];

        // "& Goal" is what a scoreboard says when the distance IS the end zone.
        return $distance > 0 ? $ordinal.' & '.$distance : $ordinal.' & Goal';
    }

    /**
     * Where a team sits in the poll, or 0 for outside it.
     *
     * 🚨 99 IS THE FEED'S WORD FOR UNRANKED, and it is the only trap in this
     * whole block. Every competitor carries a `curatedRank`, so a reader that
     * takes the number at face value puts "#99" beside two thirds of the teams
     * playing on any given Saturday — a board that looks like it is counting
     * something and is not. It is turned into 0 here, once, so nothing
     * downstream has to know that 99 ever meant anything.
     *
     * 🚨 "Curated" rather than AP, and the distinction is ESPN's rather than
     * ours: in college football it is the AP poll until the selection committee
     * publishes, and the committee's ranking after that. Which is exactly what
     * a scoreboard should show — once the CFP rankings exist, they are the ones
     * every broadcast puts beside a team's name.
     *
     * @param array<string, mixed> $side
     */
    public static function rank(array $side): int
    {
        /*
         * 🚨 The lookup is bracketed, not cast-then-defaulted. `(int) $a['k'] ?? 0`
         * binds the CAST first, so a missing key warns and is only then
         * defaulted — a notice in the log on every fixture the feed sends
         * without a rank block, which is most of them out of season.
         */
        $curated = (array) ($side['curatedRank'] ?? []);
        $rank = (int) ($curated['current'] ?? 0);

        // Anything outside a 25-team poll is unranked, whatever number the feed
        // used to say so.
        return $rank > 0 && $rank <= 25 ? $rank : 0;
    }

    /**
     * A team's record — "2-0", "7-4-1" — as the feed writes it.
     *
     * 🚨 The OVERALL one, picked by type rather than by position. The array also
     * carries home, road and conference records, and taking the first would be
     * right until the day the feed reordered them and every board quietly began
     * showing road records instead.
     *
     * @param array<string, mixed> $side
     */
    public static function record(array $side): string
    {
        foreach ((array) ($side['records'] ?? []) as $record) {
            if (is_array($record) && (($record['type'] ?? '') === 'total' || ($record['name'] ?? '') === 'overall')) {
                return trim((string) ($record['summary'] ?? ''));
            }
        }

        return '';
    }

    /**
     * "College Station, TX" — or as much of it as the feed sent.
     *
     * 🚨 Composed from the parts that are actually there. The address block
     * routinely arrives with a city and no state (and, abroad, a country
     * instead), so a format string would print "London, " with a comma hanging
     * off the end of it on a fixture that is not missing anything at all.
     *
     * @param array<string, mixed> $venue
     */
    public static function venueCity(array $venue): string
    {
        $address = (array) ($venue['address'] ?? []);

        $parts = array_values(array_filter([
            trim((string) ($address['city'] ?? '')),
            trim((string) ($address['state'] ?? $address['country'] ?? '')),
        ], fn (string $part): bool => $part !== ''));

        return implode(', ', $parts);
    }

    /**
     * Who is showing it.
     *
     * 🚨 The NATIONAL listing, where there is one. `broadcasts` also carries
     * per-market entries — the home team's regional channel, the away team's —
     * and a reader that took the first would tell everybody on the board to
     * watch a station most of them cannot receive.
     *
     * @param array<string, mixed> $competition
     */
    public static function broadcast(array $competition): string
    {
        $names = [];

        foreach ((array) ($competition['broadcasts'] ?? []) as $broadcast) {
            if (! is_array($broadcast)) {
                continue;
            }

            $name = trim((string) (((array) ($broadcast['names'] ?? []))[0] ?? ''));

            if ($name === '') {
                continue;
            }

            if (($broadcast['market'] ?? '') === 'national') {
                return $name;
            }

            $names[] = $name;
        }

        // No national listing: the first regional one is better than silence,
        // and on a board built around one team it is usually the right channel.
        return $names[0] ?? '';
    }

    /**
     * The club itself, as much of it as a scoreboard carries.
     *
     * @param  array<string, mixed> $side
     * @return array{external_id: string, name: string, abbreviation: string, logo: string, color: string}
     */
    protected function club(array $side): array
    {
        $team = is_array($side['team'] ?? null) ? $side['team'] : [];

        return [
            'external_id' => (string) ($team['id'] ?? ''),
            'name' => (string) ($team['displayName'] ?? ''),
            'abbreviation' => (string) ($team['abbreviation'] ?? ''),
            'logo' => (string) ($team['logo'] ?? ''),
            'color' => (string) ($team['color'] ?? ''),
        ];
    }

    protected function weekNumber(array $event): ?int
    {
        $week = $event['week'] ?? null;

        if (is_array($week) && isset($week['number'])) {
            return (int) $week['number'];
        }

        return is_numeric($week) ? (int) $week : null;
    }

    /* ----------------------------------------------------------- box scores */

    /**
     * A team's statistics, flattened onto the shape CFBD answers:
     * `[{homeAway, team, points, stats: [{category, stat}]}]`.
     *
     * @return list<array<string, mixed>>
     */
    protected function teamSides(array $teams): array
    {
        $out = [];

        foreach ($teams as $side) {
            if (!is_array($side)) {
                continue;
            }

            $out[] = [
                'homeAway' => (string) ($side['homeAway'] ?? 'home'),
                'team' => (string) (($side['team'] ?? [])['displayName'] ?? ''),
                'points' => null,
                'stats' => $this->flatten((array) ($side['statistics'] ?? [])),
            ];
        }

        return $out;
    }

    /**
     * 🚨 ESPN answers team statistics in TWO different shapes and it is not
     * documented which sport uses which.
     *
     * Football, basketball and hockey answer a flat list of
     * `{name, displayValue}`. Baseball answers GROUPS — batting, pitching,
     * fielding — each with its own `stats[]` and no `displayValue` of its own.
     *
     * The grouped ones are prefixed, and that is not cosmetic: `hits` appears
     * in all three baseball groups meaning hits made, hits allowed, and hits
     * handled in the field. Flattening onto bare names would keep whichever
     * came last and print a pitcher's line as the batting figure — a number
     * that looks entirely plausible and is about somebody else.
     *
     * @return list<array{category: string, stat: string}>
     */
    protected function flatten(array $statistics, string $prefix = ''): array
    {
        $out = [];

        foreach ($statistics as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $name = (string) ($entry['name'] ?? '');

            if ($name === '') {
                continue;
            }

            if (isset($entry['stats']) && is_array($entry['stats'])) {
                foreach ($this->flatten($entry['stats'], $prefix . $name . '.') as $nested) {
                    $out[] = $nested;
                }

                continue;
            }

            $out[] = [
                'category' => $prefix . $name,
                'stat' => (string) ($entry['displayValue'] ?? ''),
            ];
        }

        return $out;
    }

    /**
     * Players, pivoted onto CFBD's shape:
     * `[{homeAway, categories: [{name, types: [{name, athletes: [{name, stat}]}]}]}]`.
     *
     * 🚨 ESPN's player box score is PARALLEL ARRAYS — a group carries `labels[]`
     * and every athlete carries `stats[]` in the same order, with nothing tying
     * a figure to its label except position. Zipping them here, once, is the
     * whole job; every reader downstream would otherwise zip them again and one
     * of them would eventually get the offset wrong.
     *
     * 🚨 And the group's own name lives in a DIFFERENT FIELD per sport. The NFL
     * puts it in `name` ("passing"), baseball puts it in `type` ("batting"),
     * hockey uses `name` ("skaters"), and basketball supplies neither because
     * it has only one group. All four were read off live responses.
     *
     * @return list<array<string, mixed>>
     */
    protected function playerSides(array $players, array $teams): array
    {
        $out = [];

        foreach ($players as $side) {
            if (!is_array($side)) {
                continue;
            }

            $categories = [];

            foreach ((array) ($side['statistics'] ?? []) as $group) {
                if (!is_array($group)) {
                    continue;
                }

                $name = (string) ($group['name'] ?? $group['type'] ?? '');
                $name = $name === '' ? 'general' : $name;

                $labels = array_values(array_filter(
                    (array) ($group['labels'] ?? $group['names'] ?? []),
                    'is_string'
                ));

                $athletes = (array) ($group['athletes'] ?? []);

                if ($labels === [] || $athletes === []) {
                    continue;
                }

                $types = [];

                foreach ($labels as $index => $label) {
                    $entries = [];

                    foreach ($athletes as $athlete) {
                        if (!is_array($athlete)) {
                            continue;
                        }

                        $stats = array_values((array) ($athlete['stats'] ?? []));

                        // A short row is a row, not a reason to drop the athlete.
                        if (!array_key_exists($index, $stats)) {
                            continue;
                        }

                        $entries[] = [
                            'name' => (string) (($athlete['athlete'] ?? [])['displayName'] ?? ''),
                            'stat' => (string) $stats[$index],
                        ];
                    }

                    if ($entries !== []) {
                        $types[] = ['name' => $label, 'athletes' => $entries];
                    }
                }

                /*
                 * 🚨 The same athletes again, ATHLETE-major this time.
                 *
                 * `types` is label-major — one list per statistic — which is
                 * what a box-score table renders from and useless for ranking
                 * people: a player's yards and touchdowns live in two different
                 * lists with nothing but array position joining them. This
                 * keeps each athlete's whole line together, with the id and the
                 * headshot the feed already sent, which is what a leaderboard
                 * needs and what the leaders-only shape threw away.
                 */
                $lines = [];

                foreach ($athletes as $athlete) {
                    if (!is_array($athlete)) {
                        continue;
                    }

                    $who = (array) ($athlete['athlete'] ?? []);
                    $displayName = (string) ($who['displayName'] ?? '');

                    if ($displayName === '') {
                        continue;
                    }

                    $stats = array_values((array) ($athlete['stats'] ?? []));
                    $map = [];

                    foreach ($labels as $index => $label) {
                        if (array_key_exists($index, $stats)) {
                            $map[$label] = (string) $stats[$index];
                        }
                    }

                    if ($map === []) {
                        continue;
                    }

                    $lines[] = [
                        'id' => (string) ($who['id'] ?? ''),
                        'name' => $displayName,
                        'jersey' => (string) ($who['jersey'] ?? ''),
                        'headshot' => (string) (((array) ($who['headshot'] ?? []))['href'] ?? ''),
                        'stats' => $map,
                    ];
                }

                if ($types !== [] || $lines !== []) {
                    $categories[] = ['name' => $name, 'types' => $types, 'lines' => $lines];
                }
            }

            $out[] = [
                'homeAway' => $this->whichSide($side, $teams, count($out)),
                'categories' => $categories,
            ];
        }

        return $out;
    }

    /**
     * Which side a player group belongs to.
     *
     * 🚨 ESPN does not put `homeAway` on the player side in any sport read so
     * far — it is on the TEAM side of the same box score, and the two lists
     * describe the same two clubs. Defaulting to "home" would file the away
     * team's leaders under the home team, which is wrong in a way that reads
     * perfectly plausibly and would never be noticed.
     *
     * 🚨 Matched by TEAM ID rather than by position. The two lists have been in
     * the same order (away, then home) in every sport checked, and relying on
     * that would work until the day it did not — at which point every recap
     * would name the wrong team's players and still look right. The order is
     * the fallback, not the rule.
     *
     * @param array<string, mixed>       $side
     * @param list<array<string, mixed>> $teams
     */
    protected function whichSide(array $side, array $teams, int $position): string
    {
        $id = (string) (($side['team'] ?? [])['id'] ?? '');

        if ($id !== '') {
            foreach ($teams as $team) {
                if (is_array($team) && (string) (($team['team'] ?? [])['id'] ?? '') === $id) {
                    return ($team['homeAway'] ?? '') === 'away' ? 'away' : 'home';
                }
            }
        }

        // ESPN orders the box score away-then-home wherever it has been read.
        return $position === 0 ? 'away' : 'home';
    }

    /* -------------------------------------------------------------- the wire */

    /** @return array<string, mixed>|null */
    protected function summary(League $league, string $eventId): ?array
    {
        if (isset($this->summaries[$eventId])) {
            return $this->summaries[$eventId];
        }

        $this->fetched++;

        try {
            $summary = $this->get($league->espnPath . '/summary', ['event' => $eventId]);
        } catch (RuntimeException) {
            /*
             * 🚨 Swallowed on purpose, and only here. A game whose summary is
             * missing or malformed is one thread without statistics; letting it
             * out would take down the whole run, and every other game in it.
             */
            return null;
        }

        return $this->summaries[$eventId] = $summary;
    }

    /** @return array<string, mixed> */
    protected function get(string $path, array $params): array
    {
        $response = $this->http->get(self::BASE . '/' . ltrim($path, '/'), [
            'query' => $params,
            'timeout' => self::TIMEOUT,
            'headers' => ['Accept' => 'application/json'],
            'http_errors' => false,
        ]);

        if ($response->getStatusCode() !== 200) {
            throw new RuntimeException('ESPN returned ' . $response->getStatusCode() . ' for ' . $path);
        }

        $decoded = json_decode((string) $response->getBody(), true);

        if (!is_array($decoded)) {
            throw new RuntimeException('ESPN returned something that is not JSON for ' . $path);
        }

        return $decoded;
    }
}
