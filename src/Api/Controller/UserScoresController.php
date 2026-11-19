<?php

namespace Resofire\Picks\Api\Controller;

use Flarum\Http\RequestUtil;
use Flarum\Locale\TranslatorInterface;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;
use Resofire\Picks\Service\CurrentSeasonService;
use Resofire\Picks\UserScore;

/**
 * GET /picks/user-scores?user_id=X.
 *
 * Returns the user's all-time, current-season, and current-week score blocks
 * (totals, accuracy, rank, and the player count for each scope), used by the
 * profile stat cards.
 *
 * Permission: picks.view for your own scores; picks.viewHistory for anybody
 * else's, the same gate as the history these cards sit beside.
 */
class UserScoresController implements RequestHandlerInterface
{
    public function __construct(
        protected CurrentSeasonService $currentSeason,
        protected LoggerInterface $log,
        protected TranslatorInterface $translator
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);

        $params = $request->getQueryParams();
        $userId = (int) Arr::get($params, 'user_id', 0);

        if (! $userId) {
            return new JsonResponse(['error' => $this->translator->trans('ernestdefoe-picks.api.errors.user_id_required')], 422);
        }

        $actor->assertCan($actor->id === $userId ? 'picks.view' : 'picks.viewHistory');

        try {
            // Current week (most recent season with an unfinished game, then the
            // earliest unfinished week within it). Shared across the picks
            // endpoints via CurrentSeasonService.
            $currentWeek = $this->currentSeason->getCurrentWeek();
            $currentWeekId = $currentWeek?->id;
            $currentSeasonId = $currentWeek?->season_id;
            $currentWeekName = $currentWeek?->name;

            [$alltimeScores, $seasonScores, $weekScores] = $this->scopeScores($currentSeasonId, $currentWeekId);

            [$alltime, $season, $week] = $this->userRows($userId, $currentSeasonId, $currentWeekId);

            return new JsonResponse([
                'current_week_name' => $currentWeekName,
                'alltime' => $this->block($alltime, $alltimeScores),
                'season' => $this->block($season, $seasonScores),
                'week' => $this->block($week, $weekScores),
            ]);
        } catch (\Exception $e) {
            // A query failure is a real error — return 500 so the client (and
            // operator) can tell it apart from the valid "user has no picks
            // yet" case, which is the empty-but-200 response built above.
            $this->log->error('[Picks] UserScores failed: '.$e->getMessage(), ['exception' => $e]);

            return new JsonResponse(['error' => $this->translator->trans('ernestdefoe-picks.api.errors.user_scores_failed')], 500);
        }
    }

    /**
     * The member's own all-time, season and week rows.
     *
     * @return array{0: UserScore|null, 1: UserScore|null, 2: UserScore|null}
     */
    private function userRows(int $userId, ?int $currentSeasonId, ?int $currentWeekId): array
    {
        // ── All-time scores ───────────────────────────────────────────────
        $alltime = UserScore::query()
            ->where('user_id', $userId)
            ->whereNull('week_id')
            ->whereNull('season_id')
            ->first();

        // ── Season scores ─────────────────────────────────────────────────
        $season = null;

        if ($currentSeasonId) {
            $season = UserScore::query()
                ->where('user_id', $userId)
                ->where('season_id', $currentSeasonId)
                ->whereNull('week_id')
                ->first();
        }

        // ── Week scores ───────────────────────────────────────────────────
        $week = null;

        if ($currentWeekId) {
            $week = UserScore::query()
                ->where('user_id', $userId)
                ->where('week_id', $currentWeekId)
                ->first();
        }

        return [$alltime, $season, $week];
    }

    /**
     * Preload each scope's scored rows once (one query per scope) so the
     * rank and the player total are derived in PHP — matching
     * UserHistoryController::rankIn — instead of firing a separate COUNT
     * for the rank and another for the total in every scope.
     *
     * @return array{0: Collection<int, UserScore>, 1: Collection<int, UserScore>, 2: Collection<int, UserScore>}
     */
    private function scopeScores(?int $currentSeasonId, ?int $currentWeekId): array
    {
        $alltimeScores = UserScore::query()
            ->whereNull('week_id')->whereNull('season_id')
            ->where('total_picks', '>', 0)
            ->get(['user_id', 'total_points']);

        $seasonScores = $currentSeasonId
            ? UserScore::query()
                ->where('season_id', $currentSeasonId)->whereNull('week_id')
                ->where('total_picks', '>', 0)
                ->get(['user_id', 'total_points'])
            : collect();

        $weekScores = $currentWeekId
            ? UserScore::query()
                ->where('week_id', $currentWeekId)
                ->where('total_picks', '>', 0)
                ->get(['user_id', 'total_points'])
            : collect();

        return [$alltimeScores, $seasonScores, $weekScores];
    }

    /**
     * One scope's stat block, ranked among that scope's scored rows.
     *
     * @param Collection<int, UserScore> $scores
     * @return array<string, mixed>|null
     */
    private function block(?UserScore $row, Collection $scores): ?array
    {
        if (! $row) {
            return null;
        }

        return [
            'total_picks' => (int) $row->total_picks,
            'correct_picks' => (int) $row->correct_picks,
            'total_points' => (int) $row->total_points,
            'accuracy' => (float) $row->accuracy,
            'rank' => $row->total_picks > 0 ? UserScore::rankIn($scores, $row->total_points) : null,
            'total_players' => $scores->count(),
        ];
    }
}
