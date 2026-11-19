<?php

namespace Resofire\Picks\Service\Providers\Espn;

/**
 * ESPN's game summary, read into the box-score shape EspnProvider::boxScore()
 * answers with.
 *
 * Pure functions over a decoded response: no wire, no state, so they are
 * static and need nothing from the container.
 *
 * @internal
 */
final class BoxScoreParser
{
    /**
     * The single play that moved the game most, with its text.
     *
     * 🚨 Computed here and stored as five fields, rather than keeping the win
     * probability itself. The feed sends a reading per play — 182 of them for
     * one game — and all a recap needs is which play swung it and by how much.
     * Storing the array would multiply every box score for an answer that never
     * changes once the game is over.
     *
     * 🚨 The swing is measured between CONSECUTIVE readings, so it is the
     * effect of one play rather than the distance from the start of the game.
     *
     * @param array<int, mixed> $probability
     * @param array<string, mixed> $drives
     *
     * @return array<string, mixed>
     */
    public static function biggestSwing(array $probability, array $drives): array
    {
        $best = self::largestSwing($probability);

        if ($best === null || $best['play'] === '') {
            return [];
        }

        $text = self::playText($drives, $best['play']);

        // 🚨 A swing nobody can name is not worth reporting. Without the play's
        // own words the recap could only say that something big happened.
        if ($text === '') {
            return [];
        }

        return [
            'text' => $text,
            // Positive swung it the home team's way, negative the away team's.
            'toward' => $best['delta'] > 0 ? 'home' : 'away',
            'points' => (int) round(abs($best['delta']) * 100),
            'after' => (int) round($best['after'] * 100),
        ];
    }

    /**
     * The biggest move between consecutive readings, or null when there are
     * fewer than two.
     *
     * @param  array<int, mixed> $probability
     * @return array{delta: float, after: float, play: string}|null
     */
    private static function largestSwing(array $probability): ?array
    {
        $best = null;
        $previous = null;

        foreach ($probability as $point) {
            if (! is_array($point) || ! isset($point['homeWinPercentage'])) {
                continue;
            }

            $now = (float) $point['homeWinPercentage'];

            if ($previous !== null) {
                $delta = $now - $previous;

                if ($best === null || abs($delta) > abs($best['delta'])) {
                    $best = ['delta' => $delta, 'after' => $now, 'play' => (string) ($point['playId'] ?? '')];
                }
            }

            $previous = $now;
        }

        return $best;
    }

    /**
     * The words of one play, found among the drives, or '' when it is not there.
     *
     * @param array<string, mixed> $drives
     */
    private static function playText(array $drives, string $playId): string
    {
        foreach ((array) ($drives['previous'] ?? []) as $drive) {
            foreach ((array) ($drive['plays'] ?? []) as $play) {
                if ((string) ($play['id'] ?? '') === $playId) {
                    return trim((string) ($play['text'] ?? ''));
                }
            }
        }

        return '';
    }

    /**
     * Each side's points by period: ['home' => [3, 14, 0, 0], 'away' => [...]].
     *
     * 🚨 However many periods were actually played. An overtime game has five
     * or more, and anything assuming four drops the period the game was decided
     * in — which is the one worth reading.
     *
     * @param array<string, mixed> $competition
     *
     * @return array<string, list<int>>
     */
    public static function lineScores(array $competition): array
    {
        $out = [];

        foreach ((array) ($competition['competitors'] ?? []) as $side) {
            $where = ($side['homeAway'] ?? '') === 'away' ? 'away' : 'home';
            $out[$where] = array_map(
                fn ($p) => (int) ($p['value'] ?? $p['displayValue'] ?? 0),
                (array) ($side['linescores'] ?? [])
            );
        }

        return $out;
    }

    /**
     * The scoring plays, in order, each with the score it produced.
     *
     * 🚨 The running score is kept with the play, not recomputed. The feed
     * states what the board read after each score; adding points up again here
     * would quietly disagree with it the first time a two-point conversion or a
     * safety appeared.
     *
     * @param array<int, mixed> $plays
     *
     * @return list<array<string, mixed>>
     */
    public static function scoringPlays(array $plays): array
    {
        $out = [];

        foreach ($plays as $play) {
            if (! is_array($play)) {
                continue;
            }

            $out[] = [
                'period' => (int) (($play['period'] ?? [])['number'] ?? 0),
                'clock' => (string) (($play['clock'] ?? [])['displayValue'] ?? ''),
                'team' => (string) (($play['team'] ?? [])['abbreviation'] ?? ''),
                'type' => (string) (($play['type'] ?? [])['text'] ?? ''),
                'text' => trim((string) ($play['text'] ?? '')),
                'home' => (int) ($play['homeScore'] ?? 0),
                'away' => (int) ($play['awayScore'] ?? 0),
            ];
        }

        return $out;
    }

    /**
     * The closing line, or an empty array when nobody published one.
     *
     * 🚨 One book's number, named. "The spread" with no source is a number
     * presented as a fact of the universe, and two books rarely agree.
     *
     * @param array<int, mixed> $pickcenter
     *
     * @return array<string, mixed>
     */
    public static function market(array $pickcenter): array
    {
        foreach ($pickcenter as $entry) {
            if (! is_array($entry) || trim((string) ($entry['details'] ?? '')) === '') {
                continue;
            }

            return [
                'provider' => (string) (($entry['provider'] ?? [])['name'] ?? ''),
                'line' => trim((string) $entry['details']),
                'total' => isset($entry['overUnder']) ? (float) $entry['overUnder'] : null,
            ];
        }

        return [];
    }

    /**
     * A team's statistics, flattened onto the shape CFBD answers:
     * `[{homeAway, team, points, stats: [{category, stat}]}]`.
     *
     * @return list<array<string, mixed>>
     */
    public static function teamSides(array $teams): array
    {
        $out = [];

        foreach ($teams as $side) {
            if (! is_array($side)) {
                continue;
            }

            $out[] = [
                'homeAway' => (string) ($side['homeAway'] ?? 'home'),
                'team' => (string) (($side['team'] ?? [])['displayName'] ?? ''),
                'points' => null,
                'stats' => self::flatten((array) ($side['statistics'] ?? [])),
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
    public static function flatten(array $statistics, string $prefix = ''): array
    {
        $out = [];

        foreach ($statistics as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $name = (string) ($entry['name'] ?? '');

            if ($name === '') {
                continue;
            }

            if (isset($entry['stats']) && is_array($entry['stats'])) {
                foreach (self::flatten($entry['stats'], $prefix.$name.'.') as $nested) {
                    $out[] = $nested;
                }

                continue;
            }

            $out[] = [
                'category' => $prefix.$name,
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
    public static function playerSides(array $players, array $teams): array
    {
        $out = [];

        foreach ($players as $side) {
            if (! is_array($side)) {
                continue;
            }

            $categories = [];

            foreach ((array) ($side['statistics'] ?? []) as $group) {
                $category = is_array($group) ? self::category($group) : null;

                if ($category !== null) {
                    $categories[] = $category;
                }
            }

            $out[] = [
                'homeAway' => self::whichSide($side, $teams, count($out)),
                'categories' => $categories,
            ];
        }

        return $out;
    }

    /**
     * One statistics group, both ways round, or null when it has nothing in it.
     *
     * @param  array<string, mixed> $group
     * @return array{name: string, types: list<array<string, mixed>>, lines: list<array<string, mixed>>}|null
     */
    private static function category(array $group): ?array
    {
        $name = (string) ($group['name'] ?? $group['type'] ?? '');
        $name = $name === '' ? 'general' : $name;

        $labels = array_values(array_filter(
            (array) ($group['labels'] ?? $group['names'] ?? []),
            'is_string'
        ));

        $athletes = (array) ($group['athletes'] ?? []);

        if ($labels === [] || $athletes === []) {
            return null;
        }

        $types = self::labelMajor($labels, $athletes);

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
        $lines = self::athleteLines($labels, $athletes);

        if ($types !== [] || $lines !== []) {
            return ['name' => $name, 'types' => $types, 'lines' => $lines];
        }

        return null;
    }

    /**
     * One list per statistic: `[{name: label, athletes: [{name, stat}]}]`.
     *
     * @param  list<string>      $labels
     * @param  array<int, mixed> $athletes
     * @return list<array{name: string, athletes: list<array{name: string, stat: string}>}>
     */
    private static function labelMajor(array $labels, array $athletes): array
    {
        $types = [];

        foreach ($labels as $index => $label) {
            $entries = [];

            foreach ($athletes as $athlete) {
                if (! is_array($athlete)) {
                    continue;
                }

                $stats = array_values((array) ($athlete['stats'] ?? []));

                // A short row is a row, not a reason to drop the athlete.
                if (! array_key_exists($index, $stats)) {
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

        return $types;
    }

    /**
     * Each athlete's whole line, keyed by label.
     *
     * @param  list<string>      $labels
     * @param  array<int, mixed> $athletes
     * @return list<array<string, mixed>>
     */
    private static function athleteLines(array $labels, array $athletes): array
    {
        $lines = [];

        foreach ($athletes as $athlete) {
            if (! is_array($athlete)) {
                continue;
            }

            $who = (array) ($athlete['athlete'] ?? []);
            $displayName = (string) ($who['displayName'] ?? '');

            if ($displayName === '') {
                continue;
            }

            $map = self::statMap($labels, array_values((array) ($athlete['stats'] ?? [])));

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

        return $lines;
    }

    /**
     * @param  list<string>     $labels
     * @param  list<mixed>      $stats
     * @return array<string, string>
     */
    private static function statMap(array $labels, array $stats): array
    {
        $map = [];

        foreach ($labels as $index => $label) {
            if (array_key_exists($index, $stats)) {
                $map[$label] = (string) $stats[$index];
            }
        }

        return $map;
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
    public static function whichSide(array $side, array $teams, int $position): string
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
}
