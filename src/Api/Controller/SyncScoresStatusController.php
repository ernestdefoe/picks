<?php

namespace Resofire\Picks\Api\Controller;

use Flarum\Http\RequestUtil;
use Flarum\Settings\SettingsRepositoryInterface;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Resofire\Picks\Jobs\SyncScoresJob;

/**
 * GET /picks/sync/scores/status
 *
 * Reports the background score-sync state for the admin UI to poll after it
 * POSTs to SyncScoresController. Returns the status (idle|running|done|failed),
 * the last run's result (counts or error message), and the last-sync timestamp.
 */
class SyncScoresStatusController implements RequestHandlerInterface
{
    public function __construct(
        protected SettingsRepositoryInterface $settings
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        RequestUtil::getActor($request)->assertCan('picks.manage');

        $status    = $this->settings->get(SyncScoresJob::STATUS_KEY) ?: 'idle';
        $resultRaw = $this->settings->get(SyncScoresJob::RESULT_KEY);
        $result    = $resultRaw ? json_decode($resultRaw, true) : null;

        return new JsonResponse([
            'status'    => $status,
            'result'    => is_array($result) ? $result : null,
            'last_sync' => $this->settings->get('ernestdefoe-picks.last_scores_sync'),
        ]);
    }
}
