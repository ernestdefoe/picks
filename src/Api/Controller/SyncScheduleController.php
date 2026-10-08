<?php

namespace Resofire\Picks\Api\Controller;

use Flarum\Http\RequestUtil;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Resofire\Picks\Service\ScheduleSyncService;

class SyncScheduleController implements RequestHandlerInterface
{
    public function __construct(
        protected ScheduleSyncService $scheduleSyncService
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        RequestUtil::getActor($request)->assertCan('picks.manage');

        try {
            $result = $this->scheduleSyncService->sync();
        } catch (\RuntimeException $e) {
            return new JsonResponse([
                'status' => 'error',
                'message' => $e->getMessage(),
            ], 422);
        }

        return new JsonResponse([
            'status' => 'success',
            'weeksCreated' => $result['weeksCreated'],
            'weeksUpdated' => $result['weeksUpdated'],
            'gamesCreated' => $result['gamesCreated'],
            'gamesUpdated' => $result['gamesUpdated'],
        ]);
    }
}
