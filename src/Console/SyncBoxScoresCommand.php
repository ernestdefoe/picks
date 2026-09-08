<?php

namespace Resofire\Picks\Console;

use Illuminate\Console\Command;
use Resofire\Picks\Service\BoxScoreService;

/**
 * Fetches box scores for finished games that have none yet.
 *
 * 🚨 Scheduled hourly rather than fired when a game finishes. A box score is
 * published minutes to hours after the final whistle, so fetching at the moment
 * a game settles would usually fetch nothing and never try again. Looking for
 * finished games that still have none self-corrects: a scheduler that was off
 * overnight catches up, and a game the provider never covered stops being asked
 * about after two days rather than for ever.
 */
class SyncBoxScoresCommand extends Command
{
    protected $signature = 'picks:sync-box-scores';

    protected $description = 'Fetch team and player box scores for finished games.';

    public function handle(BoxScoreService $boxScores): int
    {
        $result = $boxScores->sync();

        if ($result['error'] === 'unconfigured') {
            $this->warn('No CFBD API key is set, so there is nothing to fetch with.');

            return self::SUCCESS;
        }

        if ($result['error'] !== '') {
            $this->error($result['error']);
            $this->line("Stored {$result['fetched']} before stopping.");

            return self::FAILURE;
        }

        // Silent when there was nothing to do: an hourly cron entry should not
        // fill a mailbox with output about having done nothing.
        if ($result['fetched'] > 0) {
            $this->info("Stored {$result['fetched']} box score(s) across {$result['weeks']} week(s) needing one.");
        }

        return self::SUCCESS;
    }
}
