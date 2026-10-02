<?php

namespace Resofire\Picks\Service;

use Carbon\Carbon;
use Resofire\Picks\PickEvent;

/**
 * Keeps a fixture's kickoff in step with the feed until the game starts.
 *
 * 🚨 A schedule is synced once, weeks ahead, when most kickoff times are not
 * yet announced — the feed sends a placeholder (ESPN: midnight Eastern on game
 * day, `timeValid: false`). Nothing ever came back for the real time, so the
 * placeholder stood: "tonight 11pm Central" for a 2:30pm Saturday game, and the
 * picks lock was set from it. Every payload that already carries the
 * competition passes through here, so a time is picked up the day it is
 * announced, and a game that is moved is moved here too.
 */
class Kickoff
{
    /**
     * @param array<string, mixed> $competition one ESPN `competitions[]` entry
     */
    public static function apply(PickEvent $event, array $competition, int $lockOffsetMinutes = 0): void
    {
        // Once a game is under way its kickoff is history; the feed's `date`
        // stays put anyway, but nothing should be able to move a played game.
        if ($event->status !== PickEvent::STATUS_SCHEDULED) {
            return;
        }

        $raw = (string) ($competition['date'] ?? '');

        if ($raw === '') {
            return;
        }

        try {
            $start = Carbon::parse($raw)->utc();
        } catch (\Throwable) {
            return;
        }

        // Absent means announced: the flag is only ever sent false to say "not yet".
        $tbd = ($competition['timeValid'] ?? true) === false;

        if ($event->match_date === null || ! $event->match_date->equalTo($start)) {
            $event->match_date = $start;
        }

        if ((bool) $event->time_tbd !== $tbd) {
            $event->time_tbd = $tbd;
        }

        $cutoff = self::cutoff($start, $tbd, $lockOffsetMinutes);

        if ($event->cutoff_date === null || ! $event->cutoff_date->equalTo($cutoff)) {
            $event->cutoff_date = $cutoff;
        }
    }

    /**
     * When picks lock. The same rule the schedule sync has always used: the
     * kickoff, or noon UTC on game day while the time is unannounced, less the
     * board's lock offset.
     */
    public static function cutoff(Carbon $start, bool $tbd, int $lockOffsetMinutes = 0): Carbon
    {
        $cutoff = $tbd ? $start->copy()->startOfDay()->addHours(12) : $start->copy();

        if ($lockOffsetMinutes > 0) {
            $cutoff->subMinutes($lockOffsetMinutes);
        }

        return $cutoff;
    }
}
