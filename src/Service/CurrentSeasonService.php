<?php

namespace Resofire\Picks\Service;

use Carbon\Carbon;
use DateTimeInterface;
use Resofire\Picks\PickEvent;
use Resofire\Picks\Season;
use Resofire\Picks\Week;

/**
 * Single source of truth for "what week are we currently in".
 *
 * A week is current if it has at least one game not yet finished (status
 * scheduled / in_progress). We take the most recent season that still has an
 * unfinished game, then the earliest unfinished week within it — so future
 * unplayed weeks in a later season never jump ahead of the true current week.
 *
 * Previously this two-step query was copy-pasted as raw Capsule queries into
 * five controllers; centralising it here (in Eloquent) means the active-season
 * definition lives in one place.
 */
class CurrentSeasonService
{
    /** Game statuses that mean "not yet finished". */
    public const UNFINISHED = ['scheduled', 'in_progress'];

    /** Board week ids already worked out in this request. */
    private static ?int $boardWeekId = null;

    private static ?int $boardWeekAt = null;

    /**
     * The week the board opens on, and the week its "this week" button goes
     * back to — the same answer for both, from {@see CurrentWeek::pick()}.
     *
     * 🚨 Not getCurrentWeek(). That one answers "which week's scores are
     * being kept" for the leaderboard and stats, and ignores which weeks are
     * open; this one answers "which open week should a visitor see".
     *
     * Two queries for every week of every season, whatever the size of the
     * schedule. Remembered (statically) for a few seconds, because the week
     * resource asks once per week it serializes and each ask may resolve a
     * fresh instance of this service.
     */
    public function getBoardWeekId(?DateTimeInterface $now = null): ?int
    {
        if ($now === null && self::$boardWeekAt !== null && time() - self::$boardWeekAt < 10) {
            return self::$boardWeekId;
        }

        $weeks = Week::query()
            ->join('picks_seasons', 'picks_seasons.id', '=', 'picks_weeks.season_id')
            ->orderBy('picks_seasons.year')
            ->orderBy('picks_weeks.season_id')
            ->orderByRaw("CASE picks_weeks.season_type WHEN 'regular' THEN 0 ELSE 1 END")
            ->orderBy('picks_weeks.week_number')
            ->orderBy('picks_weeks.id')
            ->get(['picks_weeks.id', 'picks_weeks.is_open']);

        $rows = [];
        foreach ($weeks as $week) {
            $rows[(int) $week->id] = [
                'id' => (int) $week->id,
                'is_open' => (bool) $week->is_open,
                'unfinished' => [],
                'last_kickoff' => null,
            ];
        }

        if ($rows !== []) {
            $events = PickEvent::query()
                ->whereIn('week_id', array_keys($rows))
                ->get(['week_id', 'match_date', 'status']);

            foreach ($events as $event) {
                $id = (int) $event->week_id;
                $kickoff = self::utc($event->getRawOriginal('match_date'));

                if (in_array($event->status, self::UNFINISHED, true)) {
                    $rows[$id]['unfinished'][] = $kickoff;
                }

                if ($kickoff !== null && ($rows[$id]['last_kickoff'] === null || $kickoff > $rows[$id]['last_kickoff'])) {
                    $rows[$id]['last_kickoff'] = $kickoff;
                }
            }
        }

        $picked = CurrentWeek::pick(array_values($rows), $now ?? Carbon::now('UTC'));

        if ($now === null) {
            self::$boardWeekId = $picked;
            self::$boardWeekAt = time();
        }

        return $picked;
    }

    private static function utc($value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return Carbon::parse((string) $value, 'UTC')->utc()->format('Y-m-d H:i:s');
    }

    public function getCurrentWeek(): ?Week
    {
        $currentSeason = Season::query()
            ->whereHas('weeks.events', fn ($q) => $q->whereIn('status', self::UNFINISHED))
            ->orderByDesc('year')
            ->first();

        if ($currentSeason === null) {
            return null;
        }

        return Week::query()
            ->where('season_id', $currentSeason->id)
            ->whereHas('events', fn ($q) => $q->whereIn('status', self::UNFINISHED))
            // Regular-season weeks rank ahead of postseason within a season.
            ->orderByRaw("CASE season_type WHEN 'regular' THEN 0 ELSE 1 END")
            ->orderBy('week_number')
            ->first();
    }
}
