<?php

namespace Resofire\Picks\Api\Controller\Confidence;

use Flarum\Http\RequestUtil;
use Illuminate\Support\Arr;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Resofire\Picks\Confidence\ConfidenceContest;

/** GET /picks/confidence/leaderboard?scope=week&week_id= | scope=season&season_id= | scope=alltime */
class LeaderboardController implements RequestHandlerInterface
{
    public function __construct(protected ConfidenceContest $contest)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $actor->assertCan('picks.view');

        $params = $request->getQueryParams();
        $scope  = Arr::get($params, 'scope', 'week');
        $limit  = min(50, max(1, (int) Arr::get($params, 'limit', 25)));

        if ($scope === 'alltime') {
            return new JsonResponse([
                'data' => $this->contest->allTimeStandings($actor, $limit),
                'meta' => ['scope' => $scope],
            ]);
        }

        $key = match ($scope) {
            'week'   => ($id = (int) Arr::get($params, 'week_id')) ? 'w' . $id : null,
            'season' => ($id = (int) Arr::get($params, 'season_id')) ? 's' . $id : null,
            default  => null,
        };

        if ($key === null) {
            return new JsonResponse(['status' => 'error', 'code' => 'bad_scope'], 422);
        }

        return new JsonResponse([
            'data' => $this->contest->standings($key, $actor, $limit),
            'meta' => ['scope' => $scope],
        ]);
    }
}
