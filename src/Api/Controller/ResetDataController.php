<?php

namespace Resofire\Picks\Api\Controller;

use Flarum\Http\RequestUtil;
use Illuminate\Support\Arr;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Resofire\Picks\Pick;
use Resofire\Picks\PickEvent;
use Resofire\Picks\Season;
use Resofire\Picks\Team;
use Resofire\Picks\UserScore;
use Resofire\Picks\Week;

class ResetDataController implements RequestHandlerInterface
{
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        RequestUtil::getActor($request)->assertAdmin();

        $body = $request->getParsedBody() ?? [];
        $scope = Arr::get($body, 'scope');

        if (! in_array($scope, ['schedule', 'all'], true)) {
            return new JsonResponse([
                'status' => 'error',
                'message' => 'Invalid scope. Must be "schedule" or "all".',
            ], 422);
        }

        $counts = [];

        // Both scopes clear the schedule; 'all' clears the teams as well.
        // Delete picks first (FK constraint on events)
        $counts['picks'] = Pick::count();
        Pick::query()->delete();

        // Delete user scores
        $counts['scores'] = UserScore::count();
        UserScore::query()->delete();

        // Delete events (FK on weeks)
        $counts['events'] = PickEvent::count();
        PickEvent::query()->delete();

        // Delete weeks (FK on seasons)
        $counts['weeks'] = Week::count();
        Week::query()->delete();

        // Delete seasons
        $counts['seasons'] = Season::count();
        Season::query()->delete();

        if ($scope === 'all') {
            $counts['teams'] = Team::count();
            Team::query()->delete();
        }

        return new JsonResponse([
            'status' => 'success',
            'scope' => $scope,
            'counts' => $counts,
        ]);
    }
}
