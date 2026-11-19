<?php

namespace Resofire\Picks\Api\Controller;

use Flarum\Http\RequestUtil;
use Illuminate\Support\Arr;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Resofire\Picks\Sync\AdminSync;

/**
 * POST /picks/sync/{task}: queue the teams, logos, schedule or scores sync
 * (the route allows only those) and answer 202 at once. The admin polls
 * SyncStatusController for the result.
 */
class StartSyncController implements RequestHandlerInterface
{
    public function __construct(
        protected AdminSync $sync
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        RequestUtil::getActor($request)->assertCan('picks.manage');

        $task = (string) Arr::get($request->getAttribute('routeParameters'), 'task', '');

        return new JsonResponse($this->sync->start($task), 202);
    }
}
