<?php

namespace Resofire\Picks\Api\Controller;

use Flarum\Http\RequestUtil;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Resofire\Picks\Service\TeamSyncService;

class SyncTeamsController implements RequestHandlerInterface
{
    public function __construct(
        protected TeamSyncService $teamSyncService
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        RequestUtil::getActor($request)->assertCan('picks.manage');

        try {
            // Logos are synced separately via SyncLogosController to avoid timeout.
            $result = $this->teamSyncService->sync(downloadLogos: false);
        } catch (\RuntimeException $e) {
            return new JsonResponse([
                'status' => 'error',
                'message' => $e->getMessage(),
            ], 422);
        }

        return new JsonResponse([
            'status' => 'success',
            'created' => $result['created'],
            'updated' => $result['updated'],
            'logos' => $result['logos'],
            'errors' => $result['errors'],
        ]);
    }
}
