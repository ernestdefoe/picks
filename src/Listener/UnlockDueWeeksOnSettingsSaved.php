<?php

namespace Resofire\Picks\Listener;

use Flarum\Settings\Event\Saved;
use Illuminate\Contracts\Container\Container;
use Psr\Log\LoggerInterface;
use Resofire\Picks\Service\SyncScoresService;

/**
 * Turning auto-unlock ON opens whatever is already due, there and then,
 * rather than waiting for the next scheduled run.
 *
 * Saved fires on every admin settings save, so the sync service (and its
 * CFBD/HTTP dependencies) is only built when this extension's switch is on.
 */
class UnlockDueWeeksOnSettingsSaved
{
    public function __construct(
        protected Container $container,
        protected LoggerInterface $log
    ) {
    }

    public function handle(Saved $event): void
    {
        if (empty($event->settings['ernestdefoe-picks.auto_unlock_weeks'])) {
            return;
        }

        try {
            $this->container->make(SyncScoresService::class)->unlockDueWeeks();
        } catch (\Throwable $e) {
            $this->log->warning('[picks] auto-unlock on save failed: '.$e->getMessage());
        }
    }
}
