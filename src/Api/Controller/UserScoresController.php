<?php

namespace Resofire\Picks\Api\Controller;

use Flarum\Http\RequestUtil;
use Illuminate\Support\Arr;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;
use Resofire\Picks\Service\CurrentSeasonService;
use Resofire\Picks\UserScore;

/**
 * GET /picks/user-scores?user_id=X
 *
 * Returns the user's all-time, current-season, and current-week score blocks
 * (totals, accuracy, rank, and the player count for each scope), used by the
 * profile stat cards.
 *
 * Permission: picks.view (same gate as every other picks endpoint).
 */
class UserScoresController implements RequestHandlerInterface
{
    public function __construct(
        protected CurrentSeasonService $currentSeason,
        protected LoggerInterface $log
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $actor->assertCan('picks.view');

        $params = $request->getQueryParams();
        $userId = (int) Arr::get($params, 'user_id', 0);

        if (!$userId) {
            return new JsonResponse(['error' => 'user_id required'], 422);
        }

        try {
            // Current week (most recent season with an unfinished game, then the
            // earliest unfinished week within it). Shared across the picks
            // endpoints via CurrentSeasonService.
            $currentWeek     = $this->currentSeason->getCurrentWeek();
            $currentWeekId   = $currentWeek?->id;
            $currentSeasonId = $currentWeek?->season_id;
            $currentWeekName = $currentWeek?->name;

            // ── All-time scores ───────────────────────────────────────────────
            $alltime = UserScore::query()
                ->where('user_id', $userId)
                ->whereNull('week_id')
                ->whereNull('season_id')
                ->first();

            $alltimeRank = null;
            if ($alltime && $alltime->total_picks > 0) {
                $above = UserScore::query()
                    ->whereNull('week_id')
                    ->whereNull('season_id')
                    ->where('total_picks', '>', 0)
                    ->where('total_points', '>', $alltime->total_points)
                    ->count();
                $alltimeRank = $above + 1;
            }

            $totalAlltime = UserScore::query()
                ->whereNull('week_id')->whereNull('season_id')
                ->where('total_picks', '>', 0)->count();

            // ── Season scores ─────────────────────────────────────────────────
            $season     = null;
            $seasonRank = null;
            $totalSeason = 0;

            if ($currentSeasonId) {
                $season = UserScore::query()
                    ->where('user_id', $userId)
                    ->where('season_id', $currentSeasonId)
                    ->whereNull('week_id')
                    ->first();

                if ($season && $season->total_picks > 0) {
                    $above = UserScore::query()
                        ->where('season_id', $currentSeasonId)
                        ->whereNull('week_id')
                        ->where('total_picks', '>', 0)
                        ->where('total_points', '>', $season->total_points)
                        ->count();
                    $seasonRank = $above + 1;
                }

                $totalSeason = UserScore::query()
                    ->where('season_id', $currentSeasonId)->whereNull('week_id')
                    ->where('total_picks', '>', 0)->count();
            }

            // ── Week scores ───────────────────────────────────────────────────
            $week      = null;
            $weekRank  = null;
            $totalWeek = 0;

            if ($currentWeekId) {
                $week = UserScore::query()
                    ->where('user_id', $userId)
                    ->where('week_id', $currentWeekId)
                    ->first();

                if ($week && $week->total_picks > 0) {
                    $above = UserScore::query()
                        ->where('week_id', $currentWeekId)
                        ->where('total_picks', '>', 0)
                        ->where('total_points', '>', $week->total_points)
                        ->count();
                    $weekRank = $above + 1;
                }

                $totalWeek = UserScore::query()
                    ->where('week_id', $currentWeekId)
                    ->where('total_picks', '>', 0)->count();
            }

            return new JsonResponse([
                'current_week_name' => $currentWeekName,
                'alltime' => $alltime ? [
                    'total_picks'   => (int) $alltime->total_picks,
                    'correct_picks' => (int) $alltime->correct_picks,
                    'total_points'  => (int) $alltime->total_points,
                    'accuracy'      => (float) $alltime->accuracy,
                    'rank'          => $alltimeRank,
                    'total_players' => $totalAlltime,
                ] : null,
                'season' => $season ? [
                    'total_picks'   => (int) $season->total_picks,
                    'correct_picks' => (int) $season->correct_picks,
                    'total_points'  => (int) $season->total_points,
                    'accuracy'      => (float) $season->accuracy,
                    'rank'          => $seasonRank,
                    'total_players' => $totalSeason,
                ] : null,
                'week' => $week ? [
                    'total_picks'   => (int) $week->total_picks,
                    'correct_picks' => (int) $week->correct_picks,
                    'total_points'  => (int) $week->total_points,
                    'accuracy'      => (float) $week->accuracy,
                    'rank'          => $weekRank,
                    'total_players' => $totalWeek,
                ] : null,
            ]);

        } catch (\Exception $e) {
            // A query failure is a real error — return 500 so the client (and
            // operator) can tell it apart from the valid "user has no picks
            // yet" case, which is the empty-but-200 response built above.
            $this->log->error('[Picks] UserScores failed: ' . $e->getMessage(), ['exception' => $e]);

            return new JsonResponse(['error' => 'Failed to load user scores.'], 500);
        }
    }
}
