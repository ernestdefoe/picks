<?php

namespace Resofire\Picks\Api\Controller;

use Carbon\Carbon;
use Flarum\Http\RequestUtil;
use Flarum\Settings\SettingsRepositoryInterface;
use Illuminate\Contracts\Queue\Queue;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Resofire\Picks\Jobs\SyncScoresJob;

/**
 * POST /picks/sync/scores
 *
 * Dispatches a background SyncScoresJob and returns 202 Accepted immediately,
 * instead of running the multi-week CFBD fetch loop synchronously in the admin
 * request (which could exceed max_execution_time and leave scores half-synced).
 * The admin UI polls SyncScoresStatusController for completion + result counts.
 */
class SyncScoresController implements RequestHandlerInterface
{
    private const STARTED_KEY = 'ernestdefoe-picks.scores_sync_started';

    /** A running flag older than this is treated as stale (crashed worker). */
    private const STALE_AFTER_MINUTES = 15;

    public function __construct(
        protected Queue $queue,
        protected SettingsRepositoryInterface $settings
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        RequestUtil::getActor($request)->assertCan('picks.manage');

        // Don't stack a second run on top of one already in flight, unless the
        // running flag is stale (a crashed worker left it stuck).
        if ($this->isRunning()) {
            return new JsonResponse(['status' => 'running'], 202);
        }

        $this->settings->set(SyncScoresJob::STATUS_KEY, 'running');
        $this->settings->set(self::STARTED_KEY, Carbon::now()->toIso8601String());
        $this->settings->set(SyncScoresJob::RESULT_KEY, null);

        $this->queue->push(new SyncScoresJob());

        return new JsonResponse(['status' => 'queued'], 202);
    }

    private function isRunning(): bool
    {
        if ($this->settings->get(SyncScoresJob::STATUS_KEY) !== 'running') {
            return false;
        }

        $startedAt = $this->settings->get(self::STARTED_KEY);
        if (! $startedAt) {
            return false;
        }

        try {
            return Carbon::parse($startedAt)->gt(Carbon::now()->subMinutes(self::STALE_AFTER_MINUTES));
        } catch (\Throwable $e) {
            return false;
        }
    }
}
