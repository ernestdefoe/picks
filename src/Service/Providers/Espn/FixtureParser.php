<?php

namespace Resofire\Picks\Service\Providers\Espn;

use Resofire\Picks\Service\Leagues\League;
use Resofire\Picks\Service\Providers\EspnProvider;

/**
 * One scoreboard event, read into the fixture shape EspnProvider::games()
 * answers with.
 *
 * Pure functions over a decoded response: no wire, no state, so they are
 * static and need nothing from the container.
 *
 * @internal
 */
final class FixtureParser
{
    /**
     * @param  array<string, mixed> $event
     * @return array<string, mixed>|null
     */
    public static function game(array $event, League $league): ?array
    {
        $competition = (array) (($event['competitions'] ?? [[]])[0] ?? []);

        [$home, $away] = self::sides((array) ($competition['competitors'] ?? []));

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

        return self::fixture($event, $league, $competition, $home, $away, $status, $completed)
            + self::leadIn($event, $competition, $home, $away)
            + self::liveState($event, $competition, $home, $away, $status);
    }

    /**
     * The home and away competitors, either null when the feed left it out.
     *
     * @param  array<int, mixed> $competitors
     * @return array{0: mixed, 1: mixed}
     */
    private static function sides(array $competitors): array
    {
        $home = null;
        $away = null;

        foreach ($competitors as $side) {
            if (($side['homeAway'] ?? '') === 'home') {
                $home = $side;
            } elseif (($side['homeAway'] ?? '') === 'away') {
                $away = $side;
            }
        }

        return [$home, $away];
    }

    /**
     * Who is playing, when, and the score.
     *
     * @param  array<string, mixed> $event
     * @param  array<string, mixed> $competition
     * @param  array<string, mixed> $status
     * @return array<string, mixed>
     */
    private static function fixture(array $event, League $league, array $competition, mixed $home, mixed $away, array $status, bool $completed): array
    {
        return [
            'external_id' => (string) ($event['id'] ?? ''),
            'week' => $league->hasWeeks ? self::weekNumber($event) : null,
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
            'home_team' => self::club($home),
            'away_team' => self::club($away),
            'home_score' => isset($home['score']) ? (int) $home['score'] : null,
            'away_score' => isset($away['score']) ? (int) $away['score'] : null,
            'completed' => $completed,
            'status' => (string) ($status['state'] ?? 'pre'),
            'neutral_site' => (bool) ($competition['neutralSite'] ?? false),
        ];
    }

    /**
     * The lead-in.
     *
     * @param  array<string, mixed> $event
     * @param  array<string, mixed> $competition
     * @return array<string, mixed>
     */
    private static function leadIn(array $event, array $competition, mixed $home, mixed $away): array
    {
        /*
         * 🚨 Taken from the FIXTURE, which is what makes it free and what
         * makes it right. Free because this payload is already in hand;
         * right because a rank belongs to the week the game was played in,
         * and asking a poll for it later would answer with today's.
         */
        return [
            'home_rank' => EspnProvider::rank($home),
            'away_rank' => EspnProvider::rank($away),
            'home_record' => EspnProvider::record($home),
            'away_record' => EspnProvider::record($away),
            'venue' => trim((string) (((array) ($competition['venue'] ?? []))['fullName'] ?? '')),
            'venue_city' => EspnProvider::venueCity((array) ($competition['venue'] ?? [])),
            'broadcast' => EspnProvider::broadcast($competition),
            'broadcasts' => EspnProvider::broadcasts($event),
        ];
    }

    /**
     * Live state: the clock, the ball, the down.
     *
     * @param  array<string, mixed> $event
     * @param  array<string, mixed> $competition
     * @param  array<string, mixed> $status
     * @return array<string, mixed>
     */
    private static function liveState(array $event, array $competition, mixed $home, mixed $away, array $status): array
    {
        $situation = (array) ($competition['situation'] ?? []);

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

        return [
            'period' => (int) ($clock['period'] ?? 0),
            'clock' => trim((string) ($clock['displayClock'] ?? '')),
            // ESPN's own words, which beat anything built here from a number:
            // it already knows what a period means in a game gone to overtime.
            'clock_detail' => trim((string) ($status['shortDetail'] ?? $status['detail'] ?? '')),
            'possession' => self::possession($situation, $home, $away),
            /*
             * The short form. "2nd & 10" is what belongs on a scoreboard;
             * "2nd & 10 at TCU 45" is a sentence, and the yard line is already
             * the least durable thing on the strip.
             */
            'down_distance' => EspnProvider::downAndDistance($situation),
            'ball_on' => trim((string) ($situation['possessionText'] ?? '')),
            'red_zone' => ! empty($situation['isRedZone']),
        ];
    }

    /**
     * Who has the ball, as OUR word for a side rather than ESPN's id for a
     * team.
     *
     * 🚨 `situation.possession` is an ESPN team id, and nothing in this
     * database is keyed by one. Resolving it here — against the two
     * competitors already in hand — means everything downstream stores
     * "home" or "away", which is what a scoreboard needs and cannot drift
     * out of step with anybody's team table.
     *
     * @param array<string, mixed> $situation
     */
    private static function possession(array $situation, mixed $home, mixed $away): string
    {
        $hasBall = (string) ($situation['possession'] ?? '');
        $possession = '';

        if ($hasBall === '') {
            return $possession;
        }

        foreach ([['home', $home], ['away', $away]] as [$side, $competitor]) {
            if ((string) (($competitor['team'] ?? [])['id'] ?? $competitor['id'] ?? '') === $hasBall) {
                $possession = $side;
            }
        }

        return $possession;
    }

    /**
     * The club itself, as much of it as a scoreboard carries.
     *
     * @param  array<string, mixed> $side
     * @return array{external_id: string, name: string, abbreviation: string, logo: string, color: string}
     */
    private static function club(array $side): array
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

    /** @param array<string, mixed> $event */
    private static function weekNumber(array $event): ?int
    {
        $week = $event['week'] ?? null;

        if (is_array($week) && isset($week['number'])) {
            return (int) $week['number'];
        }

        return is_numeric($week) ? (int) $week : null;
    }
}
