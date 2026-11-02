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
use Resofire\Picks\Pick;
use Resofire\Picks\Season;
use Resofire\Picks\Service\CurrentSeasonService;
use Resofire\Picks\UserScore;
use Resofire\Picks\Week;

/**
 * GET /picks/user-history?user_id=X.
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
            $currentWeek = $this->currentSeason->getCurrentWeek();
            $currentSeasonId = $currentWeek?->season_id;
            $currentWeekId = $currentWeek?->id;

            // This user's own season/week score rows — defines the only scopes
            // we need rank data for. Loading these first lets us bound every
            // rank query to the weeks/seasons the user actually played, instead
            // of pulling every player's every-week row into memory.
            $userSeasonScores = UserScore::query()
                ->where('user_id', $userId)
                ->whereNull('week_id')->whereNotNull('season_id')
                ->get()->keyBy('season_id');

            $userWeekScores = UserScore::query()
                ->where('user_id', $userId)
                ->whereNotNull('week_id')
                ->get()->keyBy('week_id');

            // All-time rank set: one row per player (bounded by player count, not
            // by weeks) — needed to rank the user against the whole board.
            $alltimeScores = UserScore::query()
                ->whereNull('week_id')->whereNull('season_id')
                ->where('total_picks', '>', 0)
                ->get(['user_id', 'total_points']);

            // Season/week rank sets, scoped to ONLY the seasons/weeks this user
            // participated in (was: every season and every week for every player).
            $seasonIds = $userSeasonScores->keys()->all();
            $weekIds = $userWeekScores->keys()->all();

            $seasonScoresBySeason = empty($seasonIds) ? collect() : UserScore::query()
                ->whereNull('week_id')->whereIn('season_id', $seasonIds)
                ->where('total_picks', '>', 0)
                ->get(['season_id', 'user_id', 'total_points'])
                ->groupBy('season_id');

            $weekScoresByWeek = empty($weekIds) ? collect() : UserScore::query()
                ->whereIn('week_id', $weekIds)
                ->where('total_picks', '>', 0)
                ->get(['week_id', 'user_id', 'total_points'])
                ->groupBy('week_id');

            return new JsonResponse([
                'alltime' => $this->buildAlltimeBlock($userId, $alltimeScores),
                'seasons' => $this->buildSeasonsBlock(
                    $userId,
                    $seasons,
                    $userSeasonScores,
                    $userWeekScores,
                    $seasonScoresBySeason,
                    $weekScoresByWeek,
                    $currentSeasonId,
                    $currentWeekId
                ),
            ]);
        } catch (\Exception $e) {
            $this->log->error('[Picks] UserHistory failed: '.$e->getMessage(), ['exception' => $e]);

            return new JsonResponse(['error' => $this->translator->trans('ernestdefoe-picks.api.errors.history_failed')], 500);
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

        $alltimeRank = null;
        $alltimeTotalPlayers = 0;
        if ($alltimeRow->total_picks > 0) {
            $alltimeRank = UserScore::rankIn($alltimeScores, $alltimeRow->total_points);
            $alltimeTotalPlayers = $alltimeScores->count();
        }

        return [
            'total_picks' => (int) $alltimeRow->total_picks,
            'correct_picks' => (int) $alltimeRow->correct_picks,
            'total_points' => (int) $alltimeRow->total_points,
            'accuracy' => (float) $alltimeRow->accuracy,
            'rank' => $alltimeRank,
            'total_players' => $alltimeTotalPlayers,
            'longest_streak' => $this->buildStreakStat($userId),
            'best_week' => $this->buildBestWeek($userId),
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
            'week_name' => $bestWeekRow->getAttribute('week_name'),
            'season_year' => (int) $bestWeekRow->getAttribute('season_year'),
            'accuracy' => (float) $bestWeekRow->accuracy,
            'correct_picks' => (int) $bestWeekRow->correct_picks,
            'total_picks' => (int) $bestWeekRow->total_picks,
            'total_points' => (int) $bestWeekRow->total_points,
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
        Collection $userSeasonScores,
        Collection $userWeekScores,
        Collection $seasonScoresBySeason,
        Collection $weekScoresByWeek,
        ?int $currentSeasonId,
        ?int $currentWeekId
    ): array {
        // $userSeasonScores / $userWeekScores are preloaded by the caller.

        $seasonIds = $seasons->pluck('id')->all();

        // Preload all weeks for all seasons in ONE query (grouped by season) so
        // every week still appears in the breakdown, replacing the per-season
        // leftJoin that fired once for every season.
        $weeksBySeason = Week::query()
            ->whereIn('season_id', $seasonIds)
            ->orderByRaw(Week::seasonTypeOrder('regular'))
            ->orderByDesc('week_number')
            ->get()
            ->groupBy('season_id');

        $seasonsData = [];

        foreach ($seasons as $season) {
            $seasonScore = $userSeasonScores->get($season->id);

            // Season rank — from the pre-loaded collection (no per-season query)
            $seasonRank = null;
            $seasonTotalPlayers = 0;
            $seasonScopeScores = $seasonScoresBySeason->get($season->id, collect());

            if ($seasonScore && $seasonScore->total_picks > 0) {
                $seasonRank = UserScore::rankIn($seasonScopeScores, $seasonScore->total_points);
                $seasonTotalPlayers = $seasonScopeScores->count();
            }

            // All weeks in this season (from the preloaded set), each merged
            // with the user's score for that week — or zeros when they haven't
            // picked it yet — so every week still appears.
            $weeksData = [];
            foreach ($weeksBySeason->get($season->id, collect()) as $week) {
                $userWeek = $userWeekScores->get($week->id);
                $totalPicks = (int) ($userWeek->total_picks ?? 0);
                $totalPoints = (int) ($userWeek->total_points ?? 0);

                // Week rank — from the pre-loaded collection (no per-week query)
                $weekRank = null;
                if ($totalPicks > 0) {
                    $weekRank = UserScore::rankIn(
                        $weekScoresByWeek->get($week->id, collect()),
                        $totalPoints
                    );
                }

                $weeksData[] = [
                    'week_id' => (int) $week->id,
                    'week_name' => $week->name,
                    'week_number' => (int) $week->week_number,
                    'is_current' => ((int) $week->id === (int) $currentWeekId),
                    'total_picks' => $totalPicks,
                    'correct_picks' => (int) ($userWeek->correct_picks ?? 0),
                    'total_points' => $totalPoints,
                    // Nothing decided yet (an unplayed week): no accuracy at
                    // all, rather than a 0% that reads as every pick lost.
                    'accuracy' => $totalPicks > 0 ? (float) $userWeek->accuracy : null,
                    'rank' => $weekRank,
                ];
            }

            $seasonsData[] = [
                'season_id' => (int) $season->id,
                'name' => $season->name,
                'year' => (int) $season->year,
                'is_current' => ((int) $season->id === (int) $currentSeasonId),
                'stats' => $seasonScore ? [
                    'total_picks' => (int) $seasonScore->total_picks,
                    'correct_picks' => (int) $seasonScore->correct_picks,
                    'total_points' => (int) $seasonScore->total_points,
                    'accuracy' => (float) $seasonScore->accuracy,
                    'rank' => $seasonRank,
                    'total_players' => $seasonTotalPlayers,
                ] : null,
                'weeks' => $weeksData,
            ];
        }

        return $seasonsData;
    }

    /**
     * The longest run of consecutive correct picks for a user.
     *
     * Walks the user's scored picks in match order, reading only the one
     * is_correct column. A window-function query (ROW_NUMBER() OVER) did this
     * in SQL, but Flarum 2 still supports MySQL 5.7.8, which has no window
     * functions, so the profile history 500'd there. One user's scored picks
     * are bounded (a few hundred a season), so the PHP pass is cheap and runs
     * the same on MySQL, MariaDB, PostgreSQL and SQLite.
     */
    private function buildStreakStat(int $userId): int
    {
        $results = Pick::query()
            ->join('picks_events', 'picks_picks.event_id', '=', 'picks_events.id')
            ->where('picks_picks.user_id', $userId)
            ->whereNotNull('picks_picks.is_correct')
            ->orderBy('picks_events.match_date')
            ->orderBy('picks_events.id')
            ->toBase()
            ->pluck('picks_picks.is_correct');

        $longest = $run = 0;

        foreach ($results as $correct) {
            $run = $correct ? $run + 1 : 0;
            $longest = max($longest, $run);
        }

        return $longest;
    }
}
