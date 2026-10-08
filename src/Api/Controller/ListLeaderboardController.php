<?php

namespace Resofire\Picks\Api\Controller;

use Flarum\Http\RequestUtil;
use Illuminate\Support\Arr;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Resofire\Picks\UserScore;

class ListLeaderboardController implements RequestHandlerInterface
{
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $actor->assertCan('picks.view');

        $params = $request->getQueryParams();
        $scope = Arr::get($params, 'scope', 'week'); // week | season | alltime
        $weekId = Arr::get($params, 'week_id');
        $seasonId = Arr::get($params, 'season_id');
        $limit = min(50, max(1, (int) Arr::get($params, 'limit', 25)));

        $query = UserScore::with('user')
            ->where('total_picks', '>', 0)
            ->orderByDesc('total_points')
            ->orderByDesc('correct_picks')
            ->limit($limit);

        switch ($scope) {
            case 'week':
                if (! $weekId) {
                    return new JsonResponse(['status' => 'error', 'message' => 'week_id required for week scope.'], 422);
                }
                $query->where('week_id', (int) $weekId);
                break;

            case 'season':
                if (! $seasonId) {
                    return new JsonResponse(['status' => 'error', 'message' => 'season_id required for season scope.'], 422);
                }
                $query->whereNull('week_id')->where('season_id', (int) $seasonId);
                break;

            case 'alltime':
                $query->whereNull('week_id')->whereNull('season_id');
                break;

            default:
                return new JsonResponse(['status' => 'error', 'message' => 'Invalid scope.'], 422);
        }

        $scores = $query->get();

        $myRank = null;
        if (! $actor->isGuest()) {
            $myRank = $scores->search(fn ($s) => $s->user_id === $actor->id);
            $myRank = $myRank !== false ? $myRank + 1 : null;
        }

        $data = $scores->map(function (UserScore $score, int $index) use ($actor) {
            $currentRank = $index + 1;
            $previousRank = $score->previous_rank;
            $movement = $previousRank !== null ? $previousRank - $currentRank : null;

            return [
                'rank' => $currentRank,
                'previous_rank' => $previousRank,
                'movement' => $movement, // positive = moved up, negative = moved down, 0 = no change
                'user_id' => $score->user_id,
                'username' => $score->user?->username,
                'display_name' => $score->user->display_name ?? $score->user?->username,
                'avatar_url' => $score->user?->avatarUrl,
                'total_points' => $score->total_points,
                'total_picks' => $score->total_picks,
                'correct_picks' => $score->correct_picks,
                'accuracy' => $score->accuracy,
                'is_me' => $score->user_id === $actor->id,
            ];
        });

        return new JsonResponse([
            'data' => $data->values()->toArray(),
            'meta' => [
                'scope' => $scope,
                'my_rank' => $myRank,
                'total' => $scores->count(),
            ],
        ]);
    }
}
