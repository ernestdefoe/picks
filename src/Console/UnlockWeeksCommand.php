<?php

namespace Resofire\Picks\Console;

use Flarum\Console\AbstractCommand;
use Resofire\Picks\Service\SyncScoresService;

/**
 * Opens the next week once the latest open week is complete, when the
 * "Automatically unlock the next week" setting is on.
 *
 * 🚨 Scheduled, because the check used to run only at the instant a sync saw
 * a game turn final. A week completed before the setting was switched on, or
 * one finished only by the 36-hour rule (a postponed or cancelled game the
 * feed never finalises), had no such instant and never opened the next week.
 *
 * Two queries per season with an open week; nothing outbound.
 */
class UnlockWeeksCommand extends AbstractCommand
{
    public function __construct(protected SyncScoresService $syncScoresService)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('picks:unlock-weeks')
            ->setDescription('Open the next week once the latest open week is complete (when auto-unlock is on).');
    }

    protected function fire(): int
    {
        $opened = $this->syncScoresService->unlockDueWeeks();

        $this->info($opened === [] ? 'No week due to open.' : 'Opened week id(s): ' . implode(', ', $opened));

        return 0;
    }
}
