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
            'home_score' => isset($home['score']) ? (int) $home['score'] : null,
            'away_score' => isset($away['score']) ? (int) $away['score'] : null,
            'completed' => $completed,
            'status' => (string) ($status['state'] ?? 'pre'),
            'neutral_site' => (bool) ($competition['neutralSite'] ?? false),
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

                if ($types !== []) {
                    $categories[] = ['name' => $name, 'types' => $types];
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
