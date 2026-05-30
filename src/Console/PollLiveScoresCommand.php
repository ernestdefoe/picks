<?php

namespace Resofire\Picks\Console;

use Carbon\Carbon;
use Flarum\Console\AbstractCommand;
use Flarum\Settings\SettingsRepositoryInterface;
use Resofire\Picks\PickEvent;
use Resofire\Picks\Service\SyncScoresService;
use Symfony\Component\Console\Input\InputOption;

class PollLiveScoresCommand extends AbstractCommand
{
    public function __construct(
        protected SyncScoresService $syncScoresService,
        protected SettingsRepositoryInterface $settings
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('picks:poll-scores')
            ->setDescription('Poll ESPN scoreboard for live and completed game scores.')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Run even if polling is disabled or no games are active today.');
    }

    protected function fire(): int
    {
        $force = $this->input->getOption('force');

        // Check admin toggle unless forced
        if (! $force && ! $this->settings->get('ernestdefoe-picks.espn_polling_enabled', false)) {
            $this->info('ESPN score polling is disabled. Enable it in Picks Settings.');
            return 0;
        }

        // Only run if there are games today or in-progress games
        if (! $force && ! $this->hasActiveGamesToday()) {
            $this->info('No active games today. Skipping poll.');
            return 0;
        }

        $this->info('Polling ESPN scoreboard...');

        try {
            $result = $this->syncScoresService->syncFromEspn();
        } catch (\RuntimeException $e) {
            $this->output->writeln('<error>' . $e->getMessage() . '</error>');
            return 1;
        }

        $this->info(
            "Done. Updated: {$result['updated']}, " .
            "Newly finished: {$result['finished']}, " .
            "Skipped: {$result['skipped']}."
        );

        return 0;
    }

    /**
     * Returns true if there are any games scheduled for today or currently in-progress.
     * This prevents unnecessary ESPN API calls on non-game days.
     */
    private function hasActiveGamesToday(): bool
    {
        $now   = Carbon::now();
        $today = $now->toDateString();

        return PickEvent::where(function ($q) use ($today, $now) {
            // Games scheduled for today
            $q->whereDate('match_date', $today)
              ->where('status', PickEvent::STATUS_SCHEDULED);
        })->orWhere(function ($q) {
            // Games currently in-progress
            $q->where('status', 'in_progress');
        })->exists();
    }
}
