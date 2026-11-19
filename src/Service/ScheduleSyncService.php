<?php

namespace Resofire\Picks\Service;

use Carbon\Carbon;
use Flarum\Locale\TranslatorInterface;
use Flarum\Settings\SettingsRepositoryInterface;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Resofire\Picks\PickEvent;
use Resofire\Picks\Season;
use Resofire\Picks\Service\Leagues\Leagues;
use Resofire\Picks\Team;
use Resofire\Picks\Week;

class ScheduleSyncService
{
    public function __construct(
        protected CfbdService $cfbd,
        protected SettingsRepositoryInterface $settings,
        protected TranslatorInterface $translator
    ) {
    }

    /**
     * Sync seasons, weeks, and games for the configured year.
     *
     * Regular season weeks come from the CFBD /calendar endpoint.
     * Postseason games are all placed in a single "Bowl Season" week.
     *
     * Returns a summary array with counts.
     */
    public function sync(): array
    {
        $year = (int) $this->settings->get('ernestdefoe-picks.season_year');

        $syncRegular = (bool) $this->settings->get('ernestdefoe-picks.sync_regular_season');
        $syncPostseason = (bool) $this->settings->get('ernestdefoe-picks.sync_postseason');

        $season = $this->syncSeason($year);

        $tally = ['weeksCreated' => 0, 'weeksUpdated' => 0, 'gamesCreated' => 0, 'gamesUpdated' => 0];

        // Build the cfbd_id => local team id map ONCE for the whole sync and
        // pass it into syncGames(), rather than re-scanning picks_teams on
        // every week.
        $teamMap = Team::whereNotNull('cfbd_id')->pluck('id', 'cfbd_id')->all();

        // Preload every existing event keyed by cfbd_id ONCE, rather than firing
        // a PickEvent::where('cfbd_id', …)->first() per game — a full 17-week FBS
        // season is ~1,500 games, i.e. ~1,500 individual SELECTs. New events
        // created during this sync are added to the map as we go (see syncGames).
        $existingEventsByCfbdId = PickEvent::whereNotNull('cfbd_id')->get()->keyBy('cfbd_id');

        if ($syncRegular) {
            $this->syncRegularSeason($year, $season, $teamMap, $existingEventsByCfbdId, $tally);
        }

        if ($syncPostseason) {
            $this->syncPostseason($year, $season, $teamMap, $existingEventsByCfbdId, $tally);
        }

        $this->settings->set(
            'ernestdefoe-picks.last_schedule_sync',
            Carbon::now()->toIso8601String()
        );

        return $tally;
    }

    /**
     * Regular season: weeks from the CFBD calendar, games fetched per week.
     *
     * @param array<int|string, int> $teamMap
     * @param \Illuminate\Support\Collection<int|string, PickEvent> $existingEventsByCfbdId
     * @param array{weeksCreated: int, weeksUpdated: int, gamesCreated: int, gamesUpdated: int} $tally
     */
    private function syncRegularSeason(int $year, Season $season, array $teamMap, $existingEventsByCfbdId, array &$tally): void
    {
        $calendarWeeks = $this->cfbd->fetchCalendar($year);

        // Filter to regular season only from the calendar
        $regularWeeks = array_filter($calendarWeeks, function (array $w) {
            return strtolower($w['seasonType'] ?? '') === 'regular';
        });

        foreach ($regularWeeks as $calWeek) {
            $weekNumber = (int) $calWeek['week'];
            $startDate = $this->parseDate($calWeek['startDate'] ?? null);
            $endDate = $this->parseDate($calWeek['endDate'] ?? null);

            $week = $this->syncWeek(
                season:     $season,
                weekNumber: $weekNumber,
                seasonType: 'regular',
                name:       $this->translator->trans('ernestdefoe-picks.api.weeks.week', ['number' => $weekNumber]),
                startDate:  $startDate,
                endDate:    $endDate
            );

            $this->countWeek($week, $tally);

            // Fetch and sync games for this week
            $apiGames = $this->cfbd->fetchGames($year, 'regular', $weekNumber);
            $this->countGames($this->syncGames($apiGames, $week, $teamMap, $existingEventsByCfbdId), $tally);
        }
    }

    /**
     * Postseason — all games in one "Bowl Season" week.
     *
     * @param array<int|string, int> $teamMap
     * @param \Illuminate\Support\Collection<int|string, PickEvent> $existingEventsByCfbdId
     * @param array{weeksCreated: int, weeksUpdated: int, gamesCreated: int, gamesUpdated: int} $tally
     */
    private function syncPostseason(int $year, Season $season, array $teamMap, $existingEventsByCfbdId, array &$tally): void
    {
        $apiGames = $this->cfbd->fetchGames($year, 'postseason');

        if (empty($apiGames)) {
            return;
        }

        // Determine date range from actual game dates
        $dates = array_filter(array_map(
            fn ($g) => $this->parseDate($g['startDate'] ?? null),
            $apiGames
        ));

        $startDate = ! empty($dates) ? min($dates) : null;
        $endDate = ! empty($dates) ? max($dates) : null;

        $week = $this->syncWeek(
            season:     $season,
            weekNumber: 1,
            seasonType: 'postseason',
            name:       $this->translator->trans('ernestdefoe-picks.api.weeks.bowl_season'),
            startDate:  $startDate,
            endDate:    $endDate
        );

        $this->countWeek($week, $tally);

        $this->countGames($this->syncGames($apiGames, $week, $teamMap, $existingEventsByCfbdId), $tally);
    }

    /** @param array{weeksCreated: int, weeksUpdated: int, gamesCreated: int, gamesUpdated: int} $tally */
    private function countWeek(Week $week, array &$tally): void
    {
        if ($week->wasRecentlyCreated) {
            $tally['weeksCreated']++;
        } else {
            $tally['weeksUpdated']++;
        }
    }

    /**
     * @param array{0: int, 1: int} $counts [created, updated]
     * @param array{weeksCreated: int, weeksUpdated: int, gamesCreated: int, gamesUpdated: int} $tally
     */
    private function countGames(array $counts, array &$tally): void
    {
        [$gc, $gu] = $counts;
        $tally['gamesCreated'] += $gc;
        $tally['gamesUpdated'] += $gu;
    }

    /**
     * Find or create the season record for a given year.
     */
    private function syncSeason(int $year): Season
    {
        /*
         * 🚨 Scoped to college football, not to the year alone.
         *
         * A board following the NFL and college football has two seasons for
         * 2026, and a lookup by year would hand this whichever came first —
         * so the college calendar would be written into the NFL's season and
         * every NFL fixture would arrive under a competition it is not in. The
         * league column is what makes them two rows; this is what makes them
         * stay two rows.
         */
        $season = Season::where('year', $year)
            ->where(function ($q) {
                // Seasons created before the league column existed have no
                // value in it, and every one of them is college football.
                $q->where('league', Leagues::DEFAULT)
                  ->orWhereNull('league')
                  ->orWhere('league', '');
            })
            ->first();

        if (! $season) {
            $season = new Season();
            $season->year = $year;
            $season->name = $year.' Season';
            $season->slug = Str::slug($year.'-season');
        }

        // Stamped on every pass, so a pre-league row is repaired the first time
        // it syncs rather than staying ambiguous forever.
        $season->league = Leagues::DEFAULT;

        // Always update dates from the calendar if we have them
        $season->save();

        return $season;
    }

    /**
     * Find or create a week record, updating its dates if it already exists.
     */
    private function syncWeek(
        Season $season,
        int $weekNumber,
        string $seasonType,
        string $name,
        ?string $startDate,
        ?string $endDate
    ): Week {
        $week = Week::where('season_id', $season->id)
            ->where('week_number', $weekNumber)
            ->where('season_type', $seasonType)
            ->first();

        if (! $week) {
            $week = new Week();
            $week->season_id = $season->id;
            $week->week_number = $weekNumber;
            $week->season_type = $seasonType;
            $week->name = $name;
        }

        $week->start_date = $startDate;
        $week->end_date = $endDate;
        $week->save();

        // $week->wasRecentlyCreated is set by Eloquent's performInsert():
        // true for a fresh INSERT, false for an update of an existing row —
        // exactly what the caller checks, with no manual bookkeeping.
        return $week;
    }

    /**
     * Sync an array of games from the CFBD API into picks_events.
     *
     * @param array<int|string, int> $teamMap cfbd_id => local team id, built
     *   once per sync by the caller.
     * @param \Illuminate\Support\Collection<int|string, PickEvent> $existingEventsByCfbdId
     *   existing events keyed by cfbd_id, preloaded once by the caller; newly
     *   created events are added here so later weeks see them too.
     * @return array{0:int,1:int} [created, updated]
     */
    private function syncGames(array $apiGames, Week $week, array $teamMap, $existingEventsByCfbdId): array
    {
        $created = 0;
        $updated = 0;

        foreach ($apiGames as $apiGame) {
            $isNew = $this->syncGame($apiGame, $week, $teamMap, $existingEventsByCfbdId);

            if ($isNew === true) {
                $created++;
            } elseif ($isNew === false) {
                $updated++;
            }
        }

        return [$created, $updated];
    }

    /**
     * Sync one CFBD game. True when created, false when updated, null when skipped.
     *
     * @param array<string, mixed> $apiGame
     * @param array<int|string, int> $teamMap
     * @param \Illuminate\Support\Collection<int|string, PickEvent> $existingEventsByCfbdId
     */
    private function syncGame(array $apiGame, Week $week, array $teamMap, $existingEventsByCfbdId): ?bool
    {
        $ids = $this->gameTeamIds($apiGame, $teamMap);

        if ($ids === null) {
            return null;
        }

        [$cfbdGameId, $homeTeamId, $awayTeamId] = $ids;

        $startDateRaw = Arr::get($apiGame, 'startDate');
        $startTimeTbd = (bool) Arr::get($apiGame, 'startTimeTBD', false);

        $matchDate = $startDateRaw ? Carbon::parse($startDateRaw) : null;
        $cutoffDate = $this->calculateCutoffDate($matchDate, $startTimeTbd);

        if (! $matchDate || ! $cutoffDate) {
            return null;
        }

        $event = $existingEventsByCfbdId->get($cfbdGameId);

        $isNew = $event === null;

        if ($isNew) {
            $event = new PickEvent();
            $event->cfbd_id = $cfbdGameId;
        }

        $event->week_id = $week->id;
        $event->home_team_id = $homeTeamId;
        $event->away_team_id = $awayTeamId;
        $event->neutral_site = (bool) Arr::get($apiGame, 'neutralSite', false);
        $event->match_date = $matchDate;
        $event->time_tbd = $startTimeTbd;
        $event->cutoff_date = $cutoffDate;

        $this->applyResult($event, $apiGame, $isNew);

        $event->save();

        if ($isNew) {
            // Keep the in-memory map in sync so a cfbd_id that recurs later
            // in the same run resolves to this row instead of re-inserting.
            $existingEventsByCfbdId->put($cfbdGameId, $event);
        }

        return $isNew;
    }

    /**
     * The game's CFBD id and both local team ids, or null when any is missing.
     *
     * @param array<string, mixed> $apiGame
     * @param array<int|string, int> $teamMap
     * @return array{0: mixed, 1: int, 2: int}|null
     */
    private function gameTeamIds(array $apiGame, array $teamMap): ?array
    {
        $cfbdGameId = Arr::get($apiGame, 'id');
        $homeId = Arr::get($apiGame, 'homeId');
        $awayId = Arr::get($apiGame, 'awayId');

        if (! $cfbdGameId || ! $homeId || ! $awayId) {
            return null;
        }

        $homeTeamId = $teamMap[$homeId] ?? null;
        $awayTeamId = $teamMap[$awayId] ?? null;

        // Skip games where either team isn't in our database
        if (! $homeTeamId || ! $awayTeamId) {
            return null;
        }

        return [$cfbdGameId, $homeTeamId, $awayTeamId];
    }

    /**
     * Only update scores/status if the game is completed.
     *
     * @param array<string, mixed> $apiGame
     */
    private function applyResult(PickEvent $event, array $apiGame, bool $isNew): void
    {
        $completed = (bool) Arr::get($apiGame, 'completed', false);
        $homePoints = Arr::get($apiGame, 'homePoints');
        $awayPoints = Arr::get($apiGame, 'awayPoints');

        if ($completed && $homePoints !== null && $awayPoints !== null) {
            $event->home_score = (int) $homePoints;
            $event->away_score = (int) $awayPoints;
            $event->status = PickEvent::STATUS_FINISHED;
            $event->result = $event->calculateResult();
        } elseif ($isNew) {
            $event->status = PickEvent::STATUS_SCHEDULED;
        }
    }

    /**
     * Calculate the cutoff date for picks based on the match date.
     *
     * If the start time is TBD, we use noon on game day as the cutoff
     * since we don't know the exact kickoff time.
     *
     * The admin-configured offset (in minutes) is subtracted from kickoff.
     */
    private function calculateCutoffDate(?Carbon $matchDate, bool $startTimeTbd): ?Carbon
    {
        if (! $matchDate) {
            return null;
        }

        $offsetMinutes = (int) $this->settings->get('ernestdefoe-picks.picks_lock_offset_minutes');

        if ($startTimeTbd) {
            // Use noon UTC on the game date as a safe cutoff when time is TBD
            $cutoff = $matchDate->copy()->startOfDay()->addHours(12);
        } else {
            $cutoff = $matchDate->copy();
        }

        if ($offsetMinutes > 0) {
            $cutoff->subMinutes($offsetMinutes);
        }

        return $cutoff;
    }

    /**
     * Parse an ISO date string to a date-only string (Y-m-d) for week records.
     * Returns null if the input is empty or unparseable.
     */
    private function parseDate(?string $dateString): ?string
    {
        if (! $dateString) {
            return null;
        }

        try {
            return Carbon::parse($dateString)->toDateString();
        } catch (\Throwable $e) {
            return null;
        }
    }
}
