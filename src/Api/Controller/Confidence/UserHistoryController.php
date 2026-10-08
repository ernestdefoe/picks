<?php

namespace Resofire\Picks\Api\Controller\Confidence;

use Flarum\Http\RequestUtil;
use Illuminate\Support\Arr;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Resofire\Picks\Confidence\ConfidenceContest;
use Resofire\Picks\Confidence\ConfidenceScore;
use Resofire\Picks\Confidence\Profile;
use Resofire\Picks\Season;
use Resofire\Picks\Service\CurrentSeasonService;
use Resofire\Picks\Week;

/**
 * GET /picks/confidence/user-history?user_id=X — a member's Confidence record
 * for their profile (Confidence\Profile has the shape).
 *
 * Permission as /picks/user-history: picks.view for your own, and
 * picks.viewHistory for anybody else's.
 *
 * Four queries however long the member has played: their own rows, the player
 * count of each scope they appear in, the seasons and weeks those rows name,
 * and the all-time table (one row per member per season) to rank them in.
 */
class UserHistoryController implements RequestHandlerInterface
{
    public function __construct(
        protected ConfidenceContest $contest,
        protected CurrentSeasonService $currentSeason
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $userId = (int) Arr::get($request->getQueryParams(), 'user_id', 0);

        if (! $userId) {
            return new JsonResponse(['status' => 'error', 'code' => 'user_id_required'], 422);
        }

        $actor->assertCan((int) $actor->id === $userId ? 'picks.view' : 'picks.viewHistory');

        if (! $this->contest->enabled()) {
            return new JsonResponse(['enabled' => false, 'alltime' => null, 'seasons' => []]);
        }

        $rows = ConfidenceScore::query()
            ->where('user_id', $userId)
            ->where('total_picks', '>', 0)
            ->get()
            ->map(fn (ConfidenceScore $s) => [
                'scope' => (string) $s->scope,
                'season_id' => (int) $s->season_id,
                'week_id' => $s->week_id === null ? null : (int) $s->week_id,
                'points' => (int) $s->total_points,
                'picks' => (int) $s->total_picks,
                'correct' => (int) $s->correct_picks,
                'accuracy' => (float) $s->accuracy,
                'diff' => $s->tiebreak_diff,
                'rank' => $s->current_rank,
            ])
            ->all();

        if ($rows === []) {
            return new JsonResponse(['enabled' => true, 'alltime' => null, 'seasons' => []]);
        }

        $players = ConfidenceScore::query()
            ->whereIn('scope', array_column($rows, 'scope'))
            ->where('total_picks', '>', 0)
            ->select('scope')
            ->selectRaw('COUNT(*) as players')
            ->groupBy('scope')
            ->pluck('players', 'scope')
            ->map(fn ($n) => (int) $n)
            ->all();

        $seasons = Season::query()
            ->whereIn('id', array_unique(array_column($rows, 'season_id')))
            ->orderByDesc('year')
            ->get(['id', 'name', 'year'])
            ->map(fn (Season $s) => ['id' => (int) $s->id, 'name' => (string) $s->name, 'year' => (int) $s->year])
            ->all();

        $weeks = Week::query()
            ->whereIn('id', array_filter(array_column($rows, 'week_id')) ?: [0])
            ->get(['id', 'name', 'season_type', 'week_number'])
            ->mapWithKeys(fn (Week $w) => [(int) $w->id => [
                'name' => (string) $w->name,
                'type' => (string) $w->season_type,
                'number' => (int) $w->week_number,
            ]])
            ->all();

        $current = $this->currentSeason->getCurrentWeek();

        return new JsonResponse(['enabled' => true] + Profile::build(
            $userId,
            $this->contest->allTimeLines(),
            $seasons,
            $weeks,
            $rows,
            $players,
            $current?->season_id === null ? null : (int) $current->season_id,
            $current?->id === null ? null : (int) $current->id
        ));
    }
}
