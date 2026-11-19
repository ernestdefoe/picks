<?php

namespace Resofire\Picks\Sync;

use Flarum\Queue\AbstractJob;
use Illuminate\Contracts\Container\Container;
use Psr\Log\LoggerInterface;
use Resofire\Picks\Season;
use Resofire\Picks\Service\EspnSyncService;
use Resofire\Picks\Service\ScheduleSyncService;
use Resofire\Picks\Service\SyncScoresService;
use Resofire\Picks\Service\TeamSyncService;
use Resofire\Picks\Team;

/**
 * One admin sync, off the request. The result lands in AdminSync for the
 * admin to poll; a failure is logged and reported there, never rethrown, so a
 * `sync` queue driver running it inline does not turn it into a 500.
 */
class AdminSyncJob extends AbstractJob
{
    /** Logos fetched per pass of the logo sync. */
    private const LOGO_BATCH = 20;

    /** @param array<string, int> $args */
    public function __construct(
        public string $task,
        public array $args = []
    ) {
    }

    public function handle(AdminSync $sync, Container $container, LoggerInterface $log): void
    {
        try {
            $sync->finish($this->task, true, $this->run($container));
        } catch (\Throwable $e) {
            $log->warning('[picks] admin sync "'.$this->task.'" failed: '.$e->getMessage());
            $sync->finish($this->task, false, ['message' => $e->getMessage()]);
        }
    }

    /** @return array<string, mixed> */
    private function run(Container $container): array
    {
        return match (true) {
            $this->task === 'teams' => $container->make(TeamSyncService::class)->sync(downloadLogos: false),
            $this->task === 'logos' => $this->logos($container->make(TeamSyncService::class)),
            $this->task === 'schedule' => $container->make(ScheduleSyncService::class)->sync(),
            $this->task === 'scores' => $container->make(SyncScoresService::class)->sync(),
            $this->task === 'espn' => $container->make(EspnSyncService::class)->sync(Season::query()->findOrFail($this->args['season_id'] ?? 0)),
            str_starts_with($this->task, 'logo-') => ['saved' => $container->make(TeamSyncService::class)->refreshLogos(Team::query()->findOrFail($this->args['team_id'] ?? 0))],
            default => throw new \InvalidArgumentException('Unknown sync: '.$this->task),
        };
    }

    /**
     * Every team still missing a crest, tried once, a batch at a time.
     *
     * 🚨 One pass by id. A team whose download fails keeps no logo, so
     * "fetch the next 20 without one" would return the same failures forever.
     *
     * @return array{saved: int, failed: int, remaining: int}
     */
    private function logos(TeamSyncService $teams): array
    {
        $saved = 0;
        $failed = 0;
        $after = 0;

        do {
            $batch = $teams->syncLogos(self::LOGO_BATCH, $after);
            $saved += $batch['saved'];
            $failed += $batch['failed'];
            $after = $batch['last_id'];
        } while ($batch['tried'] === self::LOGO_BATCH);

        return ['saved' => $saved, 'failed' => $failed, 'remaining' => $batch['remaining']];
    }
}
