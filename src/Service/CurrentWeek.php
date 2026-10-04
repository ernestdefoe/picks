<?php

namespace Resofire\Picks\Service;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;

/**
 * Which week the board opens on. A pure rule, so it can be tested without
 * Flarum or a database (tests/run.php).
 *
 * 🚨 The board used to open on the LAST open week. A board that opens two
 * weeks at a time therefore jumped to next week while this week's games were
 * still being played — college-football.co.uk had weeks 5, 6 and 7 open on a
 * Sunday and every visit landed on week 7, nine days before its first game.
 *
 * The rule, over open weeks in schedule order:
 *
 *   1. the EARLIEST week that is still "live" — a game not yet final, or its
 *      last game ended less than a day ago, so Sunday still shows Saturday's
 *      results and Monday moves on;
 *   2. otherwise the LATEST open week, once every open week is complete.
 *
 * With no week open at all, the same rule runs over every week, so a board
 * that never uses the open/closed switch still lands on the week being
 * played rather than the end of the schedule.
 *
 * Each week is passed as:
 *   ['id' => int, 'is_open' => bool, 'unfinished' => string[] kickoffs of
 *    games not yet final, 'last_kickoff' => ?string latest kickoff of any game]
 * in schedule order. Kickoffs are UTC 'Y-m-d H:i:s' strings, as stored.
 */
final class CurrentWeek
{
    /** How long a game takes, kickoff to final whistle, to the safe side. */
    public const GAME_LENGTH_HOURS = 4;

    /** How long a finished week stays current, so its results get seen. */
    public const GRACE_HOURS = 24;

    /**
     * A game still "scheduled" this long after kickoff was never reported —
     * cancelled, or a feed that missed it — and must not pin the board to a
     * week that is over.
     */
    public const STALE_HOURS = 72;

    /**
     * @param array<int, array{id:int, is_open:bool, unfinished:array<int,string|null>, last_kickoff:?string}> $weeks
     */
    public static function pick(array $weeks, DateTimeInterface $now): ?int
    {
        if ($weeks === []) {
            return null;
        }

        $open = array_values(array_filter($weeks, fn (array $w) => (bool) $w['is_open']));
        $candidates = $open !== [] ? $open : array_values($weeks);

        foreach ($candidates as $week) {
            if (self::isLive($week, $now)) {
                return (int) $week['id'];
            }
        }

        return (int) $candidates[count($candidates) - 1]['id'];
    }

    /** @param array{unfinished:array<int,string|null>, last_kickoff:?string} $week */
    public static function isLive(array $week, DateTimeInterface $now): bool
    {
        $nowTs = $now->getTimestamp();

        foreach ($week['unfinished'] as $kickoff) {
            $ts = self::ts($kickoff);

            // An unannounced or future game, or one under way: still to play.
            if ($ts === null || $ts + self::STALE_HOURS * 3600 > $nowTs) {
                return true;
            }
        }

        $last = self::ts($week['last_kickoff'] ?? null);

        return $last !== null
            && $last + (self::GAME_LENGTH_HOURS + self::GRACE_HOURS) * 3600 > $nowTs;
    }

    private static function ts(?string $utc): ?int
    {
        if ($utc === null || $utc === '') {
            return null;
        }

        try {
            return (new DateTimeImmutable($utc, new DateTimeZone('UTC')))->getTimestamp();
        } catch (\Exception $e) {
            return null;
        }
    }
}
