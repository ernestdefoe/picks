<?php

namespace Resofire\Picks\Service;

use Carbon\Carbon;
use Flarum\Settings\SettingsRepositoryInterface;
use GuzzleHttp\Client as HttpClient;
use Illuminate\Contracts\Queue\Queue;
use Illuminate\Support\Arr;
use Resofire\Picks\Jobs\ScorePicksJob;
use Resofire\Picks\Pick;
use Resofire\Picks\PickEvent;
use Resofire\Picks\Service\Providers\EspnProvider;
use Resofire\Picks\Week;

class SyncScoresService
{
    /** ESPN scoreboard fetch timeout (seconds). */
    private const ESPN_TIMEOUT = 15;

    public function __construct(
        protected CfbdService $cfbd,
        protected SettingsRepositoryInterface $settings,
        protected Queue $queue,
        protected HttpClient $http
    ) {
    }

    /**
     * Fetch completed game scores from CFBD and update picks_events.
     *
     * For each game that CFBD marks as completed with scores:
     * - Updates home_score, away_score, status, result on the event
     * - Dispatches ScorePicksJob for events that have picks and just became finished
     *
     * Returns a summary of what changed.
     */
    public function sync(): array
    {
        $year           = (int) $this->settings->get('ernestdefoe-picks.season_year', (int) date('Y'));
        $syncRegular    = (bool) $this->settings->get('ernestdefoe-picks.sync_regular_season', true);
        $syncPostseason = (bool) $this->settings->get('ernestdefoe-picks.sync_postseason', true);

        $updated = 0;
        $scored  = 0;
        $skipped = 0;

        // Fetch all weeks for this year so we know which week numbers exist
        $seasonTypes = [];
        if ($syncRegular)    $seasonTypes[] = 'regular';
        if ($syncPostseason) $seasonTypes[] = 'postseason';

        $weekIdsToCheck = [];

        // Preload every known event keyed by cfbd_id and the set of event ids
        // that already have at least one pick, so the per-game inner loop reads
        // from memory instead of firing two queries (a PickEvent lookup + a Pick
        // existence check) for each of a full season's ~900 games.
        $eventsByCfbdId = PickEvent::query()
            ->whereNotNull('cfbd_id')
            ->get()
            ->keyBy('cfbd_id');

        $eventIdsWithPicks = Pick::query()
            ->distinct()
            ->pluck('event_id')
            ->flip();

        foreach ($seasonTypes as $seasonType) {
            $weekNumbers = $this->getWeekNumbers($seasonType);

            foreach ($weekNumbers as $weekNumber) {
                $apiGames = $this->cfbd->fetchGames($year, $seasonType, $weekNumber);

                foreach ($apiGames as $apiGame) {
                    $cfbdId    = Arr::get($apiGame, 'id');
                    $completed = (bool) Arr::get($apiGame, 'completed', false);
                    $homePts   = Arr::get($apiGame, 'homePoints');
                    $awayPts   = Arr::get($apiGame, 'awayPoints');

                    if (! $completed || $homePts === null || $awayPts === null) {
                        $skipped++;
                        continue;
                    }

                    $event = $eventsByCfbdId->get($cfbdId);

                    if (! $event) {
                        $skipped++;
                        continue;
                    }

                    if (
                        $event->status === PickEvent::STATUS_FINISHED
                        && $event->home_score === (int) $homePts
                        && $event->away_score === (int) $awayPts
                    ) {
                        $skipped++;
                        continue;
                    }

                    $wasAlreadyFinished = $event->status === PickEvent::STATUS_FINISHED;

                    $event->home_score = (int) $homePts;
                    $event->away_score = (int) $awayPts;
                    $event->status     = PickEvent::STATUS_FINISHED;
                    $event->result     = $event->calculateResult();
                    $event->save();

                    $updated++;

                    $hasPicks = $eventIdsWithPicks->has($event->id);
                    if ($hasPicks) {
                        $this->queue->push(new ScorePicksJob($event->id));
                        $scored++;
                    }

                    // Collect week IDs that need auto-unlock check after all games processed
                    if (! $wasAlreadyFinished && $event->week_id) {
                        $weekIdsToCheck[$event->week_id] = true;
                    }
                }
            }
        }

        // Check auto-unlock once per affected week, not once per game
        foreach (array_keys($weekIdsToCheck) as $weekId) {
            $this->maybeUnlockNextWeek($weekId);
        }

        $this->settings->set(
            'ernestdefoe-picks.last_scores_sync',
            Carbon::now()->toIso8601String()
        );

        return compact('updated', 'scored', 'skipped');
    }

    /**
     * Sync live and completed scores from the ESPN scoreboard API.
     * No auth required — ESPN's scoreboard is public.
     *
     * Handles three states based on status.type.state:
     * - "pre"  → skip (not started)
     * - "in"   → update scores on event, mark as in_progress, no scoring job
     * - "post" → update scores, mark as finished, dispatch ScorePicksJob if picks exist
     *
     * Returns summary counts.
     */
    public function syncFromEspn(): array
    {
        /*
         * 🚨 groups=80&limit=300, not the bare scoreboard URL.
         *
         * The bare endpoint returns a FEATURED subset — 24 games on the night
         * this was found, out of 86 being played. Every game outside that
         * subset simply never appeared in the payload, so its row was never
         * matched and never updated: the thread sat on "Kickoff" with the game
         * well under way, and nothing logged, because from the sync's point of
         * view there was no such game. Rutgers at Boston College was one.
         *
         * groups=80 is FBS; limit=300 covers a full Saturday.
         */
        $url      = 'https://site.api.espn.com/apis/site/v2/sports/football/college-football/scoreboard'
            .'?groups=80&limit=300';
        $response = $this->fetchJson($url);

        if ($response === null) {
            throw new \RuntimeException('Failed to fetch ESPN scoreboard: '.($this->lastError ?? 'no detail'));
        }

        $events    = $response['events'] ?? [];
        $updated   = 0;
        $finished  = 0;
        $skipped   = 0;

        // Bulk-load every event referenced in this scoreboard payload in ONE
        // query (keyed by cfbd_id) rather than a SELECT per game. This command
        // runs every 5 minutes via the scheduler; on a game Saturday with 30+
        // concurrent games that was 30+ indexed lookups per tick.
        $espnIds = [];
        foreach ($events as $espnEvent) {
            if (isset($espnEvent['id'])) {
                $espnIds[] = (int) $espnEvent['id'];
            }
        }

        $eventsByCfbdId = PickEvent::query()
            ->whereIn('cfbd_id', array_values(array_unique($espnIds)))
            ->get()
            ->keyBy('cfbd_id');

        // Pre-build the set of event ids that already have at least one pick so
        // the per-game "did anyone pick this?" check reads from memory instead
        // of firing a Pick::exists() query for each newly-completed game —
        // matching the CFBD sync path above.
        $eventIdsWithPicks = Pick::query()
            ->whereIn('event_id', $eventsByCfbdId->pluck('id'))
            ->distinct()
            ->pluck('event_id')
            ->flip();

        foreach ($events as $espnEvent) {
            $espnId      = $espnEvent['id'] ?? null;
            $competition = $espnEvent['competitions'][0] ?? null;

            if (! $espnId || ! $competition) {
                $skipped++;
                continue;
            }

            $statusType = $competition['status']['type'] ?? [];
            $state      = $statusType['state'] ?? 'pre';
            $completed  = (bool) ($statusType['completed'] ?? false);

            // Skip games that haven't started
            if ($state === 'pre') {
                $skipped++;
                continue;
            }

            // Match to our event by cfbd_id (ESPN event id = CFBD game id)
            $event = $eventsByCfbdId->get((int) $espnId);

            if (! $event) {
                $skipped++;
                continue;
            }

            // Parse scores from competitors
            $homeScore = null;
            $awayScore = null;

            foreach ($competition['competitors'] ?? [] as $competitor) {
                $side  = $competitor['homeAway'] ?? null;
                $score = isset($competitor['score']) ? (int) $competitor['score'] : null;

                if ($side === 'home') $homeScore = $score;
                if ($side === 'away') $awayScore = $score;
            }

            if ($homeScore === null || $awayScore === null) {
                $skipped++;
                continue;
            }

            $wasFinished = $event->status === PickEvent::STATUS_FINISHED;

            $event->home_score = $homeScore;
            $event->away_score = $awayScore;

            if ($completed) {
                $event->status = PickEvent::STATUS_FINISHED;
                $event->result = $event->calculateResult();
            } else {
                // In progress — update score but don't finalize
                $event->status = 'in_progress';
            }

            /*
             * 🚨 The live clock, from the payload already in hand.
             *
             * These columns exist for the scoreboard strip, and on this site
             * nothing was filling them: the other sync path writes them, but it
             * only runs for an ESPN-backed LEAGUE, and this forum has none — it
             * exits with "No seasons on an ESPN-backed league" and does nothing.
             * So the score arrived and the clock never did, and a thread with a
             * 7-0 game on it still read "Kickoff", which is what period 0 with
             * an empty clock means to the panel.
             *
             * The fields are read exactly as EspnProvider reads them so the two
             * paths cannot drift into disagreeing about the same game, and
             * downAndDistance is CALLED rather than copied — it carries a guard
             * against ESPN's momentary "4th & -1" that is worth having once.
             */
            $statusBlock = (array) ($competition['status'] ?? $espnEvent['status'] ?? []);
            $situation   = (array) ($competition['situation'] ?? []);

            $possession = '';
            $hasBall    = (string) ($situation['possession'] ?? '');

            if ($hasBall !== '') {
                foreach ($competition['competitors'] ?? [] as $competitor) {
                    $teamId = (string) (($competitor['team'] ?? [])['id'] ?? $competitor['id'] ?? '');
                    if ($teamId === $hasBall) {
                        $possession = (string) ($competitor['homeAway'] ?? '');
                    }
                }
            }

            $clock  = trim((string) ($statusBlock['displayClock'] ?? ''));
            $period = (int) ($statusBlock['period'] ?? 0);

            $event->period        = $period;
            $event->clock         = $clock;
            $event->clock_detail  = trim((string) ($statusType['shortDetail'] ?? $statusType['detail'] ?? ''));
            // Stamped here, not defaulted in the database: the panel needs to
            // know how old the clock is before it shows a number that moves
            // every second.
            $event->clock_at      = ($clock !== '' || $period > 0) ? time() : 0;
            $event->possession    = $possession;
            $event->down_distance = EspnProvider::downAndDistance($situation);
            $event->red_zone      = ! empty($situation['isRedZone']);

            $event->save();
            $updated++;

            // Only dispatch scoring job when a game just became finished
            if ($completed && ! $wasFinished) {
                $hasPicks = $eventIdsWithPicks->has($event->id);
                if ($hasPicks) {
                    $this->queue->push(new ScorePicksJob($event->id));
                    $finished++;
                }

                // Auto-unlock next week if enabled
                if ($event->week_id) {
                    $this->maybeUnlockNextWeek($event->week_id);
                }
            }
        }

        $this->settings->set(
            'ernestdefoe-picks.last_scores_sync',
            Carbon::now()->toIso8601String()
        );

        return compact('updated', 'finished', 'skipped');
    }

    /**
     * If auto-unlock is enabled and all games in the given week are finished,
     * open the next sequential week for picking.
     */
    public function maybeUnlockNextWeek(int $weekId): bool
    {
        if (! $this->settings->get('ernestdefoe-picks.auto_unlock_weeks', false)) {
            return false;
        }

        // Check if any games in this week are still unfinished
        $unfinished = PickEvent::where('week_id', $weekId)
            ->where('status', '!=', PickEvent::STATUS_FINISHED)
            ->exists();

        if ($unfinished) {
            return false;
        }

        $currentWeek = Week::find($weekId);
        if (! $currentWeek) {
            return false;
        }

        // Find the next week in the same season by week_number
        $nextWeek = Week::where('season_id', $currentWeek->season_id)
            ->where('week_number', '>', $currentWeek->week_number)
            ->where('season_type', $currentWeek->season_type)
            ->orderBy('week_number')
            ->first();

        // If no next regular week, check postseason
        if (! $nextWeek && $currentWeek->season_type === 'regular') {
            $nextWeek = Week::where('season_id', $currentWeek->season_id)
                ->where('season_type', 'postseason')
                ->orderBy('week_number')
                ->first();
        }

        if (! $nextWeek || $nextWeek->is_open) {
            return false;
        }

        $nextWeek->is_open = true;
        $nextWeek->save();

        return true;
    }

    /**
     * Fetch a URL via the container-managed Guzzle client and return decoded
     * JSON, or null on any transport / non-200 / parse failure. Uses the same
     * 'http_errors' => false convention as CfbdService so the host's proxy/SSL
     * config applies and the call is mockable in tests.
     */
    /** Why the last fetch failed, so the thrown message can say. */
    private ?string $lastError = null;

    private function fetchJson(string $url): ?array
    {
        try {
            /*
             * 🚨 NO User-Agent override. Guzzle's own default is what works.
             *
             * This used to send 'ernestdefoe/picks' and ESPN began answering
             * 403 to it, silently, for two days — every live score on the site
             * froze. Testing a spread of agents against the endpoint: curl/8.5.0
             * and GuzzleHttp/7 both get 200, while 'ernestdefoe/picks', an empty
             * agent, a descriptive bot string and 'Mozilla/5.0' all get 403. So
             * this is not ESPN turning away automated clients — it answers two
             * of the most obviously automated agents there are. It is the custom
             * string itself.
             *
             * Which is why the fix is to send NOTHING and let Guzzle identify
             * itself honestly, rather than to paste in a browser agent. A
             * spoofed browser is both a lie and, here, a 403 anyway.
             */
            $response = $this->http->request('GET', $url, [
                'timeout'     => self::ESPN_TIMEOUT,
                'http_errors' => false,
            ]);
        } catch (\Throwable $e) {
            $this->lastError = $e->getMessage();

            return null;
        }

        if ($response->getStatusCode() !== 200) {
            // 🚨 Keep the status. Returning a bare null turned "ESPN is
            // refusing us" into "Failed to fetch ESPN scoreboard.", which is
            // indistinguishable from the site being down and took a live
            // outage to diagnose.
            $this->lastError = 'HTTP '.$response->getStatusCode().' from '.$url;

            return null;
        }

        $decoded = json_decode((string) $response->getBody(), true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * Get the week numbers to sync for a given season type.
     * For regular season, reads weeks from picks_weeks in DB.
     * For postseason, always uses week 1 (all bowl games are week 1 in CFBD).
     */
    private function getWeekNumbers(string $seasonType): array
    {
        if ($seasonType === 'postseason') {
            return [1];
        }

        return Week::where('season_type', 'regular')
            ->whereHas('season', function ($q) {
                $year = (int) $this->settings->get('ernestdefoe-picks.season_year', (int) date('Y'));
                $q->where('year', $year);
            })
            ->pluck('week_number')
            ->filter()
            ->unique()
            ->sort()
            ->values()
            ->toArray();
    }
}
