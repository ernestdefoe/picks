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
 * GET /picks/sync/{task}/status: where an admin sync has got to
 * (idle | running | done | failed) and its result, for the admin to poll.
 */
class SyncStatusController implements RequestHandlerInterface
{
    public function __construct(
        protected AdminSync $sync
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        RequestUtil::getActor($request)->assertCan('picks.manage');

        $task = (string) Arr::get($request->getAttribute('routeParameters'), 'task', '');

        return new JsonResponse($this->sync->status($task));
    }
}
