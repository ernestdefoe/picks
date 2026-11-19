<?php

namespace Resofire\Picks\Sync;

use Carbon\Carbon;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Queue\Queue;

/**
 * The admin's sync buttons, run on the queue.
 *
 * 🚨 Every one of them talks to CollegeFootballData or ESPN, and a schedule or
 * a season of logos is minutes of outbound calls. Run inside the admin's
 * request that held a PHP worker for all of it and died at max_execution_time
 * half done. The button queues a job and the admin polls for the result.
 *
 * With Flarum's default `sync` queue driver the job still runs inside the
 * dispatching request; the status is written either way, so the admin's
 * first poll already has the answer.
 */
class AdminSync
{
    /** A run older than this is treated as dead (a crashed worker). */
    private const STALE_AFTER_MINUTES = 15;

    /** How long a finished run's result stays readable. */
    private const KEEP_SECONDS = 86400;

    public function __construct(
        protected Queue $queue,
        protected CacheRepository $cache
    ) {
    }

    /**
     * Queue a sync unless the same one is already running.
     *
     * @param array<string, int> $args
     * @return array{status: string}
     */
    public function start(string $task, array $args = []): array
    {
        if ($this->running($task)) {
            return ['status' => 'running'];
        }

        $this->put($task, ['status' => 'running', 'result' => null, 'started_at' => Carbon::now()->toIso8601String()]);
        $this->queue->push(new AdminSyncJob($task, $args));

        return ['status' => 'queued'];
    }

    /** @return array{status: string, result: array<string, mixed>|null} */
    public function status(string $task): array
    {
        $state = $this->cache->get(self::key($task));

        return [
            'status' => is_array($state) ? (string) $state['status'] : 'idle',
            'result' => is_array($state) ? $state['result'] : null,
        ];
    }

    /** @param array<string, mixed> $result */
    public function finish(string $task, bool $ok, array $result): void
    {
        $this->put($task, ['status' => $ok ? 'done' : 'failed', 'result' => $result, 'started_at' => null]);
    }

    private function running(string $task): bool
    {
        $state = $this->cache->get(self::key($task));

        return is_array($state)
            && $state['status'] === 'running'
            && $state['started_at'] !== null
            && Carbon::parse($state['started_at'])->gt(Carbon::now()->subMinutes(self::STALE_AFTER_MINUTES));
    }

    /** @param array<string, mixed> $state */
    private function put(string $task, array $state): void
    {
        $this->cache->put(self::key($task), $state, self::KEEP_SECONDS);
    }

    private static function key(string $task): string
    {
        return 'picks.admin_sync.'.$task;
    }
}
