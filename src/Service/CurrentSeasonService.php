<?php

namespace Resofire\Picks\Service;

use Carbon\Carbon;
use DateTimeInterface;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Resofire\Picks\PickEvent;
use Resofire\Picks\Season;
use Resofire\Picks\UserScore;
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

    /** How long the board week is remembered, in seconds. */
    private const BOARD_WEEK_TTL = 10;

    public function __construct(
        protected CacheRepository $cache
    ) {
    }

    /**
     * The week the board opens on, and the week its "this week" button goes
     * back to — the same answer for both, from {@see CurrentWeek::pick()}.
     *
     * 🚨 Not getCurrentWeek(). That one answers "which week's scores are
     * being kept" for the leaderboard and stats, and ignores which weeks are
     * open; this one answers "which open week should a visitor see".
     *
     * Two queries whatever the size of the schedule: the weeks, and one row
     * per week of its games summed up in SQL. Remembered in the cache for a few
     * seconds, because the week resource asks once per week it serializes.
     */
    public function getBoardWeekId(?DateTimeInterface $now = null): ?int
    {
        if ($now !== null) {
            return $this->pickBoardWeek($now);
        }

        // 0 stands for "no week": the cache does not keep a null.
        $id = (int) $this->cache->remember('picks.board_week_id', self::BOARD_WEEK_TTL, fn () => $this->pickBoardWeek(Carbon::now('UTC')) ?? 0);

        return $id > 0 ? $id : null;
    }

    private function pickBoardWeek(DateTimeInterface $now): ?int
    {
        $weeks = Week::query()
            ->join('picks_seasons', 'picks_seasons.id', '=', 'picks_weeks.season_id')
            ->orderBy('picks_seasons.year')
            ->orderBy('picks_weeks.season_id')
            /*
             * 🚨 Through the grammar, not a bare column name. orderByRaw() is
             * passed through VERBATIM — the query builder prefixes the tables
             * it is given, never the text of a raw clause — so on a forum with
             * a table prefix "picks_weeks.season_type" names a table that does
             * not exist, and every request that asks for the board's week
             * failed with "Database query failed". Forums without a prefix
             * never saw it.
             */
            ->orderByRaw(Week::seasonTypeOrder('regular'))
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

        foreach ($this->gameSummaries() as $summary) {
            $id = (int) $summary->week_id;

            if (! isset($rows[$id])) {
                continue;
            }

            /*
             * 🚨 Anything not final counts as unfinished — `closed` included,
             * which is a game whose picks have locked and which may well be under
             * way. CurrentWeek only asks whether ANY unfinished game is still in
             * play, which is true exactly when the latest unfinished kickoff is
             * (every game has one: `match_date` is required), so that kickoff
             * stands in for the whole list.
             */
            if ($summary->unfinished_last !== null) {
                $rows[$id]['unfinished'][] = self::utc($summary->unfinished_last);
            }

            $rows[$id]['last_kickoff'] = self::utc($summary->last_kickoff);
        }

        return CurrentWeek::pick(array_values($rows), $now);
    }

    /**
     * One row per week: its latest kickoff and its latest unfinished kickoff.
     *
     * @return \Illuminate\Support\Collection<int, \stdClass>
     */
    private function gameSummaries(): \Illuminate\Support\Collection
    {
        $query = PickEvent::query()->toBase();
        $grammar = $query->getGrammar();
        $status = $grammar->wrap('status');
        $kickoff = $grammar->wrap('match_date');

        return $query
            ->select('week_id')
            ->selectRaw("MAX({$kickoff}) as last_kickoff")
            ->selectRaw("MAX(CASE WHEN {$status} <> ? THEN {$kickoff} END) as unfinished_last", [PickEvent::STATUS_FINISHED])
            ->whereNotNull('week_id')
            ->groupBy('week_id')
            ->get();
    }

    private static function utc(mixed $value): ?string
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
            ->orderByRaw(Week::seasonTypeOrder('regular'))
            ->orderBy('week_number')
            ->first();
    }

    /**
     * The season whose standings are being kept: the current week's, or
     * between seasons the latest one anybody has scores in.
     */
    public function getCurrentSeasonId(): ?int
    {
        $id = $this->getCurrentWeek()->season_id
            ?? Season::query()
                ->whereIn('id', UserScore::query()->select('season_id')->whereNotNull('season_id'))
                ->orderByDesc('year')
                ->orderByDesc('id')
                ->value('id');

        return $id === null ? null : (int) $id;
    }
}
