<?php

namespace Resofire\Picks\Api\Controller;

use Carbon\Carbon;
use Flarum\Http\RequestUtil;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;
use Resofire\Picks\PickEvent;
use Resofire\Picks\Season;
use Resofire\Picks\Service\CurrentSeasonService;
use Resofire\Picks\Week;

/**
 * GET /picks/leaderboard-context
 *
 * Returns the current leaderboard context so the frontend knows whether
 * the season is active, in off-season retention, or fully off-season.
 *
 * Off-season retention: all games are finished but the season ended within
 * 30 days — Week and Season scopes remain visible with final standings.
 *
 * After 30 days: off_season = true, retention_expired = true.
 * Week and Season scopes show an off-season empty state.
 *
 * Permission: picks.view
 */
class LeaderboardContextController implements RequestHandlerInterface
{
    protected const RETENTION_DAYS = 30;

    public function __construct(
        protected CurrentSeasonService $currentSeason,
        protected LoggerInterface $log
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $actor->assertCan('picks.view');

        try {
            // Season is active if there's a current (unfinished) week. Shared
            // with the other picks endpoints via CurrentSeasonService.
            $activeWeek = $this->currentSeason->getCurrentWeek();

            if ($activeWeek) {
                // Season is active — no off-season context needed
                return new JsonResponse([
                    'is_active'          => true,
                    'is_off_season'      => false,
                    'retention_expired'  => false,
                    'days_since_ended'   => null,
                    'last_week_id'       => null,
                    'last_season_id'     => null,
                    'last_season_name'   => null,
                ]);
            }

            // ── No active week — find the most recently completed season ───────
            // The most recent season is the one with the highest year that has
            // at least one finished event.
            $lastSeason = Season::query()
                ->whereHas('weeks.events', fn ($q) => $q->where('status', 'finished'))
                ->orderByDesc('year')
                ->first();

            if (! $lastSeason) {
                // No completed seasons at all — truly no data
                return new JsonResponse([
                    'is_active'          => false,
                    'is_off_season'      => false,
                    'retention_expired'  => false,
                    'days_since_ended'   => null,
                    'last_week_id'       => null,
                    'last_season_id'     => null,
                    'last_season_name'   => null,
                ]);
            }

            // ── When did this season end? ─────────────────────────────────────
            // Use the MAX(updated_at) of finished events in the last season —
            // this is set by ScorePicksJob when the final game is scored.
            $lastFinishedAt = PickEvent::query()
                ->where('status', 'finished')
                ->whereHas('week', fn ($q) => $q->where('season_id', $lastSeason->id))
                ->max('updated_at');

            $daysSinceEnded = $lastFinishedAt
                ? (int) Carbon::parse($lastFinishedAt)->diffInDays(Carbon::now())
                : null;

            $retentionExpired = $daysSinceEnded !== null && $daysSinceEnded > self::RETENTION_DAYS;

            // ── Find the last week of that season ─────────────────────────────
            // Use the week with the highest week_number that has finished events.
            // Postseason takes precedence over regular season in display.
            $lastWeek = Week::query()
                ->where('season_id', $lastSeason->id)
                ->whereHas('events', fn ($q) => $q->where('status', 'finished'))
                ->orderByRaw("CASE season_type WHEN 'postseason' THEN 0 ELSE 1 END")
                ->orderBy('week_number', 'desc')
                ->first();

            return new JsonResponse([
                'is_active'          => false,
                'is_off_season'      => true,
                'retention_expired'  => $retentionExpired,
                'days_since_ended'   => $daysSinceEnded,
                'last_week_id'       => $lastWeek?->id ?? null,
                'last_season_id'     => (int) $lastSeason->id,
                'last_season_name'   => $lastSeason->name,
            ]);

        } catch (\Exception $e) {
            // Unexpected failure → 500 (the detail is logged). The valid
            // "no completed seasons / off-season" states are returned with 200
            // from the normal path above.
            $this->log->error('[Picks] LeaderboardContext failed: ' . $e->getMessage(), ['exception' => $e]);

            return new JsonResponse(['error' => 'Failed to load leaderboard context.'], 500);
        }
    }
}
