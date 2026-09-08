<?php

namespace Resofire\Picks\Console;

use Illuminate\Console\Command;
use Resofire\Picks\Season;
use Resofire\Picks\Service\EspnSyncService;
use Resofire\Picks\Service\Leagues\Leagues;

/**
 * Fixtures and scores for every season that is not college football.
 *
 * 🚨 A separate command from the college schedule sync, for the same reason
 * there is a separate service: CollegeFootballData is asked for a year and a
 * week and answers a calendar; ESPN is asked for a league and answers a
 * scoreboard. Folding both into one command would mean one set of flags that
 * is wrong for whichever feed it was not written for.
 *
 * 🚨 It syncs SEASONS, not leagues. Which competitions a board follows is a
 * fact about its data — a row somebody created — rather than a setting, so a
 * board following nothing but college football runs this and does nothing,
 * quietly, which is correct.
 */
class SyncEspnCommand extends Command
{
    protected $signature = 'picks:sync-espn {--season= : Only this season id}';

    protected $description = 'Sync fixtures and scores for seasons on ESPN-backed leagues.';

    public function handle(EspnSyncService $sync, Leagues $leagues): int
    {
        $query = Season::query();

        if ($this->option('season') !== null) {
            $query->where('id', (int) $this->option('season'));
        }

        $seasons = $query->get()->filter(
            fn (Season $season) => $leagues->get($season->league)->provider === 'espn'
        );

        if ($seasons->isEmpty()) {
            $this->line('No seasons on an ESPN-backed league.');

            return self::SUCCESS;
        }

        foreach ($seasons as $season) {
            $league = $leagues->get($season->league);

            try {
                $result = $sync->sync($season);
            } catch (\Throwable $e) {
                /*
                 * 🚨 One season's failure does not end the run. A board
                 * following four leagues should not lose the other three
                 * because ESPN was briefly unhappy about one of them.
                 */
                $this->error($season->name . ' (' . $league->name . '): ' . $e->getMessage());

                continue;
            }

            $this->line(sprintf(
                '%s (%s): %d created, %d updated, %d skipped, %d weeks, %d teams',
                $season->name,
                $league->name,
                $result['created'],
                $result['updated'],
                $result['skipped'],
                $result['weeks'],
                $result['teams'],
            ));
        }

        return self::SUCCESS;
    }
}
