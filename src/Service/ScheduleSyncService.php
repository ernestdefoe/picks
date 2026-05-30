<?php

namespace Resofire\Picks\Service;

use Carbon\Carbon;
use Flarum\Settings\SettingsRepositoryInterface;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Resofire\Picks\PickEvent;
use Resofire\Picks\Season;
use Resofire\Picks\Team;
use Resofire\Picks\Week;

class ScheduleSyncService
{
    public function __construct(
        protected CfbdService $cfbd,
        protected SettingsRepositoryInterface $settings
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
        $year = (int) $this->settings->get('ernestdefoe-picks.season_year', (int) date('Y'));

        $syncRegular    = (bool) $this->settings->get('ernestdefoe-picks.sync_regular_season', true);
        $syncPostseason = (bool) $this->settings->get('ernestdefoe-picks.sync_postseason', true);

        $season = $this->syncSeason($year);

        $weeksCreated = 0;
        $weeksUpdated = 0;
        $gamesCreated = 0;
        $gamesUpdated = 0;

        // ------------------------------------------------------------------
        // Regular season
        // ------------------------------------------------------------------
        if ($syncRegular) {
            $calendarWeeks = $this->cfbd->fetchCalendar($year);

            // Filter to regular season only from the calendar
            $regularWeeks = array_filter($calendarWeeks, function (array $w) {
                return strtolower($w['seasonType'] ?? '') === 'regular';
            });

            foreach ($regularWeeks as $calWeek) {
                $weekNumber = (int) $calWeek['week'];
                $startDate  = $this->parseDate($calWeek['startDate'] ?? null);
                $endDate    = $this->parseDate($calWeek['endDate'] ?? null);

                $week = $this->syncWeek(
                    season:     $season,
                    weekNumber: $weekNumber,
                    seasonType: 'regular',
                    name:       'Week ' . $weekNumber,
                    startDate:  $startDate,
                    endDate:    $endDate
                );

                if ($week->wasRecentlyCreated) {
                    $weeksCreated++;
                } else {
                    $weeksUpdated++;
                }

                // Fetch and sync games for this week
                $apiGames = $this->cfbd->fetchGames($year, 'regular', $weekNumber);
                [$gc, $gu] = $this->syncGames($apiGames, $week);
                $gamesCreated += $gc;
                $gamesUpdated += $gu;
            }
        }

        // ------------------------------------------------------------------
        // Postseason — all games in one "Bowl Season" week
        // ------------------------------------------------------------------
        if ($syncPostseason) {
            $apiGames = $this->cfbd->fetchGames($year, 'postseason');

            if (! empty($apiGames)) {
                // Determine date range from actual game dates
                $dates = array_filter(array_map(
                    fn ($g) => $this->parseDate($g['startDate'] ?? null),
                    $apiGames
                ));

                $startDate = ! empty($dates) ? min($dates) : null;
                $endDate   = ! empty($dates) ? max($dates) : null;

                $week = $this->syncWeek(
                    season:     $season,
                    weekNumber: 1,
                    seasonType: 'postseason',
                    name:       'Bowl Season',
                    startDate:  $startDate,
                    endDate:    $endDate
                );

                if ($week->wasRecentlyCreated) {
                    $weeksCreated++;
                } else {
                    $weeksUpdated++;
                }

                [$gc, $gu] = $this->syncGames($apiGames, $week);
                $gamesCreated += $gc;
                $gamesUpdated += $gu;
            }
        }

        $this->settings->set(
            'ernestdefoe-picks.last_schedule_sync',
            Carbon::now()->toIso8601String()
        );

        return compact('weeksCreated', 'weeksUpdated', 'gamesCreated', 'gamesUpdated');
    }

    /**
     * Find or create the season record for a given year.
     */
    private function syncSeason(int $year): Season
    {
        $season = Season::where('year', $year)->first();

        if (! $season) {
            $season       = new Season();
            $season->year = $year;
            $season->name = $year . ' Season';
            $season->slug = Str::slug($year . '-season');
        }

        // Always update dates from the calendar if we have them
        $season->save();

        return $season;
    }

    /**
     * Find or create a week record, updating its dates if it already exists.
     */
    private function syncWeek(
        Season  $season,
        int     $weekNumber,
        string  $seasonType,
        string  $name,
        ?string $startDate,
        ?string $endDate
    ): Week {
        $week = Week::where('season_id', $season->id)
            ->where('week_number', $weekNumber)
            ->where('season_type', $seasonType)
            ->first();

        if (! $week) {
            $week                  = new Week();
            $week->season_id       = $season->id;
            $week->week_number     = $weekNumber;
            $week->season_type     = $seasonType;
            $week->name            = $name;
            $week->wasRecentlyCreated = true;
        } else {
            $week->wasRecentlyCreated = false;
        }

        $week->start_date = $startDate;
        $week->end_date   = $endDate;
        $week->save();

        return $week;
    }

    /**
     * Sync an array of games from the CFBD API into picks_events.
     * Returns [created, updated] counts.
     */
    private function syncGames(array $apiGames, Week $week): array
    {
        $created = 0;
        $updated = 0;

        // Build a lookup map of cfbd_id => local team id for fast matching
        $teamMap = Team::whereNotNull('cfbd_id')
            ->pluck('id', 'cfbd_id')
            ->all();

        foreach ($apiGames as $apiGame) {
            $cfbdGameId = Arr::get($apiGame, 'id');
            $homeId     = Arr::get($apiGame, 'homeId');
            $awayId     = Arr::get($apiGame, 'awayId');

            if (! $cfbdGameId || ! $homeId || ! $awayId) {
                continue;
            }

            $homeTeamId = $teamMap[$homeId] ?? null;
            $awayTeamId = $teamMap[$awayId] ?? null;

            // Skip games where either team isn't in our database
            if (! $homeTeamId || ! $awayTeamId) {
                continue;
            }

            $startDateRaw  = Arr::get($apiGame, 'startDate');
            $startTimeTbd  = (bool) Arr::get($apiGame, 'startTimeTBD', false);
            $neutralSite   = (bool) Arr::get($apiGame, 'neutralSite', false);
            $completed     = (bool) Arr::get($apiGame, 'completed', false);
            $homePoints    = Arr::get($apiGame, 'homePoints');
            $awayPoints    = Arr::get($apiGame, 'awayPoints');

            $matchDate  = $startDateRaw ? Carbon::parse($startDateRaw) : null;
            $cutoffDate = $this->calculateCutoffDate($matchDate, $startTimeTbd);

            if (! $matchDate || ! $cutoffDate) {
                continue;
            }

            $event = PickEvent::where('cfbd_id', $cfbdGameId)->first();

            $isNew = $event === null;

            if ($isNew) {
                $event = new PickEvent();
                $event->cfbd_id = $cfbdGameId;
            }

            $event->week_id      = $week->id;
            $event->home_team_id = $homeTeamId;
            $event->away_team_id = $awayTeamId;
            $event->neutral_site = $neutralSite;
            $event->match_date   = $matchDate;
            $event->cutoff_date  = $cutoffDate;

            // Only update scores/status if the game is completed
            if ($completed && $homePoints !== null && $awayPoints !== null) {
                $event->home_score = (int) $homePoints;
                $event->away_score = (int) $awayPoints;
                $event->status     = PickEvent::STATUS_FINISHED;
                $event->result     = $event->calculateResult();
            } elseif ($isNew) {
                $event->status = PickEvent::STATUS_SCHEDULED;
            }

            $event->save();

            if ($isNew) {
                $created++;
            } else {
                $updated++;
            }
        }

        return [$created, $updated];
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

        $offsetMinutes = (int) $this->settings->get(
            'ernestdefoe-picks.picks_lock_offset_minutes',
            0
        );

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
