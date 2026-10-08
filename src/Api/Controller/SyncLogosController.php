<?php

namespace Resofire\Picks\Api\Controller;

use Flarum\Http\RequestUtil;
use Illuminate\Support\Arr;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Resofire\Picks\Service\TeamSyncService;

class SyncLogosController implements RequestHandlerInterface
{
    public function __construct(
        protected TeamSyncService $teamSyncService
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        RequestUtil::getActor($request)->assertCan('picks.manage');

        // Allow the batch size to be overridden via request body, default 20.
        $body = $request->getParsedBody() ?? [];
        $batchSize = (int) Arr::get($body, 'batchSize', 20);
        $batchSize = max(1, min(50, $batchSize)); // clamp between 1 and 50

        try {
            $result = $this->teamSyncService->syncLogos($batchSize);
        } catch (\RuntimeException $e) {
            return new JsonResponse([
                'status' => 'error',
                'message' => $e->getMessage(),
            ], 422);
        }

        return new JsonResponse([
            'status' => 'success',
            'saved' => $result['saved'],
            'failed' => $result['failed'],
            'remaining' => $result['remaining'],
        ]);
    }
}
