<?php

namespace Resofire\Picks\Jobs;

use Flarum\Queue\AbstractJob;
use Flarum\Settings\SettingsRepositoryInterface;
use Resofire\Picks\Service\SyncScoresService;

/**
 * Runs SyncScoresService::sync() off the request thread.
 *
 * A full FBS season is up to 17 CFBD week fetches, each a 30s-timeout HTTP
 * call, so running the loop inline in the admin request risked an 8+ minute
 * request that PHP's max_execution_time would kill mid-sync, leaving scores
 * partially synced. SyncScoresController dispatches this job and returns 202;
 * the admin UI polls SyncScoresStatusController for completion + counts.
 *
 * The run's status and result are persisted to settings so the status endpoint
 * can report progress and the final counts.
 *
 * NOTE: with Flarum's default `sync` queue driver the job still runs inline in
 * the dispatching request. Configure a real queue driver (redis/database) to
 * get the async benefit — either way the status keys below are written.
 */
class SyncScoresJob extends AbstractJob
{
    public const STATUS_KEY = 'ernestdefoe-picks.scores_sync_status';
    public const RESULT_KEY = 'ernestdefoe-picks.scores_sync_result';

    public function handle(SyncScoresService $service, SettingsRepositoryInterface $settings): void
    {
        try {
            $result = $service->sync();

            $settings->set(self::RESULT_KEY, json_encode([
                'status'  => 'success',
                'updated' => $result['updated'],
                'scored'  => $result['scored'],
                'skipped' => $result['skipped'],
            ]));
            $settings->set(self::STATUS_KEY, 'done');
        } catch (\Throwable $e) {
            // Record the failure for the status endpoint instead of rethrowing,
            // so a sync-driver inline run doesn't bubble a 500 out of the
            // dispatching controller.
            $settings->set(self::RESULT_KEY, json_encode([
                'status'  => 'error',
                'message' => $e->getMessage(),
            ]));
            $settings->set(self::STATUS_KEY, 'failed');
        }
    }
}
