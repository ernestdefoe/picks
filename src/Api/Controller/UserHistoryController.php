<?php

namespace Resofire\Picks\Api\Controller;

use Flarum\Http\RequestUtil;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;
use Resofire\Picks\Pick;
use Resofire\Picks\Season;
use Resofire\Picks\Service\CurrentSeasonService;
use Resofire\Picks\UserScore;
use Resofire\Picks\Week;

/**
 * GET /picks/user-history?user_id=X
 *
 * Returns the full season-by-season pick history for a user, including
 * per-week breakdowns within each season. Used by the profile history stack.
 *
 * Permission:
 *   - Viewing own history: picks.view
 *   - Viewing another user's history: picks.viewHistory
 *   - Admins: always allowed (handled by PicksPolicy)
 */
class UserHistoryController implements RequestHandlerInterface
{
    public function __construct(
        protected CurrentSeasonService $currentSeason,
        protected LoggerInterface $log
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor  = RequestUtil::getActor($request);
        $params = $request->getQueryParams();
        $userId = (int) Arr::get($params, 'user_id', 0);

        if (!$userId) {
            return new JsonResponse(['error' => 'user_id required'], 422);
        }

        // Own history requires picks.view; another user's history requires picks.viewHistory
        if ($actor->id === $userId) {
            $actor->assertCan('picks.view');
        } else {
            $actor->assertCan('picks.viewHistory');
        }

        try {
            // ── All seasons, newest first ─────────────────────────────────────
            $seasons = Season::query()
                ->orderByDesc('year')
                ->get();

            // ── Current week (shared via CurrentSeasonService) ───────────────
            $currentWeek     = $this->currentSeason->getCurrentWeek();
            $currentSeasonId = $currentWeek?->season_id;
            $currentWeekId   = $currentWeek?->id;

            // ── Batch-load every scored row once, grouped by scope, so ranks
            // are computed in PHP instead of firing a COUNT query per week /
            // per season (previously 30+ rank queries for a multi-season user).
            $alltimeScores = UserScore::query()
                ->whereNull('week_id')->whereNull('season_id')
                ->where('total_picks', '>', 0)
                ->get(['user_id', 'total_points']);

            $seasonScoresBySeason = UserScore::query()
                ->whereNull('week_id')->whereNotNull('season_id')
                ->where('total_picks', '>', 0)
                ->get(['season_id', 'user_id', 'total_points'])
                ->groupBy('season_id');

            $weekScoresByWeek = UserScore::query()
                ->whereNotNull('week_id')
                ->where('total_picks', '>', 0)
                ->get(['week_id', 'user_id', 'total_points'])
                ->groupBy('week_id');

            return new JsonResponse([
                'alltime' => $this->buildAlltimeBlock($userId, $alltimeScores),
                'seasons' => $this->buildSeasonsBlock(
                    $userId,
                    $seasons,
                    $seasonScoresBySeason,
                    $weekScoresByWeek,
                    $currentSeasonId,
                    $currentWeekId
                ),
            ]);

        } catch (\Exception $e) {
            $this->log->error('[Picks] UserHistory failed: ' . $e->getMessage(), ['exception' => $e]);

            return new JsonResponse(['error' => 'Failed to load history.'], 500);
        }
    }

    /**
     * All-time stats block: totals, rank, longest streak and best week.
     * Returns null when the user has no all-time score row.
     */
    private function buildAlltimeBlock(int $userId, Collection $alltimeScores): ?array
    {
        $alltimeRow = UserScore::query()
            ->where('user_id', $userId)
            ->whereNull('week_id')
            ->whereNull('season_id')
            ->first();

        if (! $alltimeRow) {
            return null;
        }

        $alltimeRank         = null;
        $alltimeTotalPlayers = 0;
        if ($alltimeRow->total_picks > 0) {
            $alltimeRank         = $this->rankIn($alltimeScores, $alltimeRow->total_points);
            $alltimeTotalPlayers = $alltimeScores->count();
        }

        return [
            'total_picks'    => (int) $alltimeRow->total_picks,
            'correct_picks'  => (int) $alltimeRow->correct_picks,
            'total_points'   => (int) $alltimeRow->total_points,
            'accuracy'       => (float) $alltimeRow->accuracy,
            'rank'           => $alltimeRank,
            'total_players'  => $alltimeTotalPlayers,
            'longest_streak' => $this->buildStreakStat($userId),
            'best_week'      => $this->buildBestWeek($userId),
        ];
    }

    /** The user's single best week (by accuracy then points), or null. */
    private function buildBestWeek(int $userId): ?array
    {
        $bestWeekRow = UserScore::query()
            ->join('picks_weeks', 'picks_user_scores.week_id', '=', 'picks_weeks.id')
            ->join('picks_seasons', 'picks_weeks.season_id', '=', 'picks_seasons.id')
            ->where('picks_user_scores.user_id', $userId)
            ->whereNotNull('picks_user_scores.week_id')
            ->where('picks_user_scores.total_picks', '>', 0)
            ->orderByDesc('picks_user_scores.accuracy')
            ->orderByDesc('picks_user_scores.total_points')
            ->select([
                'picks_user_scores.total_picks',
                'picks_user_scores.correct_picks',
                'picks_user_scores.total_points',
                'picks_user_scores.accuracy',
                'picks_weeks.name as week_name',
                'picks_seasons.year as season_year',
            ])
            ->first();

        if (! $bestWeekRow) {
            return null;
        }

        return [
            'week_name'     => $bestWeekRow->week_name,
            'season_year'   => (int) $bestWeekRow->season_year,
            'accuracy'      => (float) $bestWeekRow->accuracy,
            'correct_picks' => (int) $bestWeekRow->correct_picks,
            'total_picks'   => (int) $bestWeekRow->total_picks,
            'total_points'  => (int) $bestWeekRow->total_points,
        ];
    }

    /**
     * Per-season blocks with their week breakdowns. The user's season-level
     * scores are preloaded in one query (keyed by season_id) instead of a
     * separate first() per season.
     */
    private function buildSeasonsBlock(
        int $userId,
        Collection $seasons,
        Collection $seasonScoresBySeason,
        Collection $weekScoresByWeek,
        ?int $currentSeasonId,
        ?int $currentWeekId
    ): array {
        // Preload this user's season-level scores (one query, not one per season).
        $userSeasonScores = UserScore::query()
            ->where('user_id', $userId)
            ->whereNull('week_id')
            ->whereNotNull('season_id')
            ->get()
            ->keyBy('season_id');

        $seasonsData = [];

        foreach ($seasons as $season) {
            $seasonScore = $userSeasonScores->get($season->id);

            // Season rank — from the pre-loaded collection (no per-season query)
            $seasonRank         = null;
            $seasonTotalPlayers = 0;
            $seasonScopeScores  = $seasonScoresBySeason->get($season->id, collect());

            if ($seasonScore && $seasonScore->total_picks > 0) {
                $seasonRank         = $this->rankIn($seasonScopeScores, $seasonScore->total_points);
                $seasonTotalPlayers = $seasonScopeScores->count();
            }

            // All weeks in this season — left join user scores so every week
            // appears regardless of whether the user has picks yet.
            $weekScores = Week::query()
                ->leftJoin('picks_user_scores', function ($join) use ($userId) {
                    $join->on('picks_user_scores.week_id', '=', 'picks_weeks.id')
                         ->where('picks_user_scores.user_id', '=', $userId);
                })
                ->where('picks_weeks.season_id', $season->id)
                ->orderByRaw("CASE picks_weeks.season_type WHEN 'regular' THEN 0 ELSE 1 END")
                ->orderByDesc('picks_weeks.week_number')
                ->selectRaw(
                    'picks_weeks.id as week_id, picks_weeks.name as week_name, picks_weeks.week_number, '
                    . 'COALESCE(picks_user_scores.total_picks, 0) as total_picks, '
                    . 'COALESCE(picks_user_scores.correct_picks, 0) as correct_picks, '
                    . 'COALESCE(picks_user_scores.total_points, 0) as total_points, '
                    . 'COALESCE(picks_user_scores.accuracy, 0.00) as accuracy'
                )
                ->get();

            $weeksData = [];
            foreach ($weekScores as $ws) {
                // Week rank — from the pre-loaded collection (no per-week query)
                $weekRank = null;
                if ($ws->total_picks > 0) {
                    $weekRank = $this->rankIn(
                        $weekScoresByWeek->get($ws->week_id, collect()),
                        $ws->total_points
                    );
                }

                $weeksData[] = [
                    'week_id'       => (int) $ws->week_id,
                    'week_name'     => $ws->week_name,
                    'week_number'   => (int) $ws->week_number,
                    'is_current'    => ((int) $ws->week_id === (int) $currentWeekId),
                    'total_picks'   => (int) $ws->total_picks,
                    'correct_picks' => (int) $ws->correct_picks,
                    'total_points'  => (int) $ws->total_points,
                    'accuracy'      => (float) $ws->accuracy,
                    'rank'          => $weekRank,
                ];
            }

            $seasonsData[] = [
                'season_id'    => (int) $season->id,
                'name'         => $season->name,
                'year'         => (int) $season->year,
                'is_current'   => ((int) $season->id === (int) $currentSeasonId),
                'stats'        => $seasonScore ? [
                    'total_picks'   => (int) $seasonScore->total_picks,
                    'correct_picks' => (int) $seasonScore->correct_picks,
                    'total_points'  => (int) $seasonScore->total_points,
                    'accuracy'      => (float) $seasonScore->accuracy,
                    'rank'          => $seasonRank,
                    'total_players' => $seasonTotalPlayers,
                ] : null,
                'weeks'        => $weeksData,
            ];
        }

        return $seasonsData;
    }

    /**
     * The 1-based rank of $points within a pre-loaded collection of score rows
     * (each having a `total_points`), computed in PHP so callers avoid a COUNT
     * query per week / per season.
     */
    private function rankIn(Collection $scores, $points): int
    {
        return $scores->where('total_points', '>', $points)->count() + 1;
    }

    /**
     * Calculate the longest consecutive correct picks streak for a user.
     * Walks picks ordered by their event's match_date ascending. Plucks only
     * the is_correct flag, so memory stays bounded regardless of pick volume.
     */
    private function buildStreakStat(int $userId): int
    {
        $picks = Pick::query()
            ->join('picks_events', 'picks_picks.event_id', '=', 'picks_events.id')
            ->where('picks_picks.user_id', $userId)
            ->whereNotNull('picks_picks.is_correct')
            ->orderBy('picks_events.match_date')
            ->orderBy('picks_events.id')
            ->pluck('picks_picks.is_correct');

        $longest = 0;
        $current = 0;

        foreach ($picks as $isCorrect) {
            if ($isCorrect) {
                $current++;
                if ($current > $longest) {
                    $longest = $current;
                }
            } else {
                $current = 0;
            }
        }

        return $longest;
    }
}
