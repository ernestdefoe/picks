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
     * A game not final this long after kickoff is treated as DONE: postponed,
     * cancelled, or a feed that never reported it. Without this one game
     * pinned the board to a week that was over, and kept the next week shut
     * for good. Shared with auto-unlock, so the two always agree.
     */
    public const DONE_AFTER_HOURS = 36;

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
            // An unannounced or future game, or one under way: still to play.
            if (! self::gameIsDone($kickoff, false, $now)) {
                return true;
            }
        }

        $last = self::ts($week['last_kickoff'] ?? null);

        return $last !== null
            && $last + (self::GAME_LENGTH_HOURS + self::GRACE_HOURS) * 3600 > $nowTs;
    }

    /**
     * Whether a game is over as far as weeks are concerned: final, or kicked
     * off more than DONE_AFTER_HOURS ago. A future kickoff — the 04:00Z
     * placeholder of an unannounced time included — is never done.
     */
    public static function gameIsDone(?string $kickoff, bool $finished, DateTimeInterface $now): bool
    {
        if ($finished) {
            return true;
        }

        $ts = self::ts($kickoff);

        return $ts !== null && $ts + self::DONE_AFTER_HOURS * 3600 <= $now->getTimestamp();
    }

    /**
     * Every game in the week done. A week with no games is NOT complete — an
     * unsynced schedule must not cascade open the whole season.
     *
     * @param array<int, array{0:?string, 1:bool}> $games [kickoff UTC, finished]
     */
    public static function weekIsComplete(array $games, DateTimeInterface $now): bool
    {
        if ($games === []) {
            return false;
        }

        foreach ($games as [$kickoff, $finished]) {
            if (! self::gameIsDone($kickoff, (bool) $finished, $now)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Auto-unlock: which week to open, for ONE season's weeks in schedule
     * order, or null.
     *
     * Once the LATEST open week is complete, open the first later week that
     * still has a game to play. Weeks with no games are stepped over, and so
     * are weeks already over — a board that switched this on mid-season gets
     * the week being played, not week 2 of a season half gone. No open week
     * at all: nothing, because the first week is always opened by hand.
     *
     * 🚨 A week opened here does not move the board. The board stays on the
     * earliest LIVE open week (pick()), so the just-finished week keeps its
     * day of results first.
     *
     * @param array<int, array{id:int, is_open:bool, games:array<int, array{0:?string, 1:bool}>}> $weeks
     */
    public static function weekToUnlock(array $weeks, DateTimeInterface $now): ?int
    {
        $latest = null;
        foreach ($weeks as $i => $week) {
            if ($week['is_open']) {
                $latest = $i;
            }
        }

        if ($latest === null || ! self::weekIsComplete($weeks[$latest]['games'], $now)) {
            return null;
        }

        foreach (array_slice($weeks, $latest + 1) as $week) {
            if ($week['games'] !== [] && ! self::weekIsComplete($week['games'], $now)) {
                return (int) $week['id'];
            }
        }

        return null;
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
